<?php

use App\Http\Controllers\AutenticacionController;
use App\Models\Usuario;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * La aplicacion entera exige sesion. Lo unico publico es la pantalla de entrada
 * y el ida y vuelta con Microsoft.
 */

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AutenticacionController::class, 'mostrarEntrar'])->name('entrar');

    // Se limita el ritmo: sin esto, un enlace repetido puede disparar decenas
    // de redirecciones al proveedor de identidad.
    Route::get('/auth/redirigir', [AutenticacionController::class, 'redirigir'])
        ->middleware('throttle:10,1')
        ->name('auth.redirigir');

    Route::get('/auth/callback', [AutenticacionController::class, 'volver'])
        ->middleware('throttle:10,1')
        ->name('auth.callback');
});

Route::get('/sin-acceso', [AutenticacionController::class, 'sinAcceso'])->name('sin-acceso');

// La pantalla que el service worker muestra cuando no hay senal. Es publica
// porque tiene que poder cachearse antes de que nadie inicie sesion.
Route::view('/sin-conexion', 'sin-conexion')->name('sin-conexion');
Route::get('/salir', [AutenticacionController::class, 'salir'])->name('salir');

Route::middleware(['auth', 'usuario.activo'])->group(function () {
    // El asesor entra directo a su lista de clientes: es lo primero que hace.
    Route::get('/', fn () => redirect()->route('clientes'))->name('inicio');

    Route::livewire('/clientes', 'mis-clientes')->name('clientes');
    Route::livewire('/clientes/{cliente}', 'cliente')->name('cliente');
    Route::livewire('/pedidos/{pedido}', 'pedido')->name('pedido');

    // Bandeja de aprobacion: gerentes de canal, admin de ventas y TI.
    Route::livewire('/bandeja', 'bandeja')->name('bandeja')->middleware('puede:aprobar');

    // Visto bueno para SAP: solo quienes tienen el permiso LIBERAR_SAP.
    Route::livewire('/liberar', 'liberar')->name('liberar')->middleware('puede:liberar');

    /*
     * Administracion: admin de ventas y TI. Va en su propia seccion con
     * sub-pestanas en vez de sumar tres entradas mas al encabezado, que el
     * asesor no tiene por que ver.
     */
    Route::middleware('puede:administrar')->group(function () {
        Route::livewire('/administracion/usuarios', 'usuarios')->name('usuarios');
        Route::livewire('/administracion/usuarios/{usuario}', 'usuario')->name('usuario');
        Route::livewire('/administracion/configuracion', 'configuracion')->name('configuracion');
        Route::livewire('/administracion/registros', 'registros')->name('registros');

        // Carga manual a SAP con DTW, mientras no exista el robot puente.
        Route::livewire('/administracion/plantillas', 'plantillas')->name('plantillas');
    });
});

/*
 * Acceso de desarrollo.
 *
 * Permite entrar como cualquier usuario de la tabla sin pasar por Microsoft,
 * para revisar pantallas mientras se construyen. Es un salto de autenticacion,
 * asi que lleva tres candados y tienen que darse los tres:
 *
 *   1. APP_ENV=local
 *   2. APP_DEBUG=true
 *   3. DEV_LOGIN=true, que NO existe en .env.example
 *
 * En el servidor de GoDaddy no se cumple ninguno y la ruta ni siquiera queda
 * registrada. Aun asi, revisar antes de cada despliegue que no aparezca en
 * `php artisan route:list`.
 */
if (app()->isLocal() && config('app.debug') && env('DEV_LOGIN') === true) {
    Route::get('/dev/entrar/{correo}', function (string $correo) {
        $usuario = Usuario::where('correo', strtolower($correo))->firstOrFail();

        Auth::login($usuario);

        return redirect()->route('inicio');
    })->name('dev.entrar');
}
