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
}
