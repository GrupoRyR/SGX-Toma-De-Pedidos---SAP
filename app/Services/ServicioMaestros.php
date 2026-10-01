<?php

namespace App\Services;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Model;
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
