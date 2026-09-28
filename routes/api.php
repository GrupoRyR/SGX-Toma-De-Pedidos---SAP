<?php

use App\Http\Controllers\PuenteSapController;
use Illuminate\Support\Facades\Route;

/*
 * API del robot puente.
 *
 * No hay sesion ni cookies: cada llamada trae su token en la cabecera
 * Authorization. Del otro lado no hay una persona, hay una tarea programada
 * corriendo dentro de SEGUREX.
 *
 * El limite de peticiones es holgado a proposito: el robot consulta cada pocos
 * minutos y sube maestros en lotes, pero si alguien roba el token no puede
 * usarlo para golpear el servidor.
 */
Route::prefix('sap')
    ->middleware(['token.servicio', 'throttle:120,1'])
    ->group(function () {
        Route::get('/saludo', [PuenteSapController::class, 'saludo']);

        // Bajada: de la web a SAP.
        Route::get('/pedidos-liberados', [PuenteSapController::class, 'liberados']);
        Route::post('/confirmar', [PuenteSapController::class, 'confirmar']);
        Route::post('/error', [PuenteSapController::class, 'error']);

        // Subida: de SAP a la web.
        Route::post('/maestros/inventario', [PuenteSapController::class, 'inventario']);
        Route::post('/maestros/precios', [PuenteSapController::class, 'precios']);
        Route::post('/maestros/clientes', [PuenteSapController::class, 'clientes']);
    });
