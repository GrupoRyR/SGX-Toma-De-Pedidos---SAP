<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use App\Models\Bitacora;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Importacion;
use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Carga de maestros por archivo desde Administracion.
 *
 * La promesa de esta pantalla: subir un archivo no cambia nada. Primero se ve
 * que pasaria fila por fila, y solo se carga lo que el admin elija.
 */
class CargaMaestrosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Usuario::create([
            'correo' => 'cesar.garzon@segurex.com', 'nombre' => 'Cesar', 'rol' => 'ADMIN_VENTAS', 'activo' => true,
        ]);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO', 'nombre' => 'MONICA RIVERA AREVALO']);

        Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO', 'ciudad' => 'PAIPA',
            'direccion' => 'DIAGONAL 31A 31-65', 'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id,
            'porcentaje_descuento' => 28,
        ]);
        Cliente::create([
            'codigo_sn' => 'CN0491', 'nombre' => 'PUERTAS METALICAS COLOMBIANAS SAS', 'ciudad' => 'BOGOTA',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id,
        ]);

        Producto::create(['codigo' => 'CER-100', 'descripcion' => 'CERRADURA', 'precio_lista' => 64900]);
    }

    /**
     * Fila 2: igual. Fila 3: cambia la ciudad y deja la direccion en blanco.
     * Fila 4: nuevo con cartera que no existe. Fila 5: error, sin nombre.
     */
    private function csvClientes(): UploadedFile
    {
        $csv = "Codigo SN,Nombre,Direccion,Ciudad,Canal,Asesor,Descuento\n"
            ."CN0507,RIVERA TUTA JOSE OSVALDO,DIAGONAL 31A 31-65,PAIPA,DISTRIBUCION,14 MONICA RIVERA AREVALO,28\n"
            ."CN0491,PUERTAS METALICAS COLOMBIANAS SAS,,TUNJA,,,\n"
            ."CN0999,FERRETERIA NUEVA,CALLE 1,SOGAMOSO,ferreteria,30 ASESOR NUEVO,10\n"
            ."CN0998,,,,,,\n";

        return UploadedFile::fake()->createWithContent('clientes.csv', $csv);
    }

    private function cargar(): Testable
    {
        return Livewire::actingAs($this->admin)->test('maestros-cargar')
            ->set('tipo', 'clientes')
            ->set('archivo', $this->csvClientes());
    }

    private function fila($componente, int $numero): array
    {
        return collect($componente->get('filas'))->firstWhere('fila', $numero);
    }

    public function test_un_asesor_no_entra(): void
    {
        $asesor = Usuario::create(['correo' => 'a@segurex.com', 'nombre' => 'A', 'rol' => 'ASESOR', 'activo' => true]);

        $this->actingAs($asesor)->get(route('maestros-cargar'))->assertForbidden();
    }

    public function test_el_admin_entra(): void
    {
        $this->actingAs($this->admin)->get(route('maestros-cargar'))->assertOk()->assertSee('Descargar plantilla');
    }

    public function test_subir_muestra_la_previsualizacion_sin_escribir_nada(): void
    {
        $antes = [Cliente::count(), AsesorSap::count(), Canal::count(), Bitacora::count()];

        $componente = $this->cargar()->assertHasNoErrors();

        $this->assertSame('IGUAL', $this->fila($componente, 2)['estado']);

        $cambia = $this->fila($componente, 3);
        $this->assertSame('CAMBIA', $cambia['estado']);
        $this->assertSame(['antes' => 'BOGOTA', 'despues' => 'TUNJA'], $cambia['cambios']['ciudad']);
        // La direccion vacia no aparece como cambio: no borra nada.
        $this->assertArrayNotHasKey('direccion', $cambia['cambios']);

        $nuevo = $this->fila($componente, 4);
        $this->assertSame('NUEVO', $nuevo['estado']);
        $this->assertStringContainsString('ningun asesor', implode(' ', $nuevo['avisos']));

        $this->assertSame('ERROR', $this->fila($componente, 5)['estado']);

        $componente->assertSee('TUNJA')->assertSee('FERRETERIA NUEVA');

        $this->assertSame($antes, [Cliente::count(), AsesorSap::count(), Canal::count(), Bitacora::count()]);
        $this->assertSame(0, Importacion::count());
    }

    public function test_el_archivo_subido_no_se_queda_guardado(): void
    {
        // En pruebas Livewire guarda las subidas temporales en este disco falso.
        Storage::fake('tmp-for-tests');

        $this->cargar()->assertSet('archivo', null);

        // Queda solo la ficha .json que Livewire deja junto a cada subida (la
        // limpia el mismo Livewire a las 24 horas); el contenido ya no esta.
        $this->assertSame([], array_values(array_filter(
            Storage::disk('tmp-for-tests')->allFiles(),
            fn ($ruta) => ! str_ends_with($ruta, '.json'),
        )));
    }

    public function test_carga_solo_las_filas_elegidas(): void
    {
        $this->cargar()
            ->set('seleccion', ['3'])
            ->call('cargar')
            ->assertSet('error', '');

        $this->assertSame('TUNJA', Cliente::where('codigo_sn', 'CN0491')->value('ciudad'));
        $this->assertFalse(Cliente::where('codigo_sn', 'CN0999')->exists());

        $importacion = Importacion::firstOrFail();
        $this->assertSame($this->admin->id, $importacion->usuario_id);
        $this->assertSame('CLIENTES', $importacion->tipo);
        $this->assertSame(1, $importacion->actualizados);
        $this->assertSame(0, $importacion->creados);
        $this->assertTrue(Bitacora::where('accion', 'CARGAR_MAESTROS')->exists());
        $this->assertTrue(Bitacora::where('accion', 'EDITAR_CLIENTE')->exists());
    }

    public function test_seleccionar_todos_los_validos_y_cargar(): void
    {
        $componente = $this->cargar()->call('seleccionarValidos');

        $this->assertEqualsCanonicalizing(['3', '4'], array_map('strval', $componente->get('seleccion')));

        $componente->call('cargar')->assertSee('1 creado');

        $nuevo = Cliente::where('codigo_sn', 'CN0999')->firstOrFail();
        $this->assertSame('Ferreteria', $nuevo->canal->nombre);
        $this->assertSame('30 ASESOR NUEVO', $nuevo->asesorSap->codigo_texto);
        $this->assertSame('ASESOR NUEVO', $nuevo->asesorSap->nombre);
        $this->assertEquals(10, (float) $nuevo->porcentaje_descuento);
        $this->assertFalse(Cliente::where('codigo_sn', 'CN0998')->exists());
    }

    public function test_una_celda_vacia_no_borra_el_dato(): void
    {
        $this->cargar()->set('seleccion', ['3'])->call('cargar');

        $cliente = Cliente::where('codigo_sn', 'CN0491')->firstOrFail();
        $this->assertNotNull($cliente->canal_id);
        $this->assertNotNull($cliente->asesor_sap_id);
        $this->assertSame('PUERTAS METALICAS COLOMBIANAS SAS', $cliente->nombre);
    }

    public function test_las_filas_con_error_o_iguales_no_se_cargan_aunque_se_pidan(): void
    {
        $this->cargar()->set('seleccion', ['2', '5'])->call('cargar');

        $this->assertFalse(Cliente::where('codigo_sn', 'CN0998')->exists());
        $this->assertSame(0, Importacion::count());
    }

    public function test_al_cargar_se_vuelve_a_validar(): void
    {
        $componente = $this->cargar();

        // Entre la previsualizacion y la carga alguien crea ese codigo a mano.
        Cliente::create(['codigo_sn' => 'CN0999', 'nombre' => 'CREADO A MANO']);

        // Lo que se reviso era un cliente nuevo; ahora seria pisar uno que ya
        // existe. No se carga: el admin tiene que volver a revisar.
        $componente->set('seleccion', ['4'])->call('cargar')->assertSee('cambio desde la revision');

        $cliente = Cliente::where('codigo_sn', 'CN0999')->sole();
        $this->assertSame('CREADO A MANO', $cliente->nombre);
        $this->assertNull($cliente->asesor_sap_id);
        $this->assertSame(1, Importacion::firstOrFail()->errores);
    }

    public function test_productos_desde_excel(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'carga').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($ruta);
        $writer->addRow(Row::fromValues(['Código', 'Descripción', 'Familia', 'Precio lista']));
        $writer->addRow(Row::fromValues(['CER-100', null, null, 70000]));
        $writer->addRow(Row::fromValues([2050, 'CANDADO', 'CANDADOS', 15500.5]));
        $writer->addRow(Row::fromValues(['CER-300', 'SIN PRECIO', null, null]));
        $writer->close();

        $componente = Livewire::actingAs($this->admin)->test('maestros-cargar')
            ->set('tipo', 'productos')
            ->set('archivo', UploadedFile::fake()->createWithContent('precios.xlsx', file_get_contents($ruta)));
        @unlink($ruta);

        $this->assertSame(['antes' => 64900.0, 'despues' => 70000.0], $this->fila($componente, 2)['cambios']['precio']);
        $this->assertSame('NUEVO', $this->fila($componente, 3)['estado']);
        $this->assertSame('ERROR', $this->fila($componente, 4)['estado']);

        $componente->call('seleccionarValidos')->call('cargar');

        $this->assertEquals(70000, (float) Producto::where('codigo', 'CER-100')->value('precio_lista'));
        $this->assertSame('CERRADURA', Producto::where('codigo', 'CER-100')->value('descripcion'));
        $this->assertEquals(15500.5, (float) Producto::where('codigo', '2050')->value('precio_lista'));
        $this->assertSame('PRECIOS', Importacion::firstOrFail()->tipo);
    }

    public function test_un_archivo_sin_columna_de_codigo_muestra_el_error(): void
    {
        Livewire::actingAs($this->admin)->test('maestros-cargar')
            ->set('archivo', UploadedFile::fake()->createWithContent('x.csv', "Nombre,Ciudad\nA,B\n"))
            ->assertSee('No encuentro la columna del codigo')
            ->assertSet('filas', []);
    }

    public function test_un_archivo_de_otro_tipo_se_rechaza(): void
    {
        Livewire::actingAs($this->admin)->test('maestros-cargar')
            ->set('archivo', UploadedFile::fake()->create('foto.pdf', 10))
            ->assertHasErrors('archivo');
    }

    public function test_descarga_la_plantilla(): void
    {
        Livewire::actingAs($this->admin)->test('maestros-cargar')
            ->set('tipo', 'productos')
            ->call('descargarPlantilla')
            ->assertFileDownloaded('plantilla-productos.xlsx');
    }
}
