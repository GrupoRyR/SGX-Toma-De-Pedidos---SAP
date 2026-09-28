<?php

namespace App\Console\Commands;

use App\Models\Importacion;
use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa LISTA_PRECIOS desde el CSV exportado de SharePoint.
 *
 * Upsert por codigo. Igual que con clientes, no borra lo que no venga en el
 * archivo: una exportacion parcial no puede vaciar la lista de precios.
 *
 *   php artisan pedidos:importar-precios ruta/LISTA_PRECIOS.csv [--simular]
 */
class ImportarPrecios extends Command
{
    protected $signature = 'pedidos:importar-precios
                            {archivo : Ruta del CSV exportado de SharePoint}
                            {--simular : Muestra lo que haria sin escribir nada}
                            {--separador=, : Separador de columnas}';

    protected $description = 'Importa o actualiza la lista de precios desde el CSV de LISTA_PRECIOS';

    private array $columnas = [
        'codigo' => ['codigo', 'itemcode', 'codigoproducto', 'referencia'],
        // En el export de SharePoint la descripcion viene en la columna Title.
        'descripcion' => ['descripcion', 'titulo', 'itemname', 'nombre', 'producto'],
        'familia' => ['familia', 'linea', 'grupo', 'categoria'],
        'precio' => ['preciolist', 'preciolista', 'precio', 'precioventa', 'price', 'valor'],
    ];

    public function handle(): int
    {
        $ruta = $this->argument('archivo');

        if (! is_readable($ruta)) {
            $this->error("No puedo leer el archivo: {$ruta}");

            return self::FAILURE;
        }

        $simular = (bool) $this->option('simular');
        $separador = $this->option('separador');

        $manejador = $this->abrir($ruta);
        $encabezado = fgetcsv($manejador, 0, $separador);

        if (! $encabezado) {
            $this->error('El archivo esta vacio o no tiene encabezado.');

            return self::FAILURE;
        }

        $indices = $this->mapearColumnas($encabezado);

        if (! isset($indices['codigo'], $indices['descripcion'], $indices['precio'])) {
            $this->error('Faltan columnas obligatorias: codigo, descripcion y precio.');
            $this->line('Encabezado leido: '.implode(' | ', $encabezado));

            return self::FAILURE;
        }

        $resumen = ['leidas' => 0, 'creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];
        $errores = [];
        $cambiosDePrecio = [];

        $procesar = function () use ($manejador, $separador, $indices, &$resumen, &$errores, &$cambiosDePrecio) {
            while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
                if ($this->filaVacia($fila)) {
                    continue;
                }

                $resumen['leidas']++;
                $codigo = trim((string) ($fila[$indices['codigo']] ?? ''));
                $descripcion = trim((string) ($fila[$indices['descripcion']] ?? ''));
                $precio = $this->numero((string) ($fila[$indices['precio']] ?? ''));

                if ($codigo === '' || $descripcion === '') {
                    $resumen['errores']++;
                    $errores[] = ['fila' => $resumen['leidas'], 'motivo' => 'codigo o descripcion vacio'];

                    continue;
                }

                if ($precio <= 0) {
                    $resumen['errores']++;
                    $errores[] = ['fila' => $resumen['leidas'], 'motivo' => "precio invalido para {$codigo}"];

                    continue;
                }

                $producto = Producto::firstOrNew(['codigo' => $codigo]);
                $precioAnterior = $producto->exists ? (float) $producto->precio_lista : null;

                $producto->fill([
                    'descripcion' => $descripcion,
                    'familia' => isset($indices['familia']) ? (trim((string) ($fila[$indices['familia']] ?? '')) ?: null) : $producto->familia,
                    'precio_lista' => $precio,
                    'activo' => true,
                ]);

                if (! $producto->exists) {
                    $producto->save();
                    $resumen['creados']++;

                    continue;
                }

                if ($producto->isDirty()) {
                    if ($precioAnterior !== null && abs($precioAnterior - $precio) > 0.009) {
                        $cambiosDePrecio[] = [$codigo, $precioAnterior, $precio];
                    }

                    $producto->save();
                    $resumen['actualizados']++;
                } else {
                    $resumen['sin_cambios']++;
                }
            }
        };

        if ($simular) {
            DB::beginTransaction();
            $procesar();
            DB::rollBack();
        } else {
            DB::transaction($procesar);
        }

        fclose($manejador);

        $this->table(
            ['Leidas', 'Creados', 'Actualizados', 'Sin cambios', 'Errores'],
            [[$resumen['leidas'], $resumen['creados'], $resumen['actualizados'], $resumen['sin_cambios'], $resumen['errores']]],
        );

        // Un cambio de precio inesperado es lo mas caro que puede pasar aqui:
        // se listan siempre para que alguien los mire antes de dar por buena la carga.
        if ($cambiosDePrecio) {
            $this->warn('Precios que cambiaron ('.count($cambiosDePrecio).'):');
            $this->table(['Codigo', 'Antes', 'Ahora'], array_slice($cambiosDePrecio, 0, 50));

            if (count($cambiosDePrecio) > 50) {
                $this->line('  ... y '.(count($cambiosDePrecio) - 50).' mas.');
            }
        }

        foreach (array_slice($errores, 0, 20) as $error) {
            $this->warn("  fila {$error['fila']}: {$error['motivo']}");
        }

        if ($simular) {
            $this->warn('SIMULACION: no se escribio nada en la base de datos.');

            return self::SUCCESS;
        }

        Importacion::create([
            'tipo' => 'PRECIOS',
            'archivo' => basename($ruta),
            'filas_leidas' => $resumen['leidas'],
            'creados' => $resumen['creados'],
            'actualizados' => $resumen['actualizados'],
            'sin_cambios' => $resumen['sin_cambios'],
            'errores' => $resumen['errores'],
            'detalle_errores' => array_slice($errores, 0, 200),
            'estado' => 'COMPLETADA',
        ]);

        return self::SUCCESS;
    }

    /**
     * Abre el CSV saltando el BOM si lo trae.
     *
     * Hay que quitarlo ANTES de leer, no despues: con el BOM pegado delante,
     * fgetcsv no reconoce la comilla de apertura y devuelve la primera columna
     * con las comillas incrustadas ("CODIGO" en vez de CODIGO).
     *
     * @return resource
     */
    private function abrir(string $ruta)
    {
        $manejador = fopen($ruta, 'r');

        if (fread($manejador, 3) !== "\xEF\xBB\xBF") {
            rewind($manejador);
        }

        return $manejador;
    }

    /**
     * Normaliza un titulo de columna para poder compararlo.
     *
     * Quita acentos primero: sin eso, un encabezado como "CODIGO" con tilde
     * pierde la letra entera al filtrar por [^a-z0-9] y queda "cdigo", que no
     * coincide con ningun alias.
     */
    private function clave(string $titulo): string
    {
        $sinAcentos = strtr(mb_strtolower(trim($titulo), 'UTF-8'), [
            "\u{E1}" => 'a', "\u{E9}" => 'e', "\u{ED}" => 'i', "\u{F3}" => 'o', "\u{FA}" => 'u',
            "\u{E0}" => 'a', "\u{E8}" => 'e', "\u{EC}" => 'i', "\u{F2}" => 'o', "\u{F9}" => 'u',
            "\u{E4}" => 'a', "\u{EB}" => 'e', "\u{EF}" => 'i', "\u{F6}" => 'o', "\u{FC}" => 'u',
            "\u{F1}" => 'n', "\u{E7}" => 'c',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $sinAcentos);
    }

    /** El export de SharePoint suele cerrar con una fila de comas sueltas. */
    private function filaVacia(array $fila): bool
    {
        foreach ($fila as $celda) {
            if (trim((string) $celda) !== '') {
                return false;
            }
        }

        return true;
    }

    private function mapearColumnas(array $encabezado): array
    {
        $normalizado = [];
        foreach ($encabezado as $i => $titulo) {
            $normalizado[$this->clave((string) $titulo)] = $i;
        }

        $indices = [];
        foreach ($this->columnas as $campo => $alias) {
            foreach ($alias as $nombre) {
                if (isset($normalizado[$nombre])) {
                    $indices[$campo] = $normalizado[$nombre];
                    break;
                }
            }
        }

        return $indices;
    }

    /**
     * Convierte el texto de un precio a float respetando el formato del export.
     *
     * OJO: en LISTA_PRECIOS "64,900" son sesenta y cuatro mil novecientos pesos,
     * NO sesenta y cuatro con nueve. La coma separa miles. Tratarla como decimal
     * dividiria todos los precios por mil y el pedido saldria regalado.
     *
     * La regla: el separador decimal es el ultimo simbolo que aparezca, y solo
     * si le siguen una o dos cifras. Con tres cifras detras, es separador de
     * miles.
     */
    private function numero(string $valor): float
    {
        $limpio = preg_replace('/[^0-9,.\-]/', '', $valor);

        if ($limpio === '' || $limpio === null) {
            return 0.0;
        }

        $posComa = strrpos($limpio, ',');
        $posPunto = strrpos($limpio, '.');
        $ultimo = max($posComa === false ? -1 : $posComa, $posPunto === false ? -1 : $posPunto);

        $decimal = false;
        if ($ultimo >= 0) {
            $cifrasDetras = strlen($limpio) - $ultimo - 1;
            if ($cifrasDetras >= 1 && $cifrasDetras <= 2) {
                $decimal = $ultimo;
            }
        }

        if ($decimal === false) {
            return (float) str_replace([',', '.'], '', $limpio);
        }

        $entero = str_replace([',', '.'], '', substr($limpio, 0, $decimal));

        return (float) (($entero === '' ? '0' : $entero).'.'.substr($limpio, $decimal + 1));
    }
}
