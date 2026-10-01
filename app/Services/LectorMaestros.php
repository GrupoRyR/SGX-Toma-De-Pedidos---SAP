<?php

namespace App\Services;

use App\Support\FormatoMaestros;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Throwable;

/**
 * Lee un archivo de maestros (.xlsx o .csv) y lo devuelve fila por fila, con
 * cada valor ya bajo el nombre de su campo.
 *
 * Solo lee: no toca la base de datos. Decidir que es nuevo, que cambia y que
 * esta mal es trabajo de quien lo llama.
 *
 * Los campos numericos (descuento, precio) salen como float. Si una celda de
 * esos campos no se puede leer como numero, sale el texto tal cual, para que
 * la previsualizacion la marque como error en vez de cargar un cero.
 */
class LectorMaestros
{
    /** Tope de filas: la previsualizacion viaja entera al navegador. */
    public const MAXIMO_FILAS = 5000;

    private const NUMERICOS = ['descuento', 'precio'];

    private const OBLIGATORIAS = ['clientes' => 'codigo_sn', 'productos' => 'codigo'];

    /** Encabezados de la plantilla descargable; todos los reconoce el alias. */
    private const PLANTILLAS = [
        'clientes' => ['Código SN', 'Nombre', 'Dirección', 'Ciudad', 'Canal', 'Asesor', '% Descuento'],
        'productos' => ['Código', 'Descripción', 'Familia', 'Precio lista'],
    ];

    /**
     * @return array{columnas: list<string>, filas: list<array{fila: int, valores: array<string, float|string|null>}>}
     */
    public function leer(string $ruta, string $extension, string $tipo): array
    {
        $alias = $this->alias($tipo);
        $registros = match (strtolower($extension)) {
            'xlsx' => $this->registrosXlsx($ruta),
            'csv', 'txt' => $this->registrosCsv($ruta),
            default => throw new RuntimeException('Solo se aceptan archivos .xlsx o .csv.'),
        };

        $indices = null;
        $filas = [];

        foreach ($registros as $numero => $celdas) {
            if (FormatoMaestros::filaVacia($celdas)) {
                continue;
            }

            // La primera fila con algo escrito es el encabezado.
            if ($indices === null) {
                $indices = FormatoMaestros::mapearColumnas(array_map(fn ($c) => (string) $this->texto($c), $celdas), $alias);
                $this->exigirColumnas($tipo, $indices, $celdas);

                continue;
            }

            if (count($filas) >= self::MAXIMO_FILAS) {
                throw new RuntimeException('El archivo tiene mas de '.self::MAXIMO_FILAS.' filas. Dividelo en partes.');
            }

            $valores = [];
            foreach ($indices as $campo => $i) {
                $celda = $celdas[$i] ?? null;
                $valores[$campo] = in_array($campo, self::NUMERICOS, true) ? $this->numero($celda) : $this->texto($celda);
            }

            $filas[] = ['fila' => $numero, 'valores' => $valores];
        }

        if ($indices === null) {
            throw new RuntimeException('El archivo esta vacio.');
        }

        return ['columnas' => array_keys($indices), 'filas' => $filas];
    }

    /** Escribe la plantilla vacia de un tipo: solo la fila de encabezados. */
    public function escribirPlantilla(string $tipo, string $ruta): void
    {
        $this->alias($tipo);

        $writer = new Writer;
        $writer->openToFile($ruta);
        $writer->addRow(Row::fromValues(self::PLANTILLAS[$tipo]));
        $writer->close();
    }

    private function alias(string $tipo): array
    {
        return match ($tipo) {
            'clientes' => FormatoMaestros::ALIAS_CLIENTES,
            'productos' => FormatoMaestros::ALIAS_PRODUCTOS,
            default => throw new RuntimeException("Tipo de archivo desconocido: {$tipo}."),
        };
    }

    private function exigirColumnas(string $tipo, array $indices, array $encabezado): void
    {
        if (isset($indices[self::OBLIGATORIAS[$tipo]])) {
            return;
        }

        $leido = implode(' | ', array_filter(array_map(fn ($c) => (string) $this->texto($c), $encabezado)));

        throw new RuntimeException(
            'No encuentro la columna del codigo. Usa la plantilla o revisa el encabezado. Encabezado leido: '.$leido
        );
    }

    /**
     * Filas de la primera hoja del Excel, con el numero de fila de Excel.
     *
     * Se conservan las filas vacias solo para que el numero coincida con lo
     * que el admin ve en Excel; despues se saltan.
     *
     * @return iterable<int, array<int, mixed>>
     */
    private function registrosXlsx(string $ruta): iterable
    {
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));

        try {
            $reader->open($ruta);
        } catch (Throwable) {
            throw new RuntimeException('No pude abrir el archivo como Excel (.xlsx).');
        }

        try {
            foreach ($reader->getSheetIterator() as $hoja) {
                $numero = 0;
                foreach ($hoja->getRowIterator() as $fila) {
                    $numero++;
                    yield $numero => array_map(fn (Cell $c) => $this->valorDeCelda($c), $fila->cells);
                }

                // Solo la primera hoja.
                break;
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('El archivo de Excel esta danado o no se puede leer.');
        } finally {
            $reader->close();
        }
    }

    private function valorDeCelda(Cell $celda): mixed
    {
        // De una formula interesa el resultado, no "=B2*1.19".
        if ($celda instanceof Cell\FormulaCell) {
            return $celda->getComputedValue();
        }

        return $celda->getValue();
    }

    /**
     * Filas de un CSV, detectando separador y codificacion.
     *
     * Excel en Windows guarda el CSV en Windows-1252 y con punto y coma cuando
     * la configuracion regional es de Colombia; el export de SharePoint viene
     * en UTF-8 con comas. Se aceptan los dos sin preguntarle nada al admin.
     *
     * @return iterable<int, array<int, string|null>>
     */
    private function registrosCsv(string $ruta): iterable
    {
        $contenido = @file_get_contents($ruta);

        if ($contenido === false) {
            throw new RuntimeException('No pude leer el archivo.');
        }

        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $separador = $this->separador($contenido);

        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $numero = 0;
        while (($fila = fgetcsv($flujo, 0, $separador, '"', '')) !== false) {
            $numero++;
            yield $numero => $fila;
        }

        fclose($flujo);
    }

    /** Punto y coma o coma: gana el que mas aparezca en el encabezado, fuera de comillas. */
    private function separador(string $contenido): string
    {
        $linea = strtok(ltrim($contenido, "\r\n"), "\n") ?: '';
        $sinComillas = preg_replace('/"[^"]*"/', '', $linea);

        return substr_count($sinComillas, ';') > substr_count($sinComillas, ',') ? ';' : ',';
    }

    /**
     * Texto de una celda, recortado; vacio es null.
     *
     * Excel guarda un codigo como 1234 en una celda numerica y lo entrega como
     * 1234.0: ese ".0" no es parte del codigo de SAP.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
            return (string) (int) $valor;
        }

        if ($valor instanceof DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_array($valor)) {
            $valor = implode('', array_map(fn ($t) => $t instanceof TextRun ? $t->text : '', $valor));
        }

        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Numero de una celda.
     *
     * Si ya es numero (Excel) se usa tal cual. Si es texto (CSV) pasa por la
     * regla de miles del export. Un texto sin ninguna cifra se devuelve tal
     * cual para que quede marcado como error.
     */
    private function numero(mixed $valor): float|string|null
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $texto = $this->texto($valor);

        if ($texto === null) {
            return null;
        }

        if (! preg_match('/\d/', $texto)) {
            return $texto;
        }

        return FormatoMaestros::numero($texto);
    }
}
