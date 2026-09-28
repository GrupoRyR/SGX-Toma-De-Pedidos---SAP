<?php

namespace App\Console\Commands;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Importacion;
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

    /** Nombres posibles de cada columna, en minusculas y sin espacios. */
    private array $columnas = [
        'codigo_sn' => ['codigosn', 'codigo_sn', 'cardcode', 'codigocliente'],
        'nombre' => ['nombrecliente', 'nombre', 'cardname', 'razonsocial'],
        'direccion' => ['direccion', 'address'],
        'ciudad' => ['ciudad', 'city'],
        // En el export de SharePoint el asesor viene en la columna Title
        // ("Titulo"), no en una columna llamada asesor.
        'asesor' => ['asesor', 'titulo', 'vendedor', 'slpname'],
        'canal' => ['canal'],
        'descuento' => ['descuento', 'porcentajedescuento', 'dedescuento', 'discount', 'pordescuento'],
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
                if ($this->filaVacia($fila)) {
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
                        ['nombre' => $this->nombreDelAsesor($textoAsesor), 'activo' => true],
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
                    'porcentaje_descuento' => $this->numero($this->celda($fila, $indices, 'descuento')),
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

    private function celda(array $fila, array $indices, string $campo): ?string
    {
        if (! isset($indices[$campo])) {
            return null;
        }

        $valor = trim((string) ($fila[$indices[$campo]] ?? ''));

        return $valor === '' ? null : $valor;
    }

    /**
     * Convierte el texto de un numero a float respetando el formato del export.
     *
     * OJO con el formato real de LISTA_PRECIOS: "64,900" son sesenta y cuatro mil
     * novecientos pesos, NO sesenta y cuatro con nueve. En ese archivo la coma
     * separa miles. Tratarla como decimal dividiria todos los precios por mil.
     *
     * La regla: el separador decimal es el ultimo simbolo que aparezca, y solo
     * si le siguen una o dos cifras. Con tres cifras detras, es separador de
     * miles.
     */
    private function numero(?string $valor): float
    {
        $limpio = preg_replace('/[^0-9,.\-]/', '', (string) $valor);

        if ($limpio === '' || $limpio === null) {
            return 0.0;
        }

        $negativo = str_starts_with($limpio, '-');
        $limpio = ltrim($limpio, '-');

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
            $resultado = (float) str_replace([',', '.'], '', $limpio);
        } else {
            $entero = str_replace([',', '.'], '', substr($limpio, 0, $decimal));
            $resultado = (float) (($entero === '' ? '0' : $entero).'.'.substr($limpio, $decimal + 1));
        }

        return $negativo ? -$resultado : $resultado;
    }

    /** De "14 MONICA RIVERA AREVALO" saca "MONICA RIVERA AREVALO". */
    private function nombreDelAsesor(string $texto): string
    {
        return trim(preg_replace('/^\d+\s*/', '', $texto)) ?: $texto;
    }
}
