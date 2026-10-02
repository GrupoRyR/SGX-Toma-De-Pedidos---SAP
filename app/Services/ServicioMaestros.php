<?php

namespace App\Services;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Importacion;
use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Altas y cambios de clientes y productos desde la web.
 *
 * Hasta ahora los maestros solo entraban por consola. Todo lo que los toca
 * desde la aplicacion pasa por aqui para que la validacion sea una sola y cada
 * cambio quede en la bitacora con quien lo hizo.
 *
 * No hay borrado: un cliente o producto que ya no va se desactiva, porque los
 * pedidos viejos lo siguen nombrando.
 */
class ServicioMaestros
{
    private const CAMPOS_CLIENTE = [
        'codigo_sn', 'nombre', 'direccion', 'ciudad', 'canal_id', 'asesor_sap_id', 'porcentaje_descuento', 'activo',
    ];

    private const CAMPOS_PRODUCTO = ['codigo', 'descripcion', 'familia', 'precio_lista', 'activo'];

    public function __construct(private Bitacorero $bitacora) {}

    // ---------- Clientes ----------

    public function crearCliente(array $datos): Cliente
    {
        $limpio = $this->validarCliente($datos + ['activo' => true], null);

        $cliente = Cliente::create($limpio);

        $this->bitacora->registrar('CREAR_CLIENTE', 'cliente', $cliente->id, [
            'codigo_sn' => $cliente->codigo_sn,
            'nombre' => $cliente->nombre,
        ]);

        return $cliente;
    }

    /**
     * Cambia solo los campos que vengan: lo que no se mande queda como esta.
     *
     * Asi lo puede usar tanto el formulario (que manda todo) como la carga por
     * archivo, donde una celda vacia no debe borrar el dato que ya habia.
     */
    public function actualizarCliente(Cliente $cliente, array $cambios): Cliente
    {
        $antes = $cliente->only(self::CAMPOS_CLIENTE);
        $limpio = $this->validarCliente(array_merge($antes, $cambios), $cliente->id);

        $cliente->fill($limpio);

        if (! $cliente->isDirty()) {
            return $cliente;
        }

        $cliente->save();

        $this->bitacora->registrarCambio('EDITAR_CLIENTE', 'cliente', $cliente->id,
            $antes, $cliente->refresh()->only(self::CAMPOS_CLIENTE));

        return $cliente;
    }

    private function validarCliente(array $datos, ?int $excepto): array
    {
        $codigo = $this->codigo($datos['codigo_sn'] ?? '');
        $this->exigirCodigoLibre(Cliente::class, 'codigo_sn', $codigo, $excepto, 'un cliente');

        $descuento = $this->numero($datos['porcentaje_descuento'] ?? 0, 'El descuento');

        if ($descuento < 0 || $descuento > 100) {
            throw new RuntimeException('El descuento tiene que estar entre 0 y 100.');
        }

        $canalId = $this->idOpcional($datos['canal_id'] ?? null);
        if ($canalId !== null && ! Canal::whereKey($canalId)->exists()) {
            throw new RuntimeException('El canal elegido no existe.');
        }

        $carteraId = $this->idOpcional($datos['asesor_sap_id'] ?? null);
        if ($carteraId !== null && ! AsesorSap::whereKey($carteraId)->exists()) {
            throw new RuntimeException('La cartera elegida no existe.');
        }

        return [
            'codigo_sn' => $codigo,
            'nombre' => $this->obligatorio($datos['nombre'] ?? '', 255, 'El nombre'),
            'direccion' => $this->opcional($datos['direccion'] ?? null, 255, 'La direccion'),
            'ciudad' => $this->opcional($datos['ciudad'] ?? null, 120, 'La ciudad'),
            'canal_id' => $canalId,
            'asesor_sap_id' => $carteraId,
            'porcentaje_descuento' => $descuento,
            'activo' => (bool) ($datos['activo'] ?? true),
        ];
    }

    // ---------- Productos ----------

    public function crearProducto(array $datos): Producto
    {
        $limpio = $this->validarProducto($datos + ['activo' => true], null);

        $producto = Producto::create($limpio);

        $this->bitacora->registrar('CREAR_PRODUCTO', 'producto', $producto->id, [
            'codigo' => $producto->codigo,
            'precio_lista' => $producto->precio_lista,
        ]);

        return $producto;
    }

    public function actualizarProducto(Producto $producto, array $cambios): Producto
    {
        $antes = $producto->only(self::CAMPOS_PRODUCTO);
        $limpio = $this->validarProducto(array_merge($antes, $cambios), $producto->id);

        $producto->fill($limpio);

        if (! $producto->isDirty()) {
            return $producto;
        }

        $producto->save();

        $this->bitacora->registrarCambio('EDITAR_PRODUCTO', 'producto', $producto->id,
            $antes, $producto->refresh()->only(self::CAMPOS_PRODUCTO));

        return $producto;
    }

    private function validarProducto(array $datos, ?int $excepto): array
    {
        $codigo = $this->codigo($datos['codigo'] ?? '');
        $this->exigirCodigoLibre(Producto::class, 'codigo', $codigo, $excepto, 'un producto');

        $precio = $this->numero($datos['precio_lista'] ?? 0, 'El precio');

        if ($precio < 0) {
            throw new RuntimeException('El precio no puede ser negativo.');
        }

        return [
            'codigo' => $codigo,
            'descripcion' => $this->obligatorio($datos['descripcion'] ?? '', 255, 'La descripcion'),
            'familia' => $this->opcional($datos['familia'] ?? null, 120, 'La familia'),
            'precio_lista' => $precio,
            'activo' => (bool) ($datos['activo'] ?? true),
        ];
    }

    // ---------- Carga por archivo ----------

    /**
     * Compara las filas leidas de un archivo contra la base, SIN escribir nada.
     *
     * Cada fila sale como NUEVO, CAMBIA (con el antes y el despues de cada
     * campo), IGUAL o ERROR (con el motivo). Una celda vacia no cuenta como
     * cambio: en la carga por archivo, vacio significa "no lo toques".
     *
     * @param  list<array{fila: int, valores: array<string, mixed>}>  $filas  lo que devuelve LectorMaestros
     * @return list<array<string, mixed>>
     */
    public function analizarCarga(string $tipo, array $filas): array
    {
        $esClientes = $this->esCargaDeClientes($tipo);
        $columna = $esClientes ? 'codigo_sn' : 'codigo';

        $existentes = $this->existentesPorCodigo(
            $esClientes ? Cliente::query()->with(['canal', 'asesorSap']) : Producto::query(),
            $columna,
            array_map(fn ($f) => $f['valores'][$columna] ?? null, $filas),
        );

        $contexto = $esClientes ? [
            'canales' => Canal::pluck('nombre')->mapWithKeys(fn ($n) => [mb_strtolower($n) => true])->all(),
            // Por numero: es la llave fija con SAP, el nombre puede venir distinto.
            'carteras' => AsesorSap::withCount('usuarios')->get()->keyBy('numero'),
        ] : [];

        $vistos = [];

        return array_map(function (array $fila) use ($esClientes, $columna, $existentes, $contexto, &$vistos) {
            $valores = array_filter($fila['valores'], fn ($v) => $v !== null);
            $resultado = [
                'fila' => (int) $fila['fila'],
                'id' => null,
                'codigo' => isset($valores[$columna]) ? (string) $valores[$columna] : null,
                'titulo' => $valores[$esClientes ? 'nombre' : 'descripcion'] ?? null,
                'estado' => 'ERROR',
                'cambios' => [],
                'motivo' => null,
                'avisos' => [],
                'cartera_distinta' => null,
                'valores' => $valores,
            ];

            try {
                $codigo = $this->codigo($resultado['codigo'] ?? '');
                $clave = mb_strtolower($codigo);

                if (isset($vistos[$clave])) {
                    throw new RuntimeException("El codigo {$codigo} se repite en el archivo (fila {$vistos[$clave]}).");
                }
                $vistos[$clave] = $resultado['fila'];

                $actual = $existentes[$clave] ?? null;
                [$propuesto, $antes] = $esClientes
                    ? $this->propuestaCliente($valores, $actual, $contexto)
                    : $this->propuestaProducto($valores, $actual);
            } catch (RuntimeException $e) {
                $resultado['motivo'] = $e->getMessage();

                return $resultado;
            }

            $cambios = [];
            foreach ($propuesto as $campo => $nuevo) {
                $viejo = $antes[$campo] ?? null;
                $igual = is_float($nuevo)
                    ? $viejo !== null && abs((float) $viejo - $nuevo) < 0.005
                    : $viejo === $nuevo;

                if (! $igual) {
                    $cambios[$campo] = ['antes' => $viejo, 'despues' => $nuevo];
                }
            }

            $resultado['id'] = $actual?->id;
            $resultado['codigo'] = $actual ? ($actual->{$columna}) : $codigo;
            $resultado['titulo'] ??= $actual?->{$esClientes ? 'nombre' : 'descripcion'};
            $resultado['cambios'] = $cambios;
            $resultado['estado'] = ! $actual ? 'NUEVO' : ($cambios ? 'CAMBIA' : 'IGUAL');
            $resultado['avisos'] = $esClientes && $resultado['estado'] !== 'IGUAL'
                ? $this->avisosCliente($cambios, $actual, $contexto)
                : [];

            // El nombre distinto se avisa aunque la fila no cambie nada: el caso
            // mas comun es un cliente que sigue en la misma cartera mientras SAP
            // la renombro, y justo ese es el que el admin necesita ver.
            if ($esClientes && $distinta = $this->carteraConOtroNombre($valores, $contexto)) {
                $resultado['cartera_distinta'] = $distinta;
                array_unshift($resultado['avisos'], "La cartera {$distinta['numero']} se llama \"{$distinta['aqui']}\" aqui y \"{$distinta['archivo']}\" en el archivo: se usa la existente. Para renombrarla, ve a Carteras.");
            }

            return $resultado;
        }, $filas);
    }

    /**
     * Aplica las filas que el admin eligio, en una sola transaccion.
     *
     * Antes de escribir se vuelve a analizar cada fila: entre la revision y la
     * carga pudo cambiar la base. Si la fila ya no es lo que el admin reviso
     * (por ejemplo, el cliente "nuevo" ya lo creo otra persona), no se carga.
     *
     * @param  list<array<string, mixed>>  $revisadas  filas tal como salieron de analizarCarga
     */
    public function cargar(string $tipo, array $revisadas, Usuario $usuario, string $archivo): Importacion
    {
        $esClientes = $this->esCargaDeClientes($tipo);
        $resumen = ['creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];
        $errores = [];

        $importacion = DB::transaction(function () use ($tipo, $esClientes, $revisadas, $usuario, $archivo, &$resumen, &$errores) {
            $frescas = collect($this->analizarCarga($tipo, array_map(
                fn ($f) => ['fila' => $f['fila'], 'valores' => $f['valores']], $revisadas,
            )))->keyBy('fila');

            foreach ($revisadas as $revisada) {
                $fresca = $frescas[$revisada['fila']];
                $motivo = null;

                if ($fresca['estado'] === 'ERROR') {
                    $motivo = $fresca['motivo'];
                } elseif ($fresca['estado'] !== ($revisada['estado'] ?? null) || $fresca['cambios'] != ($revisada['cambios'] ?? [])) {
                    // Comparacion suelta a proposito: al viajar al navegador
                    // un 64900.0 vuelve como 64900 y no es un cambio real.
                    $motivo = 'La fila cambio desde la revision. Vuelve a subir el archivo para verla como esta ahora.';
                } elseif ($fresca['estado'] === 'IGUAL') {
                    $resumen['sin_cambios']++;

                    continue;
                } else {
                    try {
                        $esClientes ? $this->aplicarCliente($fresca) : $this->aplicarProducto($fresca);
                        $resumen[$fresca['estado'] === 'NUEVO' ? 'creados' : 'actualizados']++;

                        continue;
                    } catch (RuntimeException $e) {
                        $motivo = $e->getMessage();
                    }
                }

                $resumen['errores']++;
                $errores[] = ['fila' => $fresca['fila'], 'codigo' => $fresca['codigo'], 'motivo' => $motivo];
            }

            return Importacion::create([
                'usuario_id' => $usuario->id,
                // PRECIOS y no PRODUCTOS: es el mismo tipo que deja el comando de consola.
                'tipo' => $esClientes ? 'CLIENTES' : 'PRECIOS',
                'archivo' => mb_substr($archivo, 0, 255),
                'filas_leidas' => count($revisadas),
                'creados' => $resumen['creados'],
                'actualizados' => $resumen['actualizados'],
                'sin_cambios' => $resumen['sin_cambios'],
                'errores' => $resumen['errores'],
                'detalle_errores' => array_slice($errores, 0, 200),
                'estado' => 'COMPLETADA',
            ]);
        });

        $this->bitacora->registrar('CARGAR_MAESTROS', 'importacion', $importacion->id, [
            'tipo' => $importacion->tipo,
            'archivo' => $importacion->archivo,
        ] + $resumen);

        return $importacion;
    }

    private function esCargaDeClientes(string $tipo): bool
    {
        return match ($tipo) {
            'clientes' => true,
            'productos' => false,
            default => throw new RuntimeException("Tipo de carga desconocido: {$tipo}."),
        };
    }

    /**
     * Trae de una vez los registros que ya existen con esos codigos, indexados
     * por el codigo en minusculas. Por tandas: SQLite no acepta listas enormes.
     */
    private function existentesPorCodigo(Builder $consulta, string $columna, array $codigos): array
    {
        $claves = collect($codigos)
            ->filter(fn ($c) => $c !== null && trim((string) $c) !== '')
            ->map(fn ($c) => mb_strtolower(trim((string) $c)))
            ->unique()
            ->values();

        $existentes = [];

        foreach ($claves->chunk(500) as $tanda) {
            (clone $consulta)->whereIn(DB::raw("lower({$columna})"), $tanda->all())->get()
                ->each(function (Model $m) use ($columna, &$existentes) {
                    $existentes[mb_strtolower($m->{$columna})] = $m;
                });
        }

        return $existentes;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} lo propuesto y lo actual, comparables */
    private function propuestaCliente(array $valores, ?Cliente $actual, array $contexto): array
    {
        $propuesto = [];

        foreach (['nombre' => [255, 'El nombre'], 'direccion' => [255, 'La direccion'], 'ciudad' => [120, 'La ciudad']] as $campo => [$maximo, $etiqueta]) {
            if (isset($valores[$campo])) {
                $propuesto[$campo] = $this->opcional($valores[$campo], $maximo, $etiqueta);
            }
        }

        // Misma normalizacion que el comando de consola: "DISTRIBUCION" y
        // "distribucion" son el canal "Distribucion", no dos canales.
        if (isset($valores['canal'])) {
            $propuesto['canal'] = Str::title(Str::lower(trim((string) $valores['canal'])));
        }

        // La cartera se reconoce por su numero. Si ya existe, lo propuesto es
        // la cartera tal como se llama aqui: asi un cliente que sigue en la 14
        // no aparece como cambio solo porque el archivo trae otro nombre.
        if (isset($valores['asesor']) && trim((string) $valores['asesor']) !== '') {
            $texto = trim((string) $valores['asesor']);
            $numero = AsesorSap::numeroDelTexto($texto);

            if ($numero === null) {
                throw new RuntimeException("La cartera \"{$texto}\" no empieza con su numero (por ejemplo \"14 MONICA RIVERA AREVALO\").");
            }

            $propuesto['asesor'] = $contexto['carteras'][$numero]->codigo_texto ?? $texto;
        }

        if (isset($valores['descuento'])) {
            if (! is_float($valores['descuento']) && ! is_int($valores['descuento'])) {
                throw new RuntimeException("El descuento no es un numero: {$valores['descuento']}.");
            }

            $propuesto['descuento'] = (float) $valores['descuento'];

            if ($propuesto['descuento'] < 0 || $propuesto['descuento'] > 100) {
                throw new RuntimeException('El descuento tiene que estar entre 0 y 100.');
            }
        }

        if (! $actual && ! isset($propuesto['nombre'])) {
            throw new RuntimeException('Falta el nombre: es un cliente nuevo.');
        }

        $antes = $actual ? [
            'nombre' => $actual->nombre,
            'direccion' => $actual->direccion,
            'ciudad' => $actual->ciudad,
            'canal' => $actual->canal?->nombre,
            'asesor' => $actual->asesorSap?->codigo_texto,
            'descuento' => (float) $actual->porcentaje_descuento,
        ] : [];

        return [$propuesto, $antes];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function propuestaProducto(array $valores, ?Producto $actual): array
    {
        $propuesto = [];

        foreach (['descripcion' => [255, 'La descripcion'], 'familia' => [120, 'La familia']] as $campo => [$maximo, $etiqueta]) {
            if (isset($valores[$campo])) {
                $propuesto[$campo] = $this->opcional($valores[$campo], $maximo, $etiqueta);
            }
        }

        if (isset($valores['precio'])) {
            if (! is_float($valores['precio']) && ! is_int($valores['precio'])) {
                throw new RuntimeException("El precio no es un numero: {$valores['precio']}.");
            }

            $propuesto['precio'] = (float) $valores['precio'];

            if ($propuesto['precio'] < 0) {
                throw new RuntimeException('El precio no puede ser negativo.');
            }
        }

        if (! $actual && ! isset($propuesto['descripcion'])) {
            throw new RuntimeException('Falta la descripcion: es un producto nuevo.');
        }

        // Un producto nuevo sin precio entraria a cero y saldria regalado.
        if (! $actual && ! isset($propuesto['precio'])) {
            throw new RuntimeException('Falta el precio: es un producto nuevo.');
        }

        $antes = $actual ? [
            'descripcion' => $actual->descripcion,
            'familia' => $actual->familia,
            'precio' => (float) $actual->precio_lista,
        ] : [];

        return [$propuesto, $antes];
    }

    /**
     * Lo que el admin tiene que saber antes de cargar un cliente: si nadie lo
     * va a ver (la visibilidad depende de que algun asesor tenga la cartera) y
     * si el archivo nombra la cartera distinto de como esta aqui.
     */
    private function avisosCliente(array $cambios, ?Cliente $actual, array $contexto): array
    {
        $avisos = [];

        if (isset($cambios['canal']) && ! isset($contexto['canales'][mb_strtolower($cambios['canal']['despues'])])) {
            $avisos[] = "Canal nuevo: se crea \"{$cambios['canal']['despues']}\".";
        }

        if (isset($cambios['asesor'])) {
            $texto = $cambios['asesor']['despues'];
            $numero = AsesorSap::numeroDelTexto($texto);
            $cartera = $contexto['carteras'][$numero] ?? null;

            // El nombre distinto se avisa aparte, en analizarCarga, para que
            // tambien llegue a las filas que no cambian nada.
            if (! $cartera) {
                $avisos[] = "Cartera nueva \"{$texto}\": ningun asesor la tiene asignada todavia, asi que nadie vera este cliente.";
            } else {
                if (! $cartera->activo) {
                    $avisos[] = "La cartera {$numero} esta inactiva: el cliente queda en ella igual.";
                }

                if ($cartera->usuarios_count === 0) {
                    $avisos[] = "Ningun asesor tiene asignada la cartera \"{$texto}\": nadie vera este cliente.";
                }
            }
        } elseif (! $actual) {
            $avisos[] = 'Sin cartera: ningun asesor vera este cliente hasta asignarle una.';
        }

        return $avisos;
    }

    /**
     * La cartera del archivo existe aqui con el mismo numero pero otro nombre.
     *
     * La carga nunca renombra: un error de digitacion en el archivo no debe
     * cambiarle el nombre a la cartera de nadie. Lo que si hace es avisar, para
     * que el admin la renombre a mano si de verdad cambio en SAP.
     *
     * @return array{numero: int, aqui: string, archivo: string}|null
     */
    private function carteraConOtroNombre(array $valores, array $contexto): ?array
    {
        $enArchivo = trim((string) ($valores['asesor'] ?? ''));
        $numero = AsesorSap::numeroDelTexto($enArchivo);
        $cartera = $numero === null ? null : ($contexto['carteras'][$numero] ?? null);

        if (! $cartera || $cartera->codigo_texto === $enArchivo) {
            return null;
        }

        return ['numero' => $numero, 'aqui' => $cartera->codigo_texto, 'archivo' => $enArchivo];
    }

    private function aplicarCliente(array $fila): void
    {
        $datos = [];

        foreach ($fila['cambios'] as $campo => $cambio) {
            $valor = $cambio['despues'];

            match ($campo) {
                'canal' => $datos['canal_id'] = Canal::firstOrCreate(['nombre' => $valor], ['activo' => true])->id,
                'asesor' => $datos['asesor_sap_id'] = AsesorSap::resolverDesdeTexto($valor)->id,
                'descuento' => $datos['porcentaje_descuento'] = $valor,
                default => $datos[$campo] = $valor,
            };
        }

        if ($fila['estado'] === 'NUEVO') {
            $this->crearCliente(['codigo_sn' => $fila['codigo']] + $datos);
        } else {
            $this->actualizarCliente(Cliente::findOrFail($fila['id']), $datos);
        }
    }

    private function aplicarProducto(array $fila): void
    {
        $datos = [];

        foreach ($fila['cambios'] as $campo => $cambio) {
            $datos[$campo === 'precio' ? 'precio_lista' : $campo] = $cambio['despues'];
        }

        if ($fila['estado'] === 'NUEVO') {
            $this->crearProducto(['codigo' => $fila['codigo']] + $datos);
        } else {
            $this->actualizarProducto(Producto::findOrFail($fila['id']), $datos);
        }
    }

    // ---------- Reglas comunes ----------

    /**
     * El codigo es la llave con SAP: viaja tal cual en la plantilla DTW.
     *
     * Un espacio en medio no es un error de digitacion inofensivo: SAP no
     * encontraria el cliente o el articulo y la carga del pedido fallaria.
     */
    private function codigo(mixed $valor): string
    {
        $codigo = trim((string) $valor);

        if ($codigo === '') {
            throw new RuntimeException('El codigo es obligatorio.');
        }

        if (preg_match('/\s/u', $codigo)) {
            throw new RuntimeException("El codigo [{$codigo}] no puede tener espacios.");
        }

        if (mb_strlen($codigo) > 50) {
            throw new RuntimeException('El codigo no puede pasar de 50 caracteres.');
        }

        return $codigo;
    }

    /**
     * Unico sin importar mayusculas: "cn0507" y "CN0507" son el mismo para una
     * persona, y tenerlos los dos seria un duplicado dificil de ver.
     *
     * @param  class-string<Model>  $modelo
     */
    private function exigirCodigoLibre(string $modelo, string $columna, string $codigo, ?int $excepto, string $que): void
    {
        $repetido = $modelo::query()
            ->whereRaw("lower({$columna}) = ?", [mb_strtolower($codigo)])
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->exists();

        if ($repetido) {
            throw new RuntimeException("Ya existe {$que} con el codigo {$codigo}.");
        }
    }

    private function obligatorio(mixed $valor, int $maximo, string $campo): string
    {
        $texto = $this->opcional($valor, $maximo, $campo);

        if ($texto === null) {
            throw new RuntimeException("{$campo} es obligatorio.");
        }

        return $texto;
    }

    private function opcional(mixed $valor, int $maximo, string $campo): ?string
    {
        $texto = trim((string) $valor);

        if (mb_strlen($texto) > $maximo) {
            throw new RuntimeException("{$campo} no puede pasar de {$maximo} caracteres.");
        }

        return $texto === '' ? null : $texto;
    }

    private function numero(mixed $valor, string $campo): float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $texto = str_replace(',', '.', trim((string) $valor));

        if ($texto === '') {
            return 0.0;
        }

        if (! is_numeric($texto)) {
            throw new RuntimeException("{$campo} tiene que ser un numero.");
        }

        return (float) $texto;
    }

    private function idOpcional(mixed $valor): ?int
    {
        return ($valor === null || $valor === '') ? null : (int) $valor;
    }
}
