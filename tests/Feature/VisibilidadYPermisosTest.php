<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La regla de seguridad mas importante del sistema: quien ve que.
 *
 * Se prueba sobre la consulta, no sobre la interfaz. Un asesor que manipule la
 * URL o la API tampoco debe poder leer clientes ajenos.
 */
class VisibilidadYPermisosTest extends TestCase
{
    use RefreshDatabase;

    private Canal $distribucion;

    private Canal $residencial;

    private AsesorSap $cartera14;

    private AsesorSap $cartera15;

    protected function setUp(): void
    {
        parent::setUp();

        $this->distribucion = Canal::create(['nombre' => 'Distribucion']);
        $this->residencial = Canal::create(['nombre' => 'Cons. Residencial']);

        $this->cartera14 = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);
        $this->cartera15 = AsesorSap::create(['codigo_texto' => '15 PAOLA ANDREA VARGAS MARIN']);

        Cliente::create([
            'codigo_sn' => 'C001', 'nombre' => 'Cliente de la 14',
            'canal_id' => $this->distribucion->id, 'asesor_sap_id' => $this->cartera14->id,
        ]);
        Cliente::create([
            'codigo_sn' => 'C002', 'nombre' => 'Cliente de la 15',
            'canal_id' => $this->residencial->id, 'asesor_sap_id' => $this->cartera15->id,
        ]);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create(['correo' => $correo, 'nombre' => 'Prueba', 'rol' => $rol, 'activo' => true]);
    }

    public function test_el_asesor_solo_ve_los_clientes_de_su_cartera(): void
    {
        $asesor = $this->usuario('asesor@segurex.com', 'ASESOR');
        $asesor->asesores()->attach($this->cartera14->id);

        $visibles = Cliente::visiblePara($asesor)->pluck('codigo_sn');

        $this->assertEquals(['C001'], $visibles->all());
    }

    public function test_un_asesor_sin_cartera_ve_los_clientes_que_le_asignaron(): void
    {
        // Un asesor tiene que poder trabajar aunque no tenga cartera de SAP:
        // se le asignan clientes directamente y esos son los suyos.
        $asesor = $this->usuario('sincartera@segurex.com', 'ASESOR');
        $cliente = Cliente::where('codigo_sn', 'C002')->first();
        $asesor->clientesAsignados()->attach($cliente->id);

        $visibles = Cliente::visiblePara($asesor)->pluck('codigo_sn');

        $this->assertEquals(['C002'], $visibles->all());
    }

    public function test_la_asignacion_directa_se_suma_a_la_cartera(): void
    {
        $asesor = $this->usuario('mixto@segurex.com', 'ASESOR');
        $asesor->asesores()->attach($this->cartera14->id);          // le da C001
        $asesor->clientesAsignados()->attach(
            Cliente::where('codigo_sn', 'C002')->first()->id,       // y ademas C002
        );

        $this->assertSame(2, Cliente::visiblePara($asesor)->count());
    }

    public function test_un_asesor_sin_cartera_ni_asignaciones_no_ve_la_empresa_entera(): void
    {
        // El punto no es castigar al que no tiene nada asignado, sino que la
        // falta de configuracion no abra la puerta. En la app vieja, quien no
        // estuviera en la tabla de permisos veia los 641 clientes.
        $nuevo = $this->usuario('recien.entrado@segurex.com', 'ASESOR');

        $this->assertSame(0, Cliente::visiblePara($nuevo)->count());
    }

    public function test_el_asesor_con_varias_carteras_las_ve_todas(): void
    {
        $asesor = $this->usuario('dos.carteras@segurex.com', 'ASESOR');
        $asesor->asesores()->attach([$this->cartera14->id, $this->cartera15->id]);

        $this->assertSame(2, Cliente::visiblePara($asesor)->count());
    }

    public function test_el_gerente_solo_ve_los_clientes_de_su_canal(): void
    {
        $gerente = $this->usuario('gerente@segurex.com', 'GERENTE_CANAL');
        $gerente->canales()->attach($this->distribucion->id);

        $visibles = Cliente::visiblePara($gerente)->pluck('codigo_sn');

        $this->assertEquals(['C001'], $visibles->all());
    }

    public function test_admin_ventas_y_ti_ven_todo(): void
    {
        foreach (['ADMIN_VENTAS', 'TI'] as $rol) {
            $admin = $this->usuario(strtolower($rol).'@segurex.com', $rol);

            $this->assertSame(2, Cliente::visiblePara($admin)->count(), "Fallo con el rol {$rol}");
        }
    }

    public function test_solo_quien_tiene_el_permiso_puede_liberar_a_sap(): void
    {
        $cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $cesar->id, 'permiso' => 'LIBERAR_SAP']);

        $ti = $this->usuario('ti@segurex.com', 'TI');

        $this->assertTrue($cesar->puedeLiberarASap());
        // Ni siquiera TI libera: es un permiso aparte del rol.
        $this->assertFalse($ti->puedeLiberarASap());
    }

    public function test_el_permiso_vencido_no_sirve(): void
    {
        $suplente = $this->usuario('suplente@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create([
            'usuario_id' => $suplente->id,
            'permiso' => 'LIBERAR_SAP',
            'vigente_desde' => now()->subDays(20),
            'vigente_hasta' => now()->subDay(),
        ]);

        $this->assertFalse($suplente->puedeLiberarASap());
    }

    public function test_el_permiso_futuro_todavia_no_sirve(): void
    {
        $suplente = $this->usuario('futuro@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create([
            'usuario_id' => $suplente->id,
            'permiso' => 'LIBERAR_SAP',
            'vigente_desde' => now()->addDays(5),
        ]);

        $this->assertFalse($suplente->puedeLiberarASap());
    }

    public function test_un_usuario_inactivo_pierde_sus_permisos(): void
    {
        $exempleado = $this->usuario('exempleado@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $exempleado->id, 'permiso' => 'LIBERAR_SAP']);
        $exempleado->update(['activo' => false]);

        $this->assertFalse($exempleado->fresh()->puedeLiberarASap());
    }

    public function test_el_correo_se_guarda_siempre_en_minusculas(): void
    {
        $usuario = $this->usuario('Cesar.Garzon@SEGUREX.com', 'ADMIN_VENTAS');

        $this->assertSame('cesar.garzon@segurex.com', $usuario->correo);
    }
}
