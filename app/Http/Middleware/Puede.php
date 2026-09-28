<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar segun lo que el usuario puede hacer, no segun su rol.
 *
 * Se usa para las bandejas, que no cuelgan de un modelo concreto y por eso no
 * pueden resolverse con una policy.
 */
class Puede
{
    public function handle(Request $request, Closure $next, string $capacidad): Response
    {
        $usuario = Auth::user();

        $permitido = match ($capacidad) {
            'aprobar' => $usuario?->puedeAprobar() ?? false,
            'liberar' => $usuario?->puedeLiberarASap() ?? false,
            'administrar' => $usuario?->esAdministrador() ?? false,
            default => false,
        };

        abort_unless($permitido, 403);

        return $next($request);
    }
}
