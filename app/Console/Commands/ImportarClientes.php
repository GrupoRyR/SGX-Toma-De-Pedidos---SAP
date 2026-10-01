<?php

namespace App\Console\Commands;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Importacion;
use App\Support\FormatoMaestros;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa CLIENTES_SAP desde el CSV exportado de SharePoint.
 *
 * Hace upsert por codigo_sn: nunca borra clientes que no vengan en el archivo,
 * porque una exportacion parcial no debe vaciar la cartera de nadie.
 *
 * Crea sobre la marcha los asesores SAP que encuentre en el campo asesor. Ese
 * texto es la llave real de visibilidad, asi que se guarda tal cual viene.
 *
 *   php artisan pedidos:importar-clientes ruta/CLIENTES_SAP.csv
 *   php artisan pedidos:importar-clientes ruta/CLIENTES_SAP.csv --simular
 */
class ImportarClientes extends Command
{
    protected $signature = 'pedidos:importar-clientes
                            {archivo : Ruta del CSV exportado de SharePoint}
                            {--simular : Muestra lo que haria sin escribir nada}
                            {--separador=, : Separador de columnas}';

    protected $description = 'Importa o actualiza clientes desde el CSV de CLIENTES_SAP';

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

        $indices = FormatoMaestros::mapearColumnas($encabezado, FormatoMaestros::ALIAS_CLIENTES);

        if (! isset($indices['codigo_sn'], $indices['nombre'])) {
            $this->error('Faltan columnas obligatorias: codigo SN y nombre.');
            $this->line('Encabezado leido: '.implode(' | ', $encabezado));

            return self::FAILURE;
        }

        $resumen = ['leidas' => 0, 'creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];
        $errores = [];
        $asesoresNuevos = [];

        $procesar = function () use ($manejador, $separador, $indices, &$resumen, &$errores, &$asesoresNuevos) {
            while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
                if (FormatoMaestros::filaVacia($fila)) {
                    continue;
                }

                $resumen['leidas']++;
                $codigo = trim((string) ($fila[$indices['codigo_sn']] ?? ''));
                $nombre = trim((string) ($fila[$indices['nombre']] ?? ''));

                if ($codigo === '' || $nombre === '') {
                    $resumen['errores']++;
                    $errores[] = ['fila' => $resumen['leidas'], 'motivo' => 'codigo o nombre vacio'];

                    continue;
                }

                $asesorId = null;
                $textoAsesor = trim((string) ($fila[$indices['asesor']] ?? ''));

                if ($textoAsesor !== '') {
                    $asesor = AsesorSap::firstOrCreate(
                        ['codigo_texto' => $textoAsesor],
                        ['nombre' => FormatoMaestros::nombreDelAsesor($textoAsesor), 'activo' => true],
                    );

                    if ($asesor->wasRecentlyCreated) {
                        $asesoresNuevos[] = $textoAsesor;
                    }

                    $asesorId = $asesor->id;
                }

                $canalId = null;
                $textoCanal = $this->celda($fila, $indices, 'canal');
                if ($textoCanal !== null) {
                    $canalId = Canal::firstOrCreate(
                        ['nombre' => Str::title(Str::lower($textoCanal))],
                        ['activo' => true],
                    )->id;
                }

                $datos = [
                    'nombre' => $nombre,
                    'canal_id' => $canalId,
                    'direccion' => $this->celda($fila, $indices, 'direccion'),
                    'ciudad' => $this->celda($fila, $indices, 'ciudad'),
                    'asesor_sap_id' => $asesorId,
                    'porcentaje_descuento' => FormatoMaestros::numero($this->celda($fila, $indices, 'descuento')),
                    'activo' => true,
                ];

                $cliente = Cliente::firstOrNew(['codigo_sn' => $codigo]);

                if (! $cliente->exists) {
                    $cliente->fill($datos)->save();
                    $resumen['creados']++;

                    continue;
                }

                $cliente->fill($datos);

                if ($cliente->isDirty()) {
                    $cliente->save();
                    $resumen['actualizados']++;
                } else {
                    $resumen['sin_cambios']++;
                }
            }
        };

        if ($simular) {
            // La transaccion se revierte: sirve para ver el impacto sin escribir.
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

        if ($asesoresNuevos) {
            $this->info('Asesores SAP detectados en el archivo: '.count(array_unique($asesoresNuevos)));
            foreach (array_unique($asesoresNuevos) as $texto) {
                $this->line('  - '.$texto);
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
            'tipo' => 'CLIENTES',
            'archivo' => basename($ruta),
            'filas_leidas' => $resumen['leidas'],
            'creados' => $resumen['creados'],
            'actualizados' => $resumen['actualizados'],
            'sin_cambios' => $resumen['sin_cambios'],
            'errores' => $resumen['errores'],
            'detalle_errores' => array_slice($errores, 0, 200),
            'estado' => 'COMPLETADA',
        ]);

        $this->info('Importacion registrada. Ahora corre: php artisan db:seed --class=AsignacionesSeeder');

        return self::SUCCESS;
    }

    private function celda(array $fila, array $indices, string $campo): ?string
    {
        if (! isset($indices[$campo])) {
            return null;
        }

        $valor = trim((string) ($fila[$indices[$campo]] ?? ''));

        return $valor === '' ? null : $valor;
    }
}
