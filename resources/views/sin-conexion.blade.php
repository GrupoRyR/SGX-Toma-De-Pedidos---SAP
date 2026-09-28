<!DOCTYPE html>
<html lang="es" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#23272b">
        <title>Sin conexión · Pedidos SEGUREX</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="flex min-h-full items-center justify-center bg-acero px-6">
        {{--
            La pantalla que ve el asesor cuando se cae la señal. Dice qué pasó y
            qué hacer, no pide disculpas ni culpa a nadie.
        --}}
        <main class="w-full max-w-sm text-center">
            <h1 class="font-titulo text-2xl font-semibold tracking-tight">Sin conexión</h1>

            <p class="mt-2 text-niquel">
                No hay señal en este momento. Lo que ya habías guardado está a salvo en el servidor.
            </p>

            <button type="button" onclick="location.reload()"
                    class="mt-6 w-full rounded-lg bg-naranja px-4 py-3.5 font-semibold text-white hover:bg-naranja-hondo">
                Reintentar
            </button>
        </main>
    </body>
</html>
