<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\AsesorSap;
use App\Models\Bitacora;
use App\Models\Canal;
use App\Models\Configuracion;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use App\Services\ServicioUsuarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Administracion: usuarios, permisos, configuracion y log de registros.
 *
 * Lo que se cuida aqui es quien reparte acceso. Administrar la operacion no es
 * lo mismo que repartir poder: el admin de ventas gestiona a su gente, pero
 * tocar administradores y repartir el visto bueno para SAP es de TI.
 */
class AdministracionTest extends TestCase
{
    use RefreshDatabase;

    private ServicioUsuarios $servicio;

    private Usuario $ti;

    private Usuario $cesar;

    private Usuario $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(ServicioUsuarios::class);

        $this->ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $this->cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        $this->asesor = $this->usuario('adriana.russi@segurex.com', 'ASESOR');

        UsuarioPermiso::create(['usuario_id' => $this->cesar->id, 'permiso' => 'LIBERAR_SAP']);
    }

    private function usuario(string $correo, string $rol, bool $activo = true): Usuario
    {
        return Usuario::create([
            'correo' => $correo, 'nombre' => 'Prueba '.$rol, 'rol' => $rol, 'activo' => $activo,
        ]);
    }

    // ---------- Quien entra al modulo ----------

    public function test_un_asesor_no_entra_a_administracion(): void
    {
        $this->actingAs($this->asesor)->get(route('usuarios'))->assertForbidden();
        $this->actingAs($this->asesor)->get(route('registros'))->assertForbidden();
        $this->actingAs($this->asesor)->get(route('configuracion'))->assertForbidden();
    }

    public function test_un_gerente_de_canal_tampoco(): void
    {
        // Aprobar pedidos no es administrar la plataforma.
        $gerente = $this->usuario('jessika.quintero@segurex.com', 'GERENTE_CANAL');

        $this->actingAs($gerente)->get(route('usuarios'))->assertForbidden();
    }

    public function test_el_admin_de_ventas_y_ti_si_entran(): void
    {
        $this->actingAs($this->cesar)->get(route('usuarios'))->assertOk();
        $this->actingAs($this->ti)->get(route('usuarios'))->assertOk();
    }

    // ---------- Quien toca a quien ----------

    public function test_el_admin_de_ventas_no_modifica_a_otro_administrador(): void
    {
        $marly = $this->usuario('marly.ossa@segurex.com', 'ADMIN_VENTAS');

        $this->assertFalse($this->cesar->can('editar', $marly));
        $this->assertTrue($this->ti->can('editar', $marly));
    }

    public function test_nadie_se_edita_a_si_mismo(): void
    {
        // La forma mas facil de quedarse por fuera sin que nadie pueda arreglarlo.
        $this->assertFalse($this->ti->can('editar', $this->ti));
        $this->assertFalse($this->cesar->can('editar', $this->cesar));
    }

    public function test_el_admin_de_ventas_no_nombra_administradores(): void
    {
        $this->assertFalse($this->cesar->can('cambiarRol', [$this->asesor, Rol::ADMIN_VENTAS]));
        $this->assertFalse($this->cesar->can('cambiarRol', [$this->asesor, Rol::TI]));

        // Lo que si puede: mover a su gente entre asesor y gerente.
        $this->assertTrue($this->cesar->can('cambiarRol', [$this->asesor, Rol::GERENTE_CANAL]));
    }

    public function test_ti_si_nombra_administradores(): void
    {
        $this->assertTrue($this->ti->can('cambiarRol', [$this->asesor, Rol::ADMIN_VENTAS]));
    }

    public function test_cambiar_el_rol_queda_con_el_antes_y_el_despues(): void
    {
        $this->actingAs($this->ti);
        $this->servicio->cambiarRol($this->asesor, Rol::GERENTE_CANAL, $this->ti);

        $registro = Bitacora::where('accion', 'CAMBIAR_ROL')->latest('id')->first();

        $this->assertSame('ASESOR', $registro->detalle['cambios']['rol']['antes']);
        $this->assertSame('GERENTE_CANAL', $registro->detalle['cambios']['rol']['despues']);
    }

    // ---------- El permiso de liberar ----------

    public function test_el_admin_de_ventas_no_reparte_el_permiso_de_liberar(): void
    {
        /*
         * Es la ultima puerta antes de SAP. Si quien la usa pudiera repartirla,
         * podria montarse un atajo para sus propios pedidos.
         */
        $this->assertFalse($this->cesar->can('otorgarLiberar', $this->asesor));
        $this->assertTrue($this->ti->can('otorgarLiberar', $this->asesor));
    }

    public function test_ti_otorga_el_permiso_con_vencimiento(): void
    {
        $this->actingAs($this->ti);

        $this->servicio->otorgarPermiso($this->asesor, 'LIBERAR_SAP', $this->ti, now()->addDays(15)->toDateString());

        $this->assertTrue($this->asesor->fresh()->puedeLiberarASap());
    }

    public function test_un_permiso_vencido_ya_no_sirve(): void
    {
        // El reemplazo temporal caduca solo: nadie tiene que acordarse.
        UsuarioPermiso::create([
            'usuario_id' => $this->asesor->id,
            'permiso' => 'LIBERAR_SAP',
            'vigente_desde' => now()->subDays(30)->toDateString(),
            'vigente_hasta' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($this->asesor->fresh()->puedeLiberarASap());
    }

    public function test_no_se_le_da_el_permiso_a_alguien_inactivo(): void
    {
        $fuera = $this->usuario('exempleado@segurex.com', 'ASESOR', activo: false);

        $this->expectExceptionMessage('Primero activa al usuario');
        $this->servicio->otorgarPermiso($fuera, 'LIBERAR_SAP', $this->ti);
    }

    public function test_revocar_el_permiso_queda_registrado(): void
    {
        $this->actingAs($this->ti);
        $this->servicio->revocarPermiso($this->cesar, 'LIBERAR_SAP', $this->ti);

        $this->assertFalse($this->cesar->fresh()->puedeLiberarASap());
        $this->assertDatabaseHas('bitacora', ['accion' => 'REVOCAR_PERMISO']);
    }

    // ---------- Entrar y salir de la aplicacion ----------

    public function test_nadie_se_desactiva_a_si_mismo(): void
    {
        $this->expectExceptionMessage('No puedes desactivarte a ti mismo');
        $this->servicio->cambiarActivo($this->ti, false, $this->ti);
    }

    public function test_desactivar_no_borra_al_usuario(): void
    {
        // Sus pedidos tienen que seguir diciendo quien los hizo.
        $this->actingAs($this->ti);
        $this->servicio->cambiarActivo($this->asesor, false, $this->ti);

        $this->assertDatabaseHas('usuarios', ['id' => $this->asesor->id, 'activo' => false]);
    }

    public function test_un_usuario_desactivado_no_conserva_sus_permisos(): void
    {
        $this->actingAs($this->ti);
        $this->servicio->cambiarActivo($this->cesar, false, $this->ti);

        $this->assertFalse($this->cesar->fresh()->puedeLiberarASap());
    }

    // ---------- Alta de usuarios ----------

    public function test_solo_se_crean_usuarios_del_dominio_de_la_empresa(): void
    {
        $this->expectExceptionMessage('dominio segurex.com');
        $this->servicio->crear('alguien@gmail.com', 'Alguien', Rol::ASESOR, $this->ti);
    }

    public function test_no_se_repite_un_correo(): void
    {
        $this->expectExceptionMessage('Ya hay un usuario con ese correo');
        $this->servicio->crear('adriana.russi@segurex.com', 'Otra Adriana', Rol::ASESOR, $this->ti);
    }

    // ---------- Carteras y canales ----------

    public function test_cambiar_las_carteras_cambia_lo_que_ve_el_asesor(): void
    {
        $this->actingAs($this->ti);
        $cartera = AsesorSap::create(['codigo_texto' => '42 ADRIANA RUSSI PARRA']);

        $this->servicio->sincronizarCarteras($this->asesor, [$cartera->id], $this->ti);

        $this->assertSame([$cartera->id], $this->asesor->fresh()->idsDeAsesores());
        $this->assertDatabaseHas('bitacora', ['accion' => 'CAMBIAR_CARTERAS']);
    }

    public function test_guardar_lo_mismo_no_ensucia_el_log(): void
    {
        $this->actingAs($this->ti);
        $canal = Canal::create(['nombre' => 'Distribucion']);
        $gerente = $this->usuario('jessika.quintero@segurex.com', 'GERENTE_CANAL');
        $gerente->canales()->attach($canal->id);

        $this->servicio->sincronizarCanales($gerente, [$canal->id], $this->ti);

        $this->assertDatabaseMissing('bitacora', ['accion' => 'CAMBIAR_CANALES']);
    }

    // ---------- Pantallas ----------

    public function test_la_ficha_muestra_el_permiso_de_liberar(): void
    {
        Livewire::actingAs($this->ti)
            ->test('usuario', ['usuario' => $this->cesar])
            ->assertSee('Lo tiene desde el');
    }

    public function test_desde_la_ficha_ti_le_da_el_visto_bueno_a_alguien(): void
    {
        Livewire::actingAs($this->ti)
            ->test('usuario', ['usuario' => $this->asesor])
            ->call('otorgarLiberar');

        $this->assertTrue($this->asesor->fresh()->puedeLiberarASap());
    }

    public function test_desde_la_ficha_el_admin_de_ventas_no_puede(): void
    {
        $otroAsesor = $this->usuario('paola.vargas@segurex.com', 'ASESOR');

        Livewire::actingAs($this->cesar)
            ->test('usuario', ['usuario' => $otroAsesor])
            ->call('otorgarLiberar')
            ->assertForbidden();

        $this->assertFalse($otroAsesor->fresh()->puedeLiberarASap());
    }

    public function test_desde_la_lista_se_da_de_alta_a_alguien(): void
    {
        Livewire::actingAs($this->ti)
            ->test('usuarios')
            ->set('correoNuevo', 'nuevo.asesor@segurex.com')
            ->set('nombreNuevo', 'Nuevo Asesor')
            ->call('crear');

        $this->assertDatabaseHas('usuarios', [
            'correo' => 'nuevo.asesor@segurex.com', 'rol' => 'ASESOR', 'activo' => true,
        ]);
    }

    public function test_el_admin_de_ventas_no_da_de_alta_a_un_administrador(): void
    {
        Livewire::actingAs($this->cesar)
            ->test('usuarios')
            ->set('correoNuevo', 'otro.admin@segurex.com')
            ->set('rolNuevo', 'ADMIN_VENTAS')
            ->call('crear')
            ->assertSee('Solo TI puede crear administradores');

        $this->assertDatabaseMissing('usuarios', ['correo' => 'otro.admin@segurex.com']);
    }

    public function test_un_correo_de_fuera_avisa_en_pantalla(): void
    {
        Livewire::actingAs($this->ti)
            ->test('usuarios')
            ->set('correoNuevo', 'alguien@gmail.com')
            ->call('crear')
            ->assertSee('dominio segurex.com');
    }

    public function test_el_log_de_registros_filtra_por_numero_de_pedido(): void
    {
        Bitacora::create([
            'usuario_id' => $this->asesor->id, 'usuario_correo' => $this->asesor->correo,
            'fecha_hora' => now(), 'accion' => 'APROBAR', 'entidad' => 'pedido', 'entidad_id' => 5007,
        ]);
        Bitacora::create([
            'usuario_id' => $this->asesor->id, 'usuario_correo' => $this->asesor->correo,
            'fecha_hora' => now(), 'accion' => 'APROBAR', 'entidad' => 'pedido', 'entidad_id' => 5008,
        ]);

        Livewire::actingAs($this->ti)
            ->test('registros')
            ->set('buscar', '5007')
            ->assertSee('#5007')
            ->assertDontSee('#5008');
    }

    // ---------- Configuracion ----------

    public function test_la_configuracion_la_cambia_ti_y_no_el_admin_de_ventas(): void
    {
        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        Livewire::actingAs($this->cesar)
            ->test('configuracion')
            ->set('valores.iva_porcentaje', '5')
            ->call('guardar')
            ->assertSee('La configuración la cambia TI');

        $this->assertSame('19', Configuracion::where('clave', 'iva_porcentaje')->value('valor'));
    }

    public function test_un_iva_imposible_no_se_guarda(): void
    {
        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        Livewire::actingAs($this->ti)
            ->test('configuracion')
            ->set('valores.iva_porcentaje', '150')
            ->call('guardar')
            ->assertSee('entre 0 y 100');

        $this->assertSame('19', Configuracion::where('clave', 'iva_porcentaje')->value('valor'));
    }

    public function test_cambiar_el_iva_queda_en_el_log(): void
    {
        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        Livewire::actingAs($this->ti)
            ->test('configuracion')
            ->set('valores.iva_porcentaje', '21')
            ->call('guardar');

        $this->assertSame(21, Configuracion::valor('iva_porcentaje'));
        $this->assertDatabaseHas('bitacora', ['accion' => 'CAMBIAR_CONFIGURACION']);
    }
}
