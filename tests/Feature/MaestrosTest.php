<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use App\Models\Bitacora;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServicioMaestros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Altas y cambios de clientes y productos desde la web.
 *
 * El codigo tiene que ser el mismo de SAP, porque es lo que viaja en la
 * plantilla DTW: por eso se cuida que sea unico y sin espacios.
 */
class MaestrosTest extends TestCase
{
    use RefreshDatabase;

    private ServicioMaestros $servicio;

    private Usuario $admin;

    private Canal $canal;

    private AsesorSap $cartera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(ServicioMaestros::class);
        $this->admin = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        $this->canal = Canal::create(['nombre' => 'Distribucion']);
        $this->cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO', 'nombre' => 'MONICA RIVERA AREVALO']);

        $this->actingAs($this->admin);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create(['correo' => $correo, 'nombre' => 'Prueba '.$rol, 'rol' => $rol, 'activo' => true]);
    }

    private function datosCliente(array $cambios = []): array
    {
        return array_merge([
            'codigo_sn' => 'CN0900',
            'nombre' => 'FERRETERIA EL TORNILLO SAS',
            'direccion' => 'CALLE 10 # 5-20',
            'ciudad' => 'TUNJA',
            'canal_id' => $this->canal->id,
            'asesor_sap_id' => $this->cartera->id,
            'porcentaje_descuento' => 15,
        ], $cambios);
    }

    // ---------- Clientes: servicio ----------

    public function test_crea_un_cliente_y_lo_registra(): void
    {
        $cliente = $this->servicio->crearCliente($this->datosCliente(['codigo_sn' => '  CN0900 ']));

        $this->assertSame('CN0900', $cliente->codigo_sn);
        $this->assertTrue($cliente->activo);
        $this->assertEquals(15, (float) $cliente->porcentaje_descuento);
        $this->assertTrue(Bitacora::where('accion', 'CREAR_CLIENTE')->where('entidad_id', $cliente->id)->exists());
    }

    public function test_el_asesor_de_la_cartera_ve_al_cliente_nuevo(): void
    {
        $asesor = $this->usuario('monica.rivera@segurex.com', 'ASESOR');
        $asesor->asesores()->attach($this->cartera->id);

        $this->servicio->crearCliente($this->datosCliente());

        $this->assertSame(1, Cliente::visiblePara($asesor)->where('codigo_sn', 'CN0900')->count());

        Livewire::actingAs($asesor)->test('mis-clientes')->assertSee('FERRETERIA EL TORNILLO SAS');
    }

    public function test_rechaza_codigo_vacio_con_espacios_o_repetido(): void
    {
        $this->servicio->crearCliente($this->datosCliente());

        foreach (['' => 'obligatorio', 'CN 0901' => 'espacios', 'cn0900' => 'Ya existe'] as $codigo => $mensaje) {
            try {
                $this->servicio->crearCliente($this->datosCliente(['codigo_sn' => $codigo]));
                $this->fail("Acepto el codigo [{$codigo}]");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $this->assertSame(1, Cliente::count());
    }

    public function test_rechaza_nombre_vacio_y_descuento_fuera_de_rango(): void
    {
        foreach ([['nombre' => '  '], ['porcentaje_descuento' => 101], ['porcentaje_descuento' => -1]] as $malo) {
            try {
                $this->servicio->crearCliente($this->datosCliente($malo));
                $this->fail('Acepto '.json_encode($malo));
            } catch (RuntimeException) {
                $this->assertSame(0, Cliente::count());
            }
        }
    }

    public function test_rechaza_canal_o_cartera_que_no_existen(): void
    {
        $this->expectException(RuntimeException::class);

        $this->servicio->crearCliente($this->datosCliente(['asesor_sap_id' => 999]));
    }

    public function test_edita_un_cliente_y_guarda_el_antes_y_el_despues(): void
    {
        $cliente = $this->servicio->crearCliente($this->datosCliente());

        $this->servicio->actualizarCliente($cliente, ['ciudad' => 'DUITAMA', 'porcentaje_descuento' => 20]);

        $cliente->refresh();
        $this->assertSame('DUITAMA', $cliente->ciudad);
        $this->assertSame('FERRETERIA EL TORNILLO SAS', $cliente->nombre);

        $registro = Bitacora::where('accion', 'EDITAR_CLIENTE')->firstOrFail();
        $this->assertSame('TUNJA', $registro->detalle['cambios']['ciudad']['antes']);
        $this->assertSame('DUITAMA', $registro->detalle['cambios']['ciudad']['despues']);
    }

    public function test_editar_no_choca_con_su_propio_codigo_pero_si_con_otro(): void
    {
        $uno = $this->servicio->crearCliente($this->datosCliente());
        $this->servicio->crearCliente($this->datosCliente(['codigo_sn' => 'CN0901']));

        $this->servicio->actualizarCliente($uno, ['codigo_sn' => 'CN0900', 'nombre' => 'OTRO NOMBRE']);
        $this->assertSame('OTRO NOMBRE', $uno->refresh()->nombre);

        $this->expectExceptionMessage('Ya existe');
        $this->servicio->actualizarCliente($uno, ['codigo_sn' => 'CN0901']);
    }

    public function test_desactivar_no_borra(): void
    {
        $cliente = $this->servicio->crearCliente($this->datosCliente());

        $this->servicio->actualizarCliente($cliente, ['activo' => false]);

        $this->assertFalse($cliente->refresh()->activo);
        $this->assertSame(1, Cliente::count());
    }

    // ---------- Productos: servicio ----------

    public function test_crea_y_edita_un_producto(): void
    {
        $producto = $this->servicio->crearProducto([
            'codigo' => 'CER-100', 'descripcion' => 'CERRADURA DE SOBREPONER', 'familia' => 'CERRADURAS', 'precio_lista' => 64900,
        ]);

        $this->assertEquals(64900, (float) $producto->precio_lista);
        $this->assertTrue(Bitacora::where('accion', 'CREAR_PRODUCTO')->exists());

        $this->servicio->actualizarProducto($producto, ['precio_lista' => 70000]);

        $this->assertEquals(70000, (float) $producto->refresh()->precio_lista);
        $this->assertTrue(Bitacora::where('accion', 'EDITAR_PRODUCTO')->exists());
    }

    public function test_rechaza_producto_con_codigo_repetido_o_precio_negativo(): void
    {
        $this->servicio->crearProducto(['codigo' => 'CER-100', 'descripcion' => 'CERRADURA', 'precio_lista' => 1000]);

        foreach ([
            ['codigo' => 'cer-100', 'descripcion' => 'OTRA', 'precio_lista' => 1000],
            ['codigo' => 'CER-101', 'descripcion' => '', 'precio_lista' => 1000],
            ['codigo' => 'CER-102', 'descripcion' => 'OTRA', 'precio_lista' => -5],
        ] as $malo) {
            try {
                $this->servicio->crearProducto($malo);
                $this->fail('Acepto '.json_encode($malo));
            } catch (RuntimeException) {
                $this->assertSame(1, Producto::count());
            }
        }
    }

    // ---------- Pantallas ----------

    public function test_un_asesor_o_un_gerente_no_entran_a_los_maestros(): void
    {
        $cliente = $this->servicio->crearCliente($this->datosCliente());
        $producto = $this->servicio->crearProducto(['codigo' => 'CER-100', 'descripcion' => 'CERRADURA', 'precio_lista' => 1000]);

        foreach (['ASESOR', 'GERENTE_CANAL'] as $rol) {
            $quien = $this->usuario(strtolower($rol).'@segurex.com', $rol);

            $this->actingAs($quien)->get(route('maestros-clientes'))->assertForbidden();
            $this->actingAs($quien)->get(route('maestros-cliente', $cliente))->assertForbidden();
            $this->actingAs($quien)->get(route('maestros-productos'))->assertForbidden();
            $this->actingAs($quien)->get(route('maestros-producto', $producto))->assertForbidden();
        }
    }

    public function test_el_admin_ve_la_lista_y_busca(): void
    {
        $this->servicio->crearCliente($this->datosCliente());
        $this->servicio->crearCliente($this->datosCliente(['codigo_sn' => 'CN0901', 'nombre' => 'CERRAJERIA LA LLAVE']));

        $this->get(route('maestros-clientes'))->assertOk()->assertSee('FERRETERIA EL TORNILLO SAS');

        Livewire::test('maestros-clientes')
            ->set('buscar', 'CN0901')
            ->assertSee('CERRAJERIA LA LLAVE')
            ->assertDontSee('FERRETERIA EL TORNILLO SAS');
    }

    public function test_la_lista_separa_activos_e_inactivos(): void
    {
        $cliente = $this->servicio->crearCliente($this->datosCliente());
        $this->servicio->actualizarCliente($cliente, ['activo' => false]);

        Livewire::test('maestros-clientes')
            ->assertDontSee('FERRETERIA EL TORNILLO SAS')
            ->set('estado', 'inactivos')
            ->assertSee('FERRETERIA EL TORNILLO SAS');
    }

    public function test_el_admin_crea_un_cliente_desde_la_pantalla(): void
    {
        Livewire::test('maestros-clientes')
            ->set('agregando', true)
            ->set('nuevo.codigo_sn', 'CN0950')
            ->set('nuevo.nombre', 'DEPOSITO LA PUERTA')
            ->set('nuevo.canal_id', (string) $this->canal->id)
            ->set('nuevo.asesor_sap_id', (string) $this->cartera->id)
            ->set('nuevo.porcentaje_descuento', '10')
            ->call('crear')
            ->assertHasNoErrors()
            ->assertRedirect(route('maestros-cliente', Cliente::where('codigo_sn', 'CN0950')->first()));

        $this->assertSame($this->cartera->id, Cliente::where('codigo_sn', 'CN0950')->value('asesor_sap_id'));
    }

    public function test_un_codigo_repetido_muestra_el_error(): void
    {
        $this->servicio->crearCliente($this->datosCliente());

        Livewire::test('maestros-clientes')
            ->set('agregando', true)
            ->set('nuevo.codigo_sn', 'CN0900')
            ->set('nuevo.nombre', 'OTRO')
            ->call('crear')
            ->assertSet('error', 'Ya existe un cliente con el codigo CN0900.')
            ->assertSee('Ya existe un cliente con el codigo CN0900.');

        $this->assertSame(1, Cliente::count());
    }

    public function test_el_admin_edita_y_desactiva_un_cliente(): void
    {
        $cliente = $this->servicio->crearCliente($this->datosCliente());

        $this->get(route('maestros-cliente', $cliente))->assertOk()->assertSee('CN0900');

        Livewire::test('maestros-cliente', ['cliente' => $cliente])
            ->assertSet('datos.ciudad', 'TUNJA')
            ->set('datos.ciudad', 'SOGAMOSO')
            ->call('guardar')
            ->assertSet('error', '')
            ->assertSee('Cambios guardados.')
            ->call('cambiarActivo');

        $cliente->refresh();
        $this->assertSame('SOGAMOSO', $cliente->ciudad);
        $this->assertFalse($cliente->activo);
    }

    public function test_el_admin_crea_y_edita_un_producto_desde_la_pantalla(): void
    {
        Livewire::test('maestros-productos')
            ->set('agregando', true)
            ->set('nuevo.codigo', 'CER-200')
            ->set('nuevo.descripcion', 'CANDADO DE ALTA SEGURIDAD')
            ->set('nuevo.precio_lista', '45900')
            ->call('crear')
            ->assertSet('error', '');

        $producto = Producto::where('codigo', 'CER-200')->firstOrFail();

        Livewire::test('maestros-productos')->assertSee('CANDADO DE ALTA SEGURIDAD');

        Livewire::test('maestros-producto', ['producto' => $producto])
            ->set('datos.precio_lista', '47000')
            ->call('guardar')
            ->assertSee('Cambios guardados.');

        $this->assertEquals(47000, (float) $producto->refresh()->precio_lista);

        Livewire::test('maestros-productos')
            ->set('agregando', true)
            ->set('nuevo.codigo', 'cer-200')
            ->set('nuevo.descripcion', 'OTRO')
            ->call('crear')
            ->assertSee('Ya existe un producto con el codigo cer-200.');
    }
}
