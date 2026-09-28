<?php

namespace App\Services;

use App\Enums\EstadoPedido;
use App\Models\Cliente;
use App\Models\Inventario;
use App\Models\Pedido;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El lado web del robot puente.
 *
 * Dos direcciones:
 *  - Bajada: entrega los pedidos que ya tienen visto bueno y recibe el numero
 *    real que SAP les asigno.
 *  - Subida: recibe los maestros que hoy se cargan a mano (clientes, precios,
 *    existencias).
 *
 * La regla que no se negocia: aqui solo salen pedidos LIBERADO. Un pedido
 * APROBADO no existe para el robot. Si esta condicion se relaja alguna vez, se
 * pierde el control humano que SEGUREX puso antes de SAP.
 */
class PuenteSap
{
    /** Cuantas veces se reintenta un pedido que SAP rechazo, antes de rendirse. */
    public const MAXIMO_INTENTOS = 3;

    public function __construct(private Bitacorero $bitacora) {}

    /**
     * Pedidos listos para crear en SAP.
     *
     * Se excluyen los que ya fallaron tres veces: reintentar en silencio para
     * siempre esconde el problema en vez de resolverlo.
     */
    public function pendientes(int $cuantos = 50): Collection
    {
        return Pedido::query()
            ->listosParaSap()
            ->where('sap_intentos', '<', self::MAXIMO_INTENTOS)
            ->with(['lineas', 'cliente:id,codigo_sn,nombre'])
            ->orderBy('fecha_liberacion')
            ->limit($cuantos)
            ->get()
            ->map(fn (Pedido $pedido) => $this->comoJson($pedido));
    }

    /** La forma en que el robot ve un pedido. */
    private function comoJson(Pedido $pedido): array
    {
        return [
            'id' => $pedido->id,
            'codigo_cliente' => $pedido->codigo_cliente,
            'nombre_cliente' => $pedido->nombre_cliente,
            'orden_compra' => $pedido->orden_compra,
            'fecha_facturacion' => $pedido->fecha_facturacion?->toDateString(),
            'direccion_entrega' => $pedido->direccion_2 ?: $pedido->direccion,
            'ciudad_entrega' => $pedido->ciudad_2 ?: $pedido->ciudad,
            'observaciones' => $pedido->observaciones,
            'subtotal' => (float) $pedido->subtotal,
            'iva' => (float) $pedido->iva,
            'total' => (float) $pedido->total,
            'fecha_liberacion' => $pedido->fecha_liberacion?->toIso8601String(),
            'intentos_previos' => (int) $pedido->sap_intentos,
            'lineas' => $pedido->lineas->map(fn ($linea) => [
                'numero' => (int) $linea->linea_num,
                'codigo_producto' => $linea->codigo_producto,
                'descripcion' => $linea->descripcion,
                'cantidad' => (float) $linea->cantidad,
                'precio_unitario' => (float) $linea->precio_unitario,
                'subtotal' => (float) $linea->subtotal_linea,
            ])->all(),
        ];
    }

    /**
     * SAP creo el pedido: se guarda su numero y se cierra el ciclo.
     *
     * Es idempotente a proposito. Si el robot confirma dos veces el mismo
     * pedido, porque perdio la respuesta y reintento, la segunda no rompe nada
     * ni duplica el registro.
     */
    public function confirmar(int $pedidoId, string $docEntry, string $docNum): Pedido
    {
        return DB::transaction(function () use ($pedidoId, $docEntry, $docNum) {
            $pedido = Pedido::whereKey($pedidoId)->lockForUpdate()->first();

            if (! $pedido) {
                throw new RuntimeException("No existe el pedido {$pedidoId}.");
            }

            if ($pedido->importado_sap) {
                // Ya estaba. El robot reintento sobre algo resuelto.
                return $pedido;
            }

            if ($pedido->estado !== EstadoPedido::LIBERADO) {
                throw new RuntimeException("El pedido {$pedidoId} no esta liberado: no debio salir a SAP.");
            }

            $pedido->forceFill([
                'estado' => EstadoPedido::IMPORTADO,
                'importado_sap' => true,
                'fecha_importacion' => now(),
                'sap_docentry' => $docEntry,
                'sap_docnum' => $docNum,
                'sap_error' => null,
            ])->save();

            $this->bitacora->registrar('IMPORTAR_SAP', 'pedido', $pedido->id, [
                'docentry' => $docEntry,
                'docnum' => $docNum,
            ]);

            return $pedido;
        });
    }

    /**
     * SAP rechazo el pedido.
     *
     * El pedido NO cambia de estado: sigue LIBERADO, y vuelve a aparecer en la
     * bandeja marcado con el mensaje textual de SAP. Quien lo libero tiene que
     * verlo; esconderlo seria peor que el error.
     */
    public function registrarError(int $pedidoId, string $mensaje): Pedido
    {
        return DB::transaction(function () use ($pedidoId, $mensaje) {
            $pedido = Pedido::whereKey($pedidoId)->lockForUpdate()->first();

            if (! $pedido) {
                throw new RuntimeException("No existe el pedido {$pedidoId}.");
            }

            if ($pedido->importado_sap) {
                throw new RuntimeException("El pedido {$pedidoId} ya esta en SAP.");
            }

            $pedido->forceFill([
                'sap_error' => trim($mensaje),
                'sap_intentos' => (int) $pedido->sap_intentos + 1,
            ])->save();

            $this->bitacora->registrar('ERROR_SAP', 'pedido', $pedido->id, [
                'mensaje' => trim($mensaje),
                'intento' => (int) $pedido->sap_intentos,
                'se_rindio' => (int) $pedido->sap_intentos >= self::MAXIMO_INTENTOS,
            ]);

            return $pedido;
        });
    }

    /**
     * Existencias por bodega.
     *
     * Upsert, nunca borrado: si SAP deja de reportar una bodega, lo que ya
     * estaba se conserva con su fecha de corte vieja, y la pantalla lo muestra
     * atenuado. Es mejor un dato viejo y marcado como viejo que ningun dato.
     */
    public function publicarInventario(array $filas, ?string $fechaCorte = null): array
    {
        $corte = $fechaCorte ? Carbon::parse($fechaCorte) : now();
        $tocadas = 0;

        foreach (array_chunk($filas, 500) as $lote) {
            $paraGuardar = [];

            foreach ($lote as $fila) {
                $codigo = trim((string) ($fila['codigo_producto'] ?? $fila['codigo'] ?? ''));

                if ($codigo === '') {
                    continue;
                }

                $paraGuardar[] = [
                    'codigo_producto' => $codigo,
                    'bodega' => trim((string) ($fila['bodega'] ?? 'GENERAL')) ?: 'GENERAL',
                    'disponible' => (float) ($fila['disponible'] ?? 0),
                    'fecha_corte' => $corte,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($paraGuardar !== []) {
                Inventario::upsert($paraGuardar, ['codigo_producto', 'bodega'],
                    ['disponible', 'fecha_corte', 'updated_at']);
                $tocadas += count($paraGuardar);
            }
        }

        $this->bitacora->registrar('PUBLICAR_INVENTARIO', 'inventario', null, [
            'filas' => $tocadas,
            'corte' => $corte->toIso8601String(),
        ]);

        return ['filas' => $tocadas, 'corte' => $corte->toIso8601String()];
    }

    /**
     * Lista de precios.
     *
     * Solo toca precio y descripcion. No desactiva productos que SAP deje de
     * enviar: un envio incompleto no puede vaciar el catalogo.
     */
    public function publicarPrecios(array $filas): array
    {
        $creados = 0;
        $actualizados = 0;

        foreach ($filas as $fila) {
            $codigo = trim((string) ($fila['codigo'] ?? ''));

            if ($codigo === '') {
                continue;
            }

            $producto = Producto::firstOrNew(['codigo' => $codigo]);
            $existia = $producto->exists;

            $producto->fill([
                'descripcion' => trim((string) ($fila['descripcion'] ?? $producto->descripcion ?? $codigo)),
                'precio_lista' => (float) ($fila['precio_lista'] ?? $producto->precio_lista ?? 0),
            ]);

            if (array_key_exists('familia', $fila)) {
                $producto->familia = $fila['familia'] ?: null;
            }

            if (! $existia) {
                $producto->activo = true;
            }

            if ($producto->isDirty() || ! $existia) {
                $producto->save();
                $existia ? $actualizados++ : $creados++;
            }
        }

        $this->bitacora->registrar('PUBLICAR_PRECIOS', 'producto', null, [
            'creados' => $creados,
            'actualizados' => $actualizados,
        ]);

        return ['creados' => $creados, 'actualizados' => $actualizados];
    }

    /**
     * Maestro de clientes.
     *
     * El canal y la cartera se resuelven por texto, igual que en la importacion
     * de CSV, para no inventar codigos que SAP no tiene. Un cliente que llega
     * sin canal conocido se guarda igual: se ve en `pedidos:revisar-datos`.
     */
    public function publicarClientes(array $filas): array
    {
        $creados = 0;
        $actualizados = 0;

        foreach ($filas as $fila) {
            $codigo = trim((string) ($fila['codigo_sn'] ?? $fila['codigo'] ?? ''));

            if ($codigo === '') {
                continue;
            }

            $cliente = Cliente::firstOrNew(['codigo_sn' => $codigo]);
            $existia = $cliente->exists;

            $cliente->fill(array_filter([
                'nombre' => trim((string) ($fila['nombre'] ?? $cliente->nombre ?? $codigo)),
                'direccion' => $fila['direccion'] ?? $cliente->direccion,
                'ciudad' => $fila['ciudad'] ?? $cliente->ciudad,
            ], fn ($v) => $v !== null));

            if (array_key_exists('porcentaje_descuento', $fila)) {
                $cliente->porcentaje_descuento = (float) $fila['porcentaje_descuento'];
            }

            if (! $existia) {
                $cliente->activo = true;
            }

            if ($cliente->isDirty() || ! $existia) {
                $cliente->save();
                $existia ? $actualizados++ : $creados++;
            }
        }

        $this->bitacora->registrar('PUBLICAR_CLIENTES', 'cliente', null, [
            'creados' => $creados,
            'actualizados' => $actualizados,
        ]);

        return ['creados' => $creados, 'actualizados' => $actualizados];
    }
}
