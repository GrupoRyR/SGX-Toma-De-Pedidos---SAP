<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta la sesion de un usuario que fue desactivado mientras estaba adentro.
 *
 * Sin esto, desactivar a alguien en administracion no surte efecto hasta que
 * cierre sesion, y una sesion recordada puede durar semanas.
 */
class UsuarioActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::user();

        if ($usuario && ! $usuario->activo) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('sin-acceso')->with('correo', $usuario->correo);
        }

        return $next($request);
    }
}
