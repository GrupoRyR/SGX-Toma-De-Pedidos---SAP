<?php

use App\Http\Middleware\Puede;
use App\Http\Middleware\TokenDeServicio;
use App\Http\Middleware\UsuarioActivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'usuario.activo' => UsuarioActivo::class,
            'puede' => Puede::class,
            'token.servicio' => TokenDeServicio::class,
        ]);

        // Quien no tenga sesion va a la pantalla de entrada, no a /login.
        $middleware->redirectGuestsTo('/entrar');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
