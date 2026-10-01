<?php

namespace App\Support;

/**
 * Como se leen los archivos de maestros: nombres de columna y numeros.
 *
 * Lo usan los comandos de consola y la carga desde la web. Antes cada comando
 * tenia su propia copia y cualquier arreglo habia que hacerlo dos veces.
 */
final class FormatoMaestros
{
    /** Nombres posibles de cada columna de clientes, ya normalizados con clave(). */
    public const ALIAS_CLIENTES = [
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

    /** Nombres posibles de cada columna de la lista de precios. */
    public const ALIAS_PRODUCTOS = [
        'codigo' => ['codigo', 'itemcode', 'codigoproducto', 'referencia'],
        // En el export de SharePoint la descripcion viene en la columna Title.
        'descripcion' => ['descripcion', 'titulo', 'itemname', 'nombre', 'producto'],
        'familia' => ['familia', 'linea', 'grupo', 'categoria'],
        'precio' => ['preciolist', 'preciolista', 'precio', 'precioventa', 'price', 'valor'],
    ];

    /**
     * Abre el CSV saltando el BOM si lo trae.
     *
     * Hay que quitarlo ANTES de leer, no despues: con el BOM pegado delante,
     * fgetcsv no reconoce la comilla de apertura y devuelve la primera columna
     * con las comillas incrustadas ("CODIGO" en vez de CODIGO).
     *
     * @return resource
     */
    public static function abrirCsv(string $ruta)
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
    public static function clave(string $titulo): string
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
    public static function filaVacia(array $fila): bool
    {
        foreach ($fila as $celda) {
            if (is_array($celda) || trim((string) $celda) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * De la fila de encabezado saca en que posicion esta cada campo.
     *
     * @param  array<string, list<string>>  $alias
     * @return array<string, int> campo => indice de columna
     */
    public static function mapearColumnas(array $encabezado, array $alias): array
    {
        $normalizado = [];
        foreach ($encabezado as $i => $titulo) {
            $normalizado[self::clave((string) $titulo)] = $i;
        }

        $indices = [];
        foreach ($alias as $campo => $nombres) {
            foreach ($nombres as $nombre) {
                if (isset($normalizado[$nombre])) {
                    $indices[$campo] = $normalizado[$nombre];
                    break;
                }
            }
        }

        return $indices;
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
     *
     * Es solo para TEXTO. Un numero que ya viene como numero (una celda de
     * Excel) no pasa por aqui: 12.345 en Excel es doce con algo, no doce mil.
     */
    public static function numero(?string $valor): float
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
    public static function nombreDelAsesor(string $texto): string
    {
        return trim(preg_replace('/^\d+\s*/', '', $texto)) ?: $texto;
    }
}
