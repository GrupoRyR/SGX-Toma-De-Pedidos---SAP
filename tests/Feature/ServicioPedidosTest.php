<?php

namespace Tests\Feature;

use App\Enums\EstadoPedido;
use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\PedidoLinea;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServicioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Armado del pedido.
 *
 * Aqui viven las cuentas que terminan en una factura, y la numeracion de lineas
 * que SAP exige. Cada prueba cubre un error concreto de la app vieja.
 */
class ServicioPedidosTest extends TestCase
{
    use RefreshDatabase;

    private ServicioPedidos $servicio;

    private Cliente $cliente;

    private Usuario $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(ServicioPedidos::class);
        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);
        Configuracion::create(['clave' => 'minutos_bloqueo', 'valor' => '30', 'tipo' => 'numero']);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'direccion' => 'DIAGONAL 31A 31-65', 'ciudad' => 'PAIPA',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id,
            'porcentaje_descuento' => 28,
        ]);

        $this->asesor = Usuario::create([
            'correo' => 'asesor@segurex.com', 'nombre' => 'Asesor',
            'rol' => 'ASESOR', 'activo' => true,
        ]);
        $this->asesor->asesores()->attach($cartera->id);
    }

    private function producto(string $codigo = 'PTS09040KC', float $precio = 64900): Producto
    {
        return Producto::create([
            'codigo' => $codigo,
            'descripcion' => 'SEGUREX A-80PD CERRADURA ENTRADA SATURNO SATIN NIQUEL',
            'familia' => 'SATURNO',
            'precio_lista' => $precio,
        ]);
    }

    public function test_el_pedido_nace_en_borrador_con_los_datos_del_cliente(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);

        $this->assertSame(EstadoPedido::BORRADOR, $pedido->estado);
        $this->assertSame('CN0507', $pedido->codigo_cliente);
        $this->assertSame('PAIPA', $pedido->ciudad);
        $this->assertSame($this->asesor->id, $pedido->creado_por);
    }

    public function test_aplica_el_descuento_del_cliente_a_la_linea(): void
    {
        // 64.900 con 28% de descuento = 46.728.
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $linea = $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 2);

        $this->assertSame(46728.0, (float) $linea->precio_unitario);
        $this->assertSame(93456.0, (float) $linea->subtotal_linea);
    }

    public function test_calcula_iva_y_total_del_pedido(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto(precio: 100000), cantidad: 1);

        $pedido->refresh();

        $this->assertSame(72000.0, (float) $pedido->subtotal);
        $this->assertSame(13680.0, (float) $pedido->iva);
        $this->assertSame(85680.0, (float) $pedido->total);
    }

    public function test_el_precio_manual_anula_el_atp(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $linea = $this->servicio->agregarLinea(
            $pedido, $this->producto(), cantidad: 1, atp: 50, precioManual: 30000,
        );

        $this->assertSame(30000.0, (float) $linea->precio_unitario);
        $this->assertNull($linea->atp_descuento_pct);
    }

    public function test_la_cantidad_tiene_que_ser_mayor_que_cero(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);

        $this->expectException(RuntimeException::class);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 0);
    }

    public function test_las_lineas_quedan_numeradas_desde_cero_sin_huecos(): void
    {
        // Error 3 de la app vieja: LineNum llegaba vacio o con huecos a SAP y la
        // importacion se caia.
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);

        foreach (['A1', 'A2', 'A3'] as $codigo) {
            $this->servicio->agregarLinea($pedido, $this->producto($codigo), cantidad: 1);
        }

        $delMedio = $pedido->lineas()->where('codigo_producto', 'A2')->first();
        $this->servicio->quitarLinea($pedido, $delMedio);

        $this->assertSame([0, 1], $pedido->fresh()->lineas->pluck('linea_num')->map(fn ($n) => (int) $n)->all());
    }

    public function test_enviar_exige_al_menos_una_linea(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);

        $this->expectException(RuntimeException::class);
        $this->servicio->enviar($pedido, $this->asesor);
    }

    public function test_enviar_deja_el_pedido_pendiente(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 1);

        $enviado = $this->servicio->enviar($pedido, $this->asesor);

        $this->assertSame(EstadoPedido::PENDIENTE, $enviado->estado);
    }

    public function test_si_hay_direccion_alterna_la_ciudad_alterna_es_obligatoria(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 1);
        $pedido->update(['direccion_2' => 'CALLE 100 # 15-20']);

        $this->expectException(RuntimeException::class);
        $this->servicio->enviar($pedido->fresh(), $this->asesor);
    }

    public function test_un_pedido_aprobado_ya_no_se_modifica(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $pedido->forceFill(['estado' => EstadoPedido::APROBADO])->save();

        $this->expectException(RuntimeException::class);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 1);
    }

    public function test_la_version_sube_con_cada_cambio(): void
    {
        // La version es lo que impide que un aprobador apruebe algo distinto de
        // lo que leyo, si el asesor lo edita mientras tanto.
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $inicial = (int) $pedido->version;

        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 1);

        $this->assertGreaterThan($inicial, (int) $pedido->fresh()->version);
    }

    public function test_el_bloqueo_impide_que_otro_edite(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $otro = Usuario::create([
            'correo' => 'otro@segurex.com', 'nombre' => 'Otro',
            'rol' => 'ADMIN_VENTAS', 'activo' => true,
        ]);

        $this->assertTrue($this->servicio->bloquear($pedido, $this->asesor));
        $this->assertFalse($this->servicio->bloquear($pedido->fresh(), $otro));
    }

    public function test_al_liberar_el_bloqueo_otro_puede_editar(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $otro = Usuario::create([
            'correo' => 'otro@segurex.com', 'nombre' => 'Otro',
            'rol' => 'ADMIN_VENTAS', 'activo' => true,
        ]);

        $this->servicio->bloquear($pedido, $this->asesor);
        $this->servicio->liberarBloqueo($pedido);

        $this->assertTrue($this->servicio->bloquear($pedido->fresh(), $otro));
    }

    public function test_borrar_el_pedido_no_deja_lineas_huerfanas(): void
    {
        // Error 8 de la app vieja: se borraba el pedido y sus lineas quedaban
        // sueltas en la lista para siempre.
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 1);

        $pedido->forceDelete();

        $this->assertSame(0, PedidoLinea::count());
    }
}
