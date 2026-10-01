<?php

namespace App\Console\Commands;

use App\Models\Importacion;
use App\Models\Producto;
use App\Support\FormatoMaestros;
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

    public function handle(): int
    {
        $ruta = $this->argument('archivo');

        if (! is_readable($ruta)) {
            $this->error("No puedo leer el archivo: {$ruta}");

            return self::FAILURE;
        }

        $simular = (bool) $this->option('simular');
        $separador = $this->option('separador');

        $manejador = FormatoMaestros::abrirCsv($ruta);
        $encabezado = fgetcsv($manejador, 0, $separador);

        if (! $encabezado) {
            $this->error('El archivo esta vacio o no tiene encabezado.');

            return self::FAILURE;
        }

        $indices = FormatoMaestros::mapearColumnas($encabezado, FormatoMaestros::ALIAS_PRODUCTOS);

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
                if (FormatoMaestros::filaVacia($fila)) {
                    continue;
                }

                $resumen['leidas']++;
                $codigo = trim((string) ($fila[$indices['codigo']] ?? ''));
                $descripcion = trim((string) ($fila[$indices['descripcion']] ?? ''));
                $precio = FormatoMaestros::numero((string) ($fila[$indices['precio']] ?? ''));

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
}
