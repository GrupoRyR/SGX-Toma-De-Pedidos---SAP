{{--
    Sub-navegacion de administracion.

    Va dentro de la seccion en vez de sumar pestanas al encabezado: el asesor,
    que es casi todo el mundo, no tiene por que ver estas tres.
--}}
@php
    $partes = [
        ['ruta' => 'usuarios', 'texto' => 'Usuarios'],
        ['ruta' => 'configuracion', 'texto' => 'Configuración'],
        ['ruta' => 'plantillas', 'texto' => 'Plantillas SAP'],
        ['ruta' => 'registros', 'texto' => 'Registros'],
    ];
@endphp

<nav class="mb-4 flex gap-1 overflow-x-auto border-b border-acero-hondo">
    @foreach ($partes as $parte)
        <a href="{{ route($parte['ruta']) }}" wire:navigate
            @class([
                'shrink-0 -mb-px border-b-2 px-3 pb-2.5 text-sm font-medium',
                'border-naranja text-grafito' => request()->routeIs($parte['ruta']),
                'border-transparent text-niquel hover:text-grafito' => ! request()->routeIs($parte['ruta']),
            ])
        >{{ $parte['texto'] }}</a>
    @endforeach
</nav>
