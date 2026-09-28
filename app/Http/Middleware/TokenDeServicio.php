<?php

namespace App\Http\Middleware;

use App\Models\TokenServicio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar solo al robot puente.
 *
 * El token viaja en la cabecera Authorization. No hay sesion ni cookies: cada
 * llamada se autentica sola, que es como tiene que ser una tarea programada.
 */
class TokenDeServicio
{
    public function handle(Request $request, Closure $next): Response
    {
        $claro = $request->bearerToken();

        if (! $claro) {
            return response()->json(['error' => 'Falta el token de servicio.'], 401);
        }

        $token = TokenServicio::porValor($claro);

        if (! $token) {
            return response()->json(['error' => 'Token no valido.'], 401);
        }

        if (! $token->aceptaIp($request->ip())) {
            return response()->json(['error' => 'Esta IP no esta autorizada para este token.'], 403);
        }

        $token->registrarUso($request->ip());
        $request->attributes->set('token_servicio', $token);

        return $next($request);
    }
}
