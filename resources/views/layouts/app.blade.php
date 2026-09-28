<!DOCTYPE html>
<html lang="es" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#23272b">

        <title>{{ $title ?? 'Pedidos SEGUREX' }}</title>

        {{-- Aplicacion instalable. Sin modo offline completo: fue una decision
             de SEGUREX, no una limitacion. --}}
        <link rel="manifest" href="/pwa/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/pwa/apple-touch-icon.png">
        <link rel="icon" href="/pwa/icono-192.png" type="image/png">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Pedidos">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-full bg-acero">
        @php
            $usuario = auth()->user();
            // La navegacion se arma con lo que cada quien puede hacer. Un asesor
            // no ve la bandeja de aprobacion porque para el no existe.
            $secciones = collect([
                ['ruta' => 'clientes', 'texto' => 'Mis clientes', 'visible' => true, 'activa' => request()->routeIs('clientes')],
                ['ruta' => 'bandeja', 'texto' => 'Por aprobar', 'visible' => $usuario->puedeAprobar(), 'activa' => request()->routeIs('bandeja')],
                ['ruta' => 'liberar', 'texto' => 'Por liberar', 'visible' => $usuario->puedeLiberarASap(), 'activa' => request()->routeIs('liberar')],
                // Administracion cubre cuatro pantallas, asi que se marca por ruta.
                ['ruta' => 'usuarios', 'texto' => 'Administración', 'visible' => $usuario->esAdministrador(), 'activa' => request()->is('administracion*')],
            ])->where('visible');
        @endphp

        <header class="bg-grafito text-white">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3">
                <a href="{{ route('clientes') }}" wire:navigate class="font-titulo text-lg font-semibold tracking-tight">
                    Pedidos <span class="text-naranja">SEGUREX</span>
                </a>

                <div class="flex items-center gap-3 text-sm">
                    <span class="hidden text-niquel-claro sm:inline">{{ $usuario->nombre }}</span>
                    <a href="{{ route('salir') }}" class="rounded px-2 py-1 text-niquel-claro hover:text-white">Salir</a>
                </div>
            </div>

            @if ($secciones->count() > 1)
                <nav class="mx-auto flex max-w-3xl gap-1 overflow-x-auto px-3">
                    @foreach ($secciones as $seccion)
                        <a
                            href="{{ route($seccion['ruta']) }}"
                            wire:navigate
                            @class([
                                'shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium',
                                'border-naranja text-white' => $seccion['activa'],
                                'border-transparent text-niquel-claro hover:text-white' => ! $seccion['activa'],
                            ])
                        >{{ $seccion['texto'] }}</a>
                    @endforeach
                </nav>
            @endif
        </header>

        <main class="mx-auto max-w-3xl px-4 pb-16 pt-5">
            {{ $slot }}
        </main>

        @livewireScripts

        <script>
            // Solo en HTTPS o en local: el navegador no registra un service
            // worker sobre http en un dominio real, y es correcto que no lo haga.
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    // El ?v= es el hash del manifiesto de Vite: cambia solo
                    // cuando cambia la interfaz, y al cambiar hace que el
                    // navegador instale el service worker nuevo y bote el cache
                    // viejo. Sin el, habria que acordarse de subir un numero a
                    // mano en cada despliegue.
                    navigator.serviceWorker.register('/sw.js?v={{ Vite::manifestHash() ?? 'dev' }}').catch(() => {
                        // Que falle el registro no puede romper la aplicacion:
                        // sin service worker todo sigue funcionando, solo carga
                        // un poco mas lento.
                    });
                });
            }
        </script>
    </body>
</html>
