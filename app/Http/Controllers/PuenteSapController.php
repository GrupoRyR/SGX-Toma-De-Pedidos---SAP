<?php

namespace App\Http\Controllers;

use App\Services\PuenteSap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La API que consume el robot puente.
 *
 * Quien llama aqui no es una persona: es una tarea programada corriendo dentro
 * de SEGUREX, detras de la VPN. Por eso las respuestas son explicitas y los
 * errores vienen con el motivo en texto: del otro lado no hay nadie leyendo
 * una pantalla, hay un script que tiene que poder decidir si reintenta.
 */
class PuenteSapController extends Controller
{
    public function __construct(private PuenteSap $puente) {}

    /**
     * Pedidos con visto bueno, listos para crear en SAP.
     *
     * Devolver una lista vacia es el resultado normal la mayor parte del dia.
     */
    public function liberados(Request $request): JsonResponse
    {
        $cuantos = min(max((int) $request->query('limite', 50), 1), 200);

        $pedidos = $this->puente->pendientes($cuantos);

        return response()->json([
            'pedidos' => $pedidos,
            'cuantos' => $pedidos->count(),
            'consultado_en' => now()->toIso8601String(),
        ]);
    }

    /** SAP creo el pedido. */
    public function confirmar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'pedido_id' => ['required', 'integer'],
            'docentry' => ['required', 'string', 'max:50'],
            'docnum' => ['required', 'string', 'max:50'],
        ]);

        try {
            $pedido = $this->puente->confirmar($datos['pedido_id'], $datos['docentry'], $datos['docnum']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'pedido_id' => $pedido->id,
            'estado' => $pedido->estado->value,
            'docnum' => $pedido->sap_docnum,
        ]);
    }

    /** SAP rechazo el pedido. */
    public function error(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'pedido_id' => ['required', 'integer'],
            'mensaje' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $pedido = $this->puente->registrarError($datos['pedido_id'], $datos['mensaje']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'pedido_id' => $pedido->id,
            'intentos' => (int) $pedido->sap_intentos,
            // Se lo decimos explicito para que el robot no tenga que contar.
            'reintentar' => (int) $pedido->sap_intentos < PuenteSap::MAXIMO_INTENTOS,
        ]);
    }

    /** Existencias por bodega. */
    public function inventario(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'corte' => ['nullable', 'date'],
            'filas' => ['required', 'array', 'min:1'],
            'filas.*.codigo_producto' => ['required', 'string', 'max:50'],
            'filas.*.bodega' => ['nullable', 'string', 'max:50'],
            'filas.*.disponible' => ['required', 'numeric'],
        ]);

        return response()->json(
            $this->puente->publicarInventario($datos['filas'], $datos['corte'] ?? null)
        );
    }

    /** Lista de precios. */
    public function precios(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'filas' => ['required', 'array', 'min:1'],
            'filas.*.codigo' => ['required', 'string', 'max:50'],
            'filas.*.descripcion' => ['nullable', 'string', 'max:255'],
            'filas.*.precio_lista' => ['required', 'numeric', 'min:0'],
            'filas.*.familia' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->puente->publicarPrecios($datos['filas']));
    }

    /** Maestro de clientes. */
    public function clientes(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'filas' => ['required', 'array', 'min:1'],
            'filas.*.codigo_sn' => ['required', 'string', 'max:50'],
            'filas.*.nombre' => ['required', 'string', 'max:255'],
            'filas.*.direccion' => ['nullable', 'string', 'max:255'],
            'filas.*.ciudad' => ['nullable', 'string', 'max:120'],
            'filas.*.porcentaje_descuento' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json($this->puente->publicarClientes($datos['filas']));
    }

    /**
     * Prueba de vida.
     *
     * Sirve para que quien configure el robot sepa que el token quedo bien
     * antes de intentar mover pedidos.
     */
    public function saludo(Request $request): JsonResponse
    {
        $token = $request->attributes->get('token_servicio');

        return response()->json([
            'ok' => true,
            'token' => $token?->nombre,
            'hora_servidor' => now()->toIso8601String(),
        ]);
    }
}
