<?php

namespace App\Services;

use App\Models\Bitacora;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Escribe en el log de registros.
 *
 * Regla de oro: registrar nunca puede tumbar la operacion. Si falla el insert
 * en bitacora, se deja constancia en el log de errores del servidor y la
 * operacion sigue. Un pedido no se pierde porque no se pudo auditar.
 */
class Bitacorero
{
    public function registrar(string $accion, string $entidad, ?int $entidadId = null, array $detalle = []): void
    {
        try {
            $usuario = Auth::user();

            Bitacora::create([
                'usuario_id' => $usuario?->id,
                'usuario_correo' => $usuario?->correo ?? ($detalle['correo'] ?? null),
                'fecha_hora' => now(),
                'accion' => $accion,
                'entidad' => $entidad,
                'entidad_id' => $entidadId,
                'detalle' => $detalle ?: null,
                'ip' => Request::ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('No se pudo escribir en bitacora', [
                'accion' => $accion,
                'entidad' => $entidad,
                'entidad_id' => $entidadId,
                'excepcion' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Registra un cambio guardando el antes y el despues.
     *
     * Es lo que permite responder "que decia antes" sin discutir.
     */
    public function registrarCambio(string $accion, string $entidad, int $entidadId, array $antes, array $despues): void
    {
        $cambios = [];

        foreach ($despues as $campo => $valorNuevo) {
            $valorViejo = $antes[$campo] ?? null;

            if ($valorViejo != $valorNuevo) {
                $cambios[$campo] = ['antes' => $valorViejo, 'despues' => $valorNuevo];
            }
        }

        if ($cambios === []) {
            return;
        }

        $this->registrar($accion, $entidad, $entidadId, ['cambios' => $cambios]);
    }
}
