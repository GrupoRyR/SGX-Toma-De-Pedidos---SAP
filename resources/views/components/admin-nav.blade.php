{{--
    Sub-navegacion de administracion.

    Va dentro de la seccion en vez de sumar pestanas al encabezado: el asesor,
    que es casi todo el mundo, no tiene por que ver estas pestanas.

    'activa' son los nombres de ruta que encienden la pestana: la ficha de un
    cliente sigue siendo la pestana Clientes.
--}}
@php
    $partes = [
        ['ruta' => 'usuarios', 'texto' => 'Usuarios', 'activa' => ['usuarios', 'usuario']],
        ['ruta' => 'maestros-clientes', 'texto' => 'Clientes', 'activa' => ['maestros-clientes', 'maestros-cliente']],
        ['ruta' => 'maestros-productos', 'texto' => 'Productos', 'activa' => ['maestros-productos', 'maestros-producto']],
        ['ruta' => 'maestros-carteras', 'texto' => 'Carteras', 'activa' => ['maestros-carteras']],
        ['ruta' => 'maestros-cargar', 'texto' => 'Cargar archivo', 'activa' => ['maestros-cargar']],
        ['ruta' => 'configuracion', 'texto' => 'Configuración', 'activa' => ['configuracion']],
        ['ruta' => 'plantillas', 'texto' => 'Plantillas SAP', 'activa' => ['plantillas']],
        ['ruta' => 'registros', 'texto' => 'Registros', 'activa' => ['registros']],
    ];
@endphp

<nav class="mb-4 flex gap-1 overflow-x-auto border-b border-acero-hondo">
    @foreach ($partes as $parte)
        <a href="{{ route($parte['ruta']) }}" wire:navigate
            @class([
                'shrink-0 -mb-px border-b-2 px-3 pb-2.5 text-sm font-medium',
                'border-naranja text-grafito' => request()->routeIs(...$parte['activa']),
                'border-transparent text-niquel hover:text-grafito' => ! request()->routeIs(...$parte['activa']),
            ])
        >{{ $parte['texto'] }}</a>
    @endforeach
</nav>
