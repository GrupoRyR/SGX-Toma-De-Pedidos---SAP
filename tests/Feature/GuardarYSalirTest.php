<?php

namespace Tests\Feature;

use App\Enums\EstadoPedido;
use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServicioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Guardar y salir" en la pantalla del pedido.
 *
 * El boton dice guardar, asi que tiene que guardar todo lo que el asesor
 * escribio, no solo las lineas. Antes solo soltaba el bloqueo y el encabezado
 * (orden de compra, fecha, direccion alterna, observaciones) se perdia hasta
 * que el pedido se enviaba.
 */
class GuardarYSalirTest extends TestCase
{
    use RefreshDatabase;

    private ServicioPedidos $servicio;

    private Cliente $cliente;

    private Producto $producto;

    private Usuario $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        $this->servicio = app(ServicioPedidos::class);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id, 'porcentaje_descuento' => 28,
        ]);

        $this->producto = Producto::create([
            'codigo' => 'PTS09040KC', 'descripcion' => 'Cerradura', 'precio_lista' => 64900,
        ]);

        $this->asesor = Usuario::create([
            'correo' => 'adriana.russi@segurex.com', 'nombre' => 'Adriana Russi', 'rol' => 'ASESOR', 'activo' => true,
        ]);
        $this->asesor->asesores()->attach($cartera->id);
    }

    private function borrador(): Pedido
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto, cantidad: 2);

        return $pedido->fresh();
    }

    public function test_guardar_y_salir_guarda_el_encabezado(): void
    {
        $pedido = $this->borrador();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->set('orden_compra', 'OC-7781')
            ->set('fecha_facturacion', '2026-10-15')
            ->set('direccion_2', 'Calle 10 # 20-30')
            ->set('ciudad_2', 'Tunja')
            ->set('observaciones', 'Entregar en bodega 3')
            ->call('terminarEdicion')
            ->assertRedirect(route('cliente', $this->cliente));

        $pedido->refresh();

        $this->assertSame('OC-7781', $pedido->orden_compra);
        $this->assertSame('2026-10-15', $pedido->fecha_facturacion->format('Y-m-d'));
        $this->assertSame('Calle 10 # 20-30', $pedido->direccion_2);
        $this->assertSame('Tunja', $pedido->ciudad_2);
        $this->assertSame('Entregar en bodega 3', $pedido->observaciones);
        $this->assertSame(EstadoPedido::BORRADOR, $pedido->estado);
    }

    public function test_guardar_y_salir_suelta_el_bloqueo(): void
    {
        $pedido = $this->borrador();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->set('orden_compra', 'OC-1')
            ->call('terminarEdicion');

        $this->assertNull($pedido->fresh()->bloqueado_por);
    }

    public function test_si_no_se_puede_guardar_avisa_y_no_sale(): void
    {
        // Salir sin guardar perderia lo escrito sin decir nada. Mejor quedarse,
        // mostrar por que y dejar que el asesor lo corrija; el bloqueo sigue
        // siendo suyo y vence solo si se va.
        $pedido = $this->borrador();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->set('orden_compra', 'OC-9')
            ->set('fecha_facturacion', 'no-es-una-fecha')
            ->call('terminarEdicion')
            ->assertNoRedirect()
            ->assertSee('No se pudo guardar');

        $this->assertNull($pedido->fresh()->orden_compra);
        $this->assertSame($this->asesor->id, $pedido->fresh()->bloqueado_por);
    }

    public function test_quien_solo_lo_mira_no_cambia_el_encabezado_al_salir(): void
    {
        // Un pedido aprobado ya no es editable por el asesor: salir no puede
        // escribir en el. (Uno pendiente si lo es, hasta que lo aprueben.)
        $gerente = Usuario::create([
            'correo' => 'jessika.quintero@segurex.com', 'nombre' => 'Jessika Quintero', 'rol' => 'GERENTE_CANAL', 'activo' => true,
        ]);
        $gerente->canales()->attach($this->cliente->canal_id);

        $pedido = $this->borrador();
        $pedido->update(['orden_compra' => 'OC-ORIGINAL']);
        $pedido = $this->servicio->enviar($pedido->fresh(), $this->asesor);
        $pedido = $this->servicio->aprobar($pedido, $gerente);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->set('orden_compra', 'OC-CAMBIADA')
            ->call('terminarEdicion');

        $this->assertSame('OC-ORIGINAL', $pedido->fresh()->orden_compra);
    }
}
