<?php

namespace Tests\Feature;

use App\Enums\EstadoPedido;
use App\Models\AsesorSap;
use App\Models\Bitacora;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use App\Services\ServicioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aprobacion, rechazo y visto bueno para SAP.
 *
 * Los dos controles que SEGUREX pidio: una persona aprueba, y despues Cesar
 * Garzon o Marly Ossa dan el visto bueno antes de que algo llegue a SAP.
 */
class AprobacionTest extends TestCase
{
    use RefreshDatabase;

    private ServicioPedidos $servicio;

    private Cliente $cliente;

    private Usuario $asesor;

    private Usuario $gerente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(ServicioPedidos::class);
        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id,
            'porcentaje_descuento' => 28,
        ]);

        $this->asesor = $this->usuario('asesor@segurex.com', 'ASESOR');
        $this->asesor->asesores()->attach($cartera->id);

        $this->gerente = $this->usuario('jessika.quintero@segurex.com', 'GERENTE_CANAL');
        $this->gerente->canales()->attach($canal->id);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create([
            'correo' => $correo, 'nombre' => 'Prueba', 'rol' => $rol, 'activo' => true,
        ]);
    }

    private function pedidoPendiente(?Usuario $autor = null)
    {
        $producto = Producto::firstOrCreate(
            ['codigo' => 'PTS09040KC'],
            ['descripcion' => 'SEGUREX A-80PD CERRADURA ENTRADA SATURNO', 'precio_lista' => 64900],
        );

        $pedido = $this->servicio->crear($this->cliente, $autor ?? $this->asesor);
        $this->servicio->agregarLinea($pedido, $producto, cantidad: 2);

        return $this->servicio->enviar($pedido->fresh(), $autor ?? $this->asesor);
    }

    private function conPermisoDeLiberar(string $correo = 'cesar.garzon@segurex.com'): Usuario
    {
        $usuario = $this->usuario($correo, 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $usuario->id, 'permiso' => 'LIBERAR_SAP']);

        return $usuario;
    }

    public function test_aprobar_deja_constancia_de_quien_y_cuando(): void
    {
        $pedido = $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);

        $this->assertSame(EstadoPedido::APROBADO, $pedido->estado);
        $this->assertSame($this->gerente->id, $pedido->aprobado_por);
        $this->assertNotNull($pedido->fecha_aprobacion);
    }

    public function test_no_se_puede_aprobar_dos_veces(): void
    {
        // Error 4 de la app vieja: doble clic o dos aprobadores al tiempo
        // aprobaban el mismo pedido dos veces.
        $pedido = $this->pedidoPendiente();
        $this->servicio->aprobar($pedido, $this->gerente);

        $this->expectExceptionMessage('ya no esta pendiente');
        $this->servicio->aprobar($pedido->fresh(), $this->gerente);
    }

    public function test_no_se_aprueba_si_el_pedido_cambio_mientras_se_revisaba(): void
    {
        // El asesor puede editar un pedido PENDIENTE. Sin este control, el
        // aprobador confirmaria algo distinto de lo que leyo en pantalla.
        $pedido = $this->pedidoPendiente();
        $versionQueVioElAprobador = (int) $pedido->version;

        $producto = Producto::create([
            'codigo' => 'OTRO', 'descripcion' => 'Otro producto', 'precio_lista' => 500000,
        ]);
        $this->servicio->agregarLinea($pedido->fresh(), $producto, cantidad: 10);

        $this->expectExceptionMessage('cambio mientras lo revisabas');
        $this->servicio->aprobar($pedido->fresh(), $this->gerente, $versionQueVioElAprobador);
    }

    public function test_aprobar_guarda_una_copia_congelada_del_pedido(): void
    {
        $pedido = $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);

        $foto = $pedido->snapshot_aprobado;

        $this->assertNotNull($foto);
        // Al pasar por JSON, un total sin decimales vuelve como entero:
        // se compara el valor, no el tipo.
        $this->assertEquals((float) $pedido->total, $foto['total']);
        $this->assertCount(1, $foto['lineas']);
    }

    public function test_ti_que_aprueba_su_propio_pedido_queda_marcado_en_la_bitacora(): void
    {
        // SEGUREX permite la autoaprobacion a TI para pruebas e inducciones.
        // Que sea posible no quiere decir que sea invisible.
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $pedido = $this->pedidoPendiente($ti);

        $this->servicio->aprobar($pedido, $ti);

        $registro = Bitacora::where('accion', 'APROBAR')->latest('id')->first();

        $this->assertTrue($registro->detalle['autoaprobacion']);
        $this->assertSame('TI', $registro->detalle['rol']);
    }

    public function test_una_aprobacion_normal_no_queda_marcada_como_autoaprobacion(): void
    {
        $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);

        $registro = Bitacora::where('accion', 'APROBAR')->latest('id')->first();

        $this->assertFalse($registro->detalle['autoaprobacion']);
    }

    public function test_rechazar_exige_motivo(): void
    {
        $this->expectExceptionMessage('necesita un motivo');
        $this->servicio->rechazar($this->pedidoPendiente(), $this->gerente, '   ');
    }

    public function test_rechazar_guarda_el_motivo_y_permite_reenviar(): void
    {
        $pedido = $this->servicio->rechazar($this->pedidoPendiente(), $this->gerente, 'Falta la orden de compra');

        $this->assertSame(EstadoPedido::RECHAZADO, $pedido->estado);
        $this->assertSame('Falta la orden de compra', $pedido->motivo_rechazo);

        // Un rechazado se corrige y vuelve a pendiente.
        $reenviado = $this->servicio->enviar($pedido->fresh(), $this->asesor);

        $this->assertSame(EstadoPedido::PENDIENTE, $reenviado->estado);
        $this->assertNull($reenviado->motivo_rechazo);
    }

    public function test_un_pedido_aprobado_todavia_no_va_a_sap(): void
    {
        // El punto central del control que pidio SEGUREX.
        $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);

        $this->assertSame(0, Pedido::listosParaSap()->count());
    }

    public function test_liberar_deja_el_pedido_listo_para_sap(): void
    {
        $cesar = $this->conPermisoDeLiberar();
        $pedido = $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);

        $liberado = $this->servicio->liberar($pedido, $cesar);

        $this->assertSame(EstadoPedido::LIBERADO, $liberado->estado);
        $this->assertSame($cesar->id, $liberado->liberado_por);
        $this->assertSame(1, Pedido::listosParaSap()->count());
    }

    public function test_sin_el_permiso_no_se_libera_aunque_sea_administrador(): void
    {
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $pedido = $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);

        $this->expectExceptionMessage('No tienes el permiso');
        $this->servicio->liberar($pedido, $ti);
    }

    public function test_no_se_libera_un_pedido_que_alguien_esta_editando(): void
    {
        $cesar = $this->conPermisoDeLiberar();
        $pedido = $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);
        $pedido->forceFill([
            'bloqueado_por' => $this->asesor->id,
            'bloqueado_hasta' => now()->addMinutes(30),
        ])->save();

        $this->expectExceptionMessage('esta editando');
        $this->servicio->liberar($pedido->fresh(), $cesar);
    }

    public function test_devolver_un_liberado_lo_regresa_a_aprobado(): void
    {
        $cesar = $this->conPermisoDeLiberar();
        $pedido = $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);
        $liberado = $this->servicio->liberar($pedido, $cesar);

        $devuelto = $this->servicio->devolverDeLiberado($liberado, $cesar, 'Confirmar precios con el cliente');

        $this->assertSame(EstadoPedido::APROBADO, $devuelto->estado);
        $this->assertNull($devuelto->liberado_por);
        $this->assertSame(0, Pedido::listosParaSap()->count());
    }

    public function test_un_pedido_ya_importado_no_se_devuelve(): void
    {
        $cesar = $this->conPermisoDeLiberar();
        $pedido = $this->servicio->aprobar($this->pedidoPendiente(), $this->gerente);
        $liberado = $this->servicio->liberar($pedido, $cesar);
        $liberado->forceFill(['importado_sap' => true])->save();

        $this->expectExceptionMessage('todavia no entro a SAP');
        $this->servicio->devolverDeLiberado($liberado->fresh(), $cesar, 'Ya no');
    }
}
