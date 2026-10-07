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
 * Largo maximo de la direccion alterna y de las observaciones.
 *
 * SAP corta la direccion de entrega en 60 caracteres y los comentarios en 250.
 * Si se dejan pasar mas largos, DTW rechaza la plantilla o los recorta sin
 * avisar, asi que se frenan al guardar, con el error junto al campo.
 */
class LimitesEncabezadoTest extends TestCase
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

    public function test_una_direccion_alterna_de_mas_de_60_no_se_guarda(): void
    {
        $pedido = $this->borrador();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->set('direccion_2', str_repeat('a', 61))
            ->set('ciudad_2', 'Bogota')
            ->call('terminarEdicion')
            ->assertHasErrors(['direccion_2' => 'max'])
            ->assertSee('La dirección alterna no puede pasar de 60 caracteres.')
            ->assertSet('error', '')
            ->assertNoRedirect();

        $this->assertNull($pedido->fresh()->direccion_2);
    }

    public function test_unas_observaciones_de_mas_de_250_no_se_guardan(): void
    {
        $pedido = $this->borrador();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->set('observaciones', str_repeat('o', 251))
            ->call('terminarEdicion')
            ->assertHasErrors(['observaciones' => 'max'])
            ->assertSee('Las observaciones no pueden pasar de 250 caracteres.')
            ->assertSet('error', '')
            ->assertNoRedirect();

        $this->assertNull($pedido->fresh()->observaciones);
    }

    public function test_enviar_con_textos_demasiado_largos_no_envia(): void
    {
        $pedido = $this->borrador();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->set('direccion_2', str_repeat('a', 61))
            ->set('ciudad_2', 'Bogota')
            ->set('observaciones', str_repeat('o', 251))
            ->call('enviar')
            ->assertHasErrors(['direccion_2' => 'max', 'observaciones' => 'max'])
            ->assertNoRedirect();

        $pedido->refresh();
        $this->assertSame(EstadoPedido::BORRADOR, $pedido->estado);
        $this->assertNull($pedido->direccion_2);
        $this->assertNull($pedido->observaciones);
    }

    public function test_en_el_limite_justo_si_se_guarda(): void
    {
        $pedido = $this->borrador();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->set('direccion_2', str_repeat('a', 60))
            ->set('ciudad_2', 'Bogota')
            ->set('observaciones', str_repeat('o', 250))
            ->call('terminarEdicion')
            ->assertHasNoErrors()
            ->assertRedirect();

        $pedido->refresh();
        $this->assertSame(str_repeat('a', 60), $pedido->direccion_2);
        $this->assertSame(str_repeat('o', 250), $pedido->observaciones);
    }

    public function test_los_campos_traen_el_limite_en_el_formulario(): void
    {
        $pedido = $this->borrador();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->assertSeeHtml('maxlength="60"')
            ->assertSeeHtml('maxlength="250"');
    }
}
