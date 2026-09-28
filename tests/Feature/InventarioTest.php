<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServicioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Existencias en el buscador de productos.
 *
 * Lo que el asesor ve es una foto de SAP, no el dato en vivo. Todo lo que se
 * prueba aqui gira alrededor de eso: que la fecha del corte siempre acompane al
 * numero, que un dato viejo se muestre y no se esconda, y que pedir de mas
 * avise sin bloquear.
 */
class InventarioTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $asesor;

    private Cliente $cliente;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);
        Configuracion::create(['clave' => 'minutos_bloqueo', 'valor' => '30', 'tipo' => 'numero']);
        Configuracion::create(['clave' => 'horas_inventario_viejo', 'valor' => '24', 'tipo' => 'numero']);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id, 'porcentaje_descuento' => 28,
        ]);

        $this->asesor = Usuario::create([
            'correo' => 'asesor@segurex.com', 'nombre' => 'Asesor', 'rol' => 'ASESOR', 'activo' => true,
        ]);
        $this->asesor->asesores()->attach($cartera->id);

        $this->producto = Producto::create([
            'codigo' => 'PTS09040KC',
            'descripcion' => 'SEGUREX A-80PD CERRADURA ENTRADA SATURNO',
            'precio_lista' => 64900,
        ]);
    }

    private function existencia(float $cuanto, ?string $bodega = 'PRINCIPAL', $corte = null): Inventario
    {
        return Inventario::create([
            'codigo_producto' => $this->producto->codigo,
            'bodega' => $bodega,
            'disponible' => $cuanto,
            'fecha_corte' => $corte ?? now(),
        ]);
    }

    private function pedido()
    {
        return app(ServicioPedidos::class)->crear($this->cliente, $this->asesor);
    }

    public function test_las_existencias_se_suman_entre_bodegas(): void
    {
        $this->existencia(14, 'PRINCIPAL');
        $this->existencia(3, 'NORTE');

        $conExistencias = Producto::conExistencias()->find($this->producto->id);

        $this->assertEquals(17, $conExistencias->disponible_total);
    }

    public function test_el_buscador_muestra_lo_disponible(): void
    {
        $this->existencia(14);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $this->pedido()])
            ->set('buscarProducto', 'PTS09040KC')
            ->assertSee('14')
            ->assertSee('disponibles');
    }

    public function test_sin_corte_no_se_muestra_un_cero_enganoso(): void
    {
        /*
         * Mientras el robot no haya publicado nada, no hay dato. Un "0
         * disponibles" se leeria como agotado, que es una afirmacion que la web
         * no puede hacer.
         */
        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $this->pedido()])
            ->set('buscarProducto', 'PTS09040KC')
            ->assertDontSee('disponibles');
    }

    public function test_un_dato_viejo_se_muestra_igual(): void
    {
        // Atenuado y con la antiguedad a la vista, pero no se esconde.
        $this->existencia(14, 'PRINCIPAL', now()->subDays(3));

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $this->pedido()])
            ->set('buscarProducto', 'PTS09040KC')
            ->assertSee('disponibles')
            ->assertSee('text-niquel-claro', escape: false);
    }

    public function test_pedir_mas_de_lo_que_hay_avisa(): void
    {
        $this->existencia(3);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $this->pedido()])
            ->call('elegirProducto', $this->producto->id)
            ->set('cantidad', '200')
            ->assertSee('más de lo que hay en existencias');
    }

    public function test_pedir_mas_de_lo_que_hay_no_bloquea(): void
    {
        // A veces se pide contra reposicion: el asesor sabe algo que la foto no.
        $this->existencia(3);
        $pedido = $this->pedido();

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->call('elegirProducto', $this->producto->id)
            ->set('cantidad', '200')
            ->call('agregar');

        $this->assertSame(1, $pedido->fresh()->lineas()->count());
    }

    public function test_pedir_dentro_de_lo_disponible_no_avisa_nada(): void
    {
        $this->existencia(50);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $this->pedido()])
            ->call('elegirProducto', $this->producto->id)
            ->set('cantidad', '2')
            ->assertDontSee('más de lo que hay en existencias');
    }

    public function test_sin_inventario_publicado_no_se_avisa_de_faltantes(): void
    {
        // No se puede advertir de un faltante que no se conoce.
        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $this->pedido()])
            ->call('elegirProducto', $this->producto->id)
            ->set('cantidad', '9999')
            ->assertDontSee('más de lo que hay en existencias');
    }
}
