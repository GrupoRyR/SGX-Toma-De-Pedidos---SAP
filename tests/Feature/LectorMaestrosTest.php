<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use App\Models\Cliente;
use App\Models\Producto;
use App\Services\LectorMaestros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Tests\TestCase;

/**
 * Lectura de archivos de maestros (.xlsx y .csv) y los comandos de consola
 * que comparten la misma normalizacion.
 *
 * Lo delicado: los precios de SharePoint vienen con la coma como separador de
 * miles ("64,900"), pero un Excel ya trae el numero como numero y no se le
 * puede aplicar esa regla encima.
 */
class LectorMaestrosTest extends TestCase
{
    use RefreshDatabase;

    private LectorMaestros $lector;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->lector = app(LectorMaestros::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    private function archivo(string $contenido, string $extension): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'maestros').'.'.$extension;
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    private function xlsx(array $filas): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'maestros').'.xlsx';
        $this->temporales[] = $ruta;

        $writer = new Writer;
        $writer->openToFile($ruta);
        foreach ($filas as $fila) {
            $writer->addRow(Row::fromValues($fila));
        }
        $writer->close();

        return $ruta;
    }

    // ---------- CSV ----------

    public function test_lee_csv_con_bom_comas_y_encabezados_con_tilde(): void
    {
        $csv = "\xEF\xBB\xBF\"Código SN\",\"Nombre cliente\",\"Título\",\"Canal\",\"% Descuento\",\"Ciudad\"\n"
            ."CN0507,\"RIVERA TUTA, JOSE\",14 MONICA RIVERA AREVALO,DISTRIBUCION,\"28,5\",\n"
            .",,,,,\n";

        $leido = $this->lector->leer($this->archivo($csv, 'csv'), 'csv', 'clientes');

        $this->assertCount(1, $leido['filas']);
        $fila = $leido['filas'][0];
        $this->assertSame(2, $fila['fila']);
        $this->assertSame('CN0507', $fila['valores']['codigo_sn']);
        $this->assertSame('RIVERA TUTA, JOSE', $fila['valores']['nombre']);
        $this->assertSame('14 MONICA RIVERA AREVALO', $fila['valores']['asesor']);
        $this->assertSame(28.5, $fila['valores']['descuento']);
        $this->assertNull($fila['valores']['ciudad']);
    }

    public function test_lee_csv_con_punto_y_coma_en_windows_1252(): void
    {
        $csv = mb_convert_encoding("Código;Descripción;Precio lista\nCER-100;CERRADURA AÑO;64,900\n", 'Windows-1252', 'UTF-8');

        $leido = $this->lector->leer($this->archivo($csv, 'csv'), 'csv', 'productos');

        $valores = $leido['filas'][0]['valores'];
        $this->assertSame('CER-100', $valores['codigo']);
        $this->assertSame('CERRADURA AÑO', $valores['descripcion']);
        // En el export la coma separa miles: sesenta y cuatro mil novecientos.
        $this->assertSame(64900.0, $valores['precio']);
    }

    public function test_un_numero_ilegible_queda_como_texto_para_marcarlo(): void
    {
        $csv = "Codigo,Descripcion,Precio\nCER-100,CERRADURA,N/A\n";

        $leido = $this->lector->leer($this->archivo($csv, 'csv'), 'csv', 'productos');

        $this->assertSame('N/A', $leido['filas'][0]['valores']['precio']);
    }

    public function test_sin_columna_de_codigo_explica_que_falta(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('codigo');

        $this->lector->leer($this->archivo("Nombre,Ciudad\nALGUIEN,TUNJA\n", 'csv'), 'csv', 'clientes');
    }

    // ---------- XLSX ----------

    public function test_lee_xlsx_con_numeros_de_verdad(): void
    {
        $ruta = $this->xlsx([
            ['Código', 'Descripción', 'Familia', 'Precio lista'],
            [1234.0, 'CANDADO', 'CANDADOS', 12.345],
            [],
            ['CER-100', 'CERRADURA', null, 64900],
        ]);

        $leido = $this->lector->leer($ruta, 'xlsx', 'productos');

        $this->assertCount(2, $leido['filas']);
        // Excel guarda el codigo 1234 como numero: tiene que volver como "1234".
        $this->assertSame('1234', $leido['filas'][0]['valores']['codigo']);
        // Un numero de Excel no pasa por la regla de miles del CSV.
        $this->assertSame(12.345, $leido['filas'][0]['valores']['precio']);
        $this->assertSame(64900.0, $leido['filas'][1]['valores']['precio']);
        $this->assertNull($leido['filas'][1]['valores']['familia']);
        // El numero de fila es el de Excel, contando la fila vacia.
        $this->assertSame(2, $leido['filas'][0]['fila']);
        $this->assertSame(4, $leido['filas'][1]['fila']);
    }

    public function test_un_archivo_que_no_es_excel_se_rechaza(): void
    {
        $this->expectException(RuntimeException::class);

        $this->lector->leer($this->archivo('esto no es un zip', 'xlsx'), 'xlsx', 'productos');
    }

    public function test_la_plantilla_se_lee_con_las_columnas_esperadas(): void
    {
        foreach (['clientes' => ['codigo_sn', 'nombre', 'asesor', 'descuento'], 'productos' => ['codigo', 'descripcion', 'precio']] as $tipo => $esperadas) {
            $ruta = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
            $this->temporales[] = $ruta;

            $this->lector->escribirPlantilla($tipo, $ruta);
            $leido = $this->lector->leer($ruta, 'xlsx', $tipo);

            $this->assertSame([], $leido['filas']);
            foreach ($esperadas as $campo) {
                $this->assertContains($campo, $leido['columnas']);
            }
        }
    }

    // ---------- Los comandos de consola siguen igual ----------

    public function test_el_comando_de_clientes_sigue_leyendo_el_export_de_sharepoint(): void
    {
        $csv = "\xEF\xBB\xBF\"Código SN\",\"Nombre cliente\",\"Título\",\"Canal\",\"Descuento\"\n"
            ."CN0507,RIVERA TUTA JOSE,14 MONICA RIVERA AREVALO,DISTRIBUCION,28\n,,,,\n";

        $this->artisan('pedidos:importar-clientes', ['archivo' => $this->archivo($csv, 'csv')])->assertSuccessful();

        $cliente = Cliente::where('codigo_sn', 'CN0507')->firstOrFail();
        $this->assertEquals(28, (float) $cliente->porcentaje_descuento);
        $this->assertSame('Distribucion', $cliente->canal->nombre);
        $this->assertSame('MONICA RIVERA AREVALO', AsesorSap::where('codigo_texto', '14 MONICA RIVERA AREVALO')->value('nombre'));
    }

    public function test_el_comando_de_precios_sigue_leyendo_la_coma_de_miles(): void
    {
        $csv = "Código,Título,Precio lista\nCER-100,CERRADURA,\"64,900\"\nCER-101,CANDADO,\"1.250,50\"\n";

        $this->artisan('pedidos:importar-precios', ['archivo' => $this->archivo($csv, 'csv')])->assertSuccessful();

        $this->assertEquals(64900, (float) Producto::where('codigo', 'CER-100')->value('precio_lista'));
        $this->assertEquals(1250.5, (float) Producto::where('codigo', 'CER-101')->value('precio_lista'));
    }
}
