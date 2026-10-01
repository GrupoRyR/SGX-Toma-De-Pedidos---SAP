<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use App\Models\Bitacora;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Services\ServicioCarteras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Carteras SAP reconocidas por su numero.
 *
 * El numero es fijo en SAP y no se repite; el nombre cambia cuando cambia el
 * asesor. Por eso la llave es el numero y no el texto completo.
 */
class CarterasTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create(['correo' => $correo, 'nombre' => 'Prueba '.$rol, 'rol' => $rol, 'activo' => true]);
    }

    private function admin(): Usuario
    {
        return $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
    }

    private function migracionDelNumero(): object
    {
        return require database_path('migrations/2026_10_01_000100_agregar_numero_a_carteras.php');
    }

    // ---------- Modelo y resolucion por numero ----------

    public function test_al_crear_una_cartera_toma_el_numero_del_texto(): void
    {
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->assertSame(14, $cartera->refresh()->numero);
    }

    public function test_no_se_crea_una_cartera_sin_numero(): void
    {
        // El indice unico admite varios NULL: sin esta defensa podrian quedar
        // carteras sin numero, y repetidas, que es justo lo que el numero evita.
        $error = null;

        try {
            AsesorSap::create(['codigo_texto' => 'SIN NUMERO']);
        } catch (RuntimeException $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Una cartera sin numero no deberia poder crearse.');
        $this->assertStringContainsString('SIN NUMERO', $error->getMessage());
        $this->assertSame(0, AsesorSap::count());
    }

    public function test_saca_el_numero_del_texto_de_sap(): void
    {
        $this->assertSame(14, AsesorSap::numeroDelTexto('14 MONICA RIVERA AREVALO'));
        $this->assertSame(7, AsesorSap::numeroDelTexto('  7 ALGUIEN'));
        $this->assertNull(AsesorSap::numeroDelTexto('MONICA RIVERA AREVALO'));
        $this->assertNull(AsesorSap::numeroDelTexto(''));
    }

    public function test_resolver_usa_la_cartera_del_mismo_numero_sin_renombrarla(): void
    {
        $existente = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $resuelta = AsesorSap::resolverDesdeTexto('14 OTRA PERSONA');

        $this->assertSame($existente->id, $resuelta->id);
        $this->assertFalse($resuelta->wasRecentlyCreated);
        $this->assertSame('14 MONICA RIVERA AREVALO', $resuelta->codigo_texto);
        $this->assertSame(1, AsesorSap::count());
    }

    public function test_resolver_crea_la_cartera_si_el_numero_no_existe(): void
    {
        $nueva = AsesorSap::resolverDesdeTexto('30 ASESOR NUEVO');

        $this->assertTrue($nueva->wasRecentlyCreated);
        $this->assertSame(30, $nueva->numero);
        $this->assertSame('30 ASESOR NUEVO', $nueva->codigo_texto);
        $this->assertSame('ASESOR NUEVO', $nueva->nombre);
        $this->assertTrue($nueva->activo);
    }

    public function test_resolver_rechaza_un_texto_sin_numero(): void
    {
        $this->expectException(RuntimeException::class);

        AsesorSap::resolverDesdeTexto('MONICA RIVERA AREVALO');
    }

    // ---------- Migracion ----------

    public function test_la_migracion_llena_el_numero_de_las_carteras_que_ya_existian(): void
    {
        $migracion = $this->migracionDelNumero();
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('asesores_sap', 'numero'));

        DB::table('asesores_sap')->insert([
            ['codigo_texto' => '14 MONICA RIVERA AREVALO', 'activo' => true],
            ['codigo_texto' => '2 PEPE', 'activo' => true],
        ]);

        $migracion->up();

        $this->assertSame(
            ['14 MONICA RIVERA AREVALO' => 14, '2 PEPE' => 2],
            DB::table('asesores_sap')->pluck('numero', 'codigo_texto')->map(fn ($n) => (int) $n)->all(),
        );
    }

    public function test_la_migracion_se_detiene_si_hay_numeros_repetidos_o_sin_numero(): void
    {
        $migracion = $this->migracionDelNumero();
        $migracion->down();

        DB::table('asesores_sap')->insert([
            ['codigo_texto' => '14 MONICA RIVERA AREVALO', 'activo' => true],
            ['codigo_texto' => '14 OTRA PERSONA', 'activo' => true],
            ['codigo_texto' => 'SIN NUMERO', 'activo' => true],
        ]);

        try {
            $migracion->up();
            $this->fail('La migracion debia detenerse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('14 OTRA PERSONA', $e->getMessage());
            $this->assertStringContainsString('SIN NUMERO', $e->getMessage());
        }

        // Se detuvo antes de tocar la tabla.
        $this->assertFalse(Schema::hasColumn('asesores_sap', 'numero'));
    }

    // ---------- Servicio ----------

    public function test_crea_una_cartera_con_el_nombre_en_mayusculas(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $cartera = app(ServicioCarteras::class)->crear(30, '  ana   maría  pérez ', $admin);

        $this->assertSame(30, $cartera->numero);
        $this->assertSame('30 ANA MARÍA PÉREZ', $cartera->codigo_texto);
        $this->assertSame('ANA MARÍA PÉREZ', $cartera->nombre);
        $this->assertTrue($cartera->activo);
        $this->assertTrue(Bitacora::where('accion', 'CREAR_CARTERA')->where('entidad_id', $cartera->id)->exists());
    }

    public function test_no_crea_una_cartera_con_numero_repetido_o_invalido_o_sin_nombre(): void
    {
        $admin = $this->admin();
        AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);
        $servicio = app(ServicioCarteras::class);

        foreach ([[14, 'OTRA PERSONA'], [0, 'CERO'], [-3, 'NEGATIVA'], [31, '   ']] as [$numero, $nombre]) {
            try {
                $servicio->crear($numero, $nombre, $admin);
                $this->fail("Debia rechazar {$numero} {$nombre}.");
            } catch (RuntimeException) {
            }
        }

        $this->assertSame(1, AsesorSap::count());
    }

    public function test_renombrar_conserva_el_numero_los_clientes_y_lo_que_ve_el_asesor(): void
    {
        $admin = $this->admin();
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO', 'nombre' => 'MONICA RIVERA AREVALO']);
        $cliente = Cliente::create(['codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO', 'asesor_sap_id' => $cartera->id]);
        $asesor = $this->usuario('monica.rivera@segurex.com', 'ASESOR');
        $asesor->asesores()->attach($cartera->id);

        $this->actingAs($admin);
        app(ServicioCarteras::class)->renombrar($cartera, 'Otra Persona', $admin);

        $cartera->refresh();
        $this->assertSame(14, $cartera->numero);
        $this->assertSame('14 OTRA PERSONA', $cartera->codigo_texto);
        $this->assertSame('OTRA PERSONA', $cartera->nombre);
        $this->assertSame($cartera->id, $cliente->refresh()->asesor_sap_id);
        $this->assertSame(1, Cliente::visiblePara($asesor)->count());
        $this->assertTrue(Bitacora::where('accion', 'RENOMBRAR_CARTERA')->exists());

        Livewire::actingAs($asesor)->test('mis-clientes')->assertSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_renombrar_rechaza_un_nombre_vacio(): void
    {
        $admin = $this->admin();
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->expectException(RuntimeException::class);

        app(ServicioCarteras::class)->renombrar($cartera, '  ', $admin);
    }

    public function test_desactivar_no_cambia_quien_ve_los_clientes(): void
    {
        $admin = $this->admin();
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);
        Cliente::create(['codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA', 'asesor_sap_id' => $cartera->id]);
        $asesor = $this->usuario('monica.rivera@segurex.com', 'ASESOR');
        $asesor->asesores()->attach($cartera->id);

        $this->actingAs($admin);
        $servicio = app(ServicioCarteras::class);
        $servicio->cambiarActivo($cartera, false, $admin);

        $this->assertFalse($cartera->refresh()->activo);
        $this->assertSame(1, Cliente::visiblePara($asesor)->count());
        $this->assertTrue(Bitacora::where('accion', 'DESACTIVAR_CARTERA')->exists());

        $servicio->cambiarActivo($cartera, true, $admin);
        $this->assertTrue($cartera->refresh()->activo);
        $this->assertTrue(Bitacora::where('accion', 'ACTIVAR_CARTERA')->exists());
    }

    // ---------- Pantalla ----------

    public function test_un_asesor_o_un_gerente_no_entran(): void
    {
        foreach (['ASESOR', 'GERENTE_CANAL'] as $rol) {
            $quien = $this->usuario(strtolower($rol).'@segurex.com', $rol);

            $this->actingAs($quien)->get(route('maestros-carteras'))->assertForbidden();
        }
    }

    public function test_el_admin_ve_la_lista_con_clientes_y_asesores(): void
    {
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);
        AsesorSap::create(['codigo_texto' => '15 PAOLA ANDREA VARGAS MARIN']);
        Cliente::create(['codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA', 'asesor_sap_id' => $cartera->id]);
        $asesor = Usuario::create(['correo' => 'monica.rivera@segurex.com', 'nombre' => 'Mónica Rivera', 'rol' => 'ASESOR', 'activo' => true]);
        $asesor->asesores()->attach($cartera->id);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('maestros-carteras'))
            ->assertOk()
            ->assertSeeInOrder(['14 MONICA RIVERA AREVALO', 'Mónica Rivera', '15 PAOLA ANDREA VARGAS MARIN'])
            // Solo la que tiene clientes pide confirmar antes de desactivarla.
            ->assertSee('La cartera 14 tiene clientes (1)', false)
            ->assertDontSee('La cartera 15 tiene clientes', false);

        Livewire::actingAs($admin)->test('maestros-carteras')
            ->set('buscar', 'paola')
            ->assertSee('15 PAOLA ANDREA VARGAS MARIN')
            ->assertDontSee('14 MONICA RIVERA AREVALO');
    }

    public function test_el_admin_crea_una_cartera_desde_la_pantalla(): void
    {
        Livewire::actingAs($this->admin())->test('maestros-carteras')
            ->set('agregando', true)
            ->set('nuevo.numero', '30')
            ->set('nuevo.nombre', 'Ana Perez')
            ->call('crear')
            ->assertSet('error', '')
            ->assertSee('30 ANA PEREZ');

        $this->assertSame('30 ANA PEREZ', AsesorSap::where('numero', 30)->value('codigo_texto'));
    }

    public function test_un_numero_repetido_muestra_el_error_en_la_pantalla(): void
    {
        AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        Livewire::actingAs($this->admin())->test('maestros-carteras')
            ->set('agregando', true)
            ->set('nuevo.numero', '14')
            ->set('nuevo.nombre', 'OTRA')
            ->call('crear')
            ->assertSee('Ya existe la cartera 14');

        $this->assertSame(1, AsesorSap::count());
    }

    public function test_el_admin_renombra_y_desactiva_desde_la_pantalla(): void
    {
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        Livewire::actingAs($this->admin())->test('maestros-carteras')
            ->call('editar', $cartera->id)
            ->set('nombreEditado', 'otra persona')
            ->call('guardarNombre')
            ->assertSet('error', '')
            ->assertSee('14 OTRA PERSONA')
            ->call('cambiarActivo', $cartera->id, false)
            ->assertSee('Inactiva');

        $this->assertSame('14 OTRA PERSONA', $cartera->refresh()->codigo_texto);
        $this->assertFalse($cartera->activo);
    }

    public function test_la_pestana_aparece_en_administracion(): void
    {
        $this->actingAs($this->admin())->get(route('maestros-productos'))
            ->assertSee(route('maestros-carteras'), false);
    }
}
