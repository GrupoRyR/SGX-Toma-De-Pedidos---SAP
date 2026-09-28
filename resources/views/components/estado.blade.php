@props(['estado'])

{{--
    Distintivo de estado del pedido.

    Los colores son los que el equipo ya reconoce de la app de Power Apps:
    pendiente morado, aprobado verde, rechazado rojo. Se conservan a proposito
    para que nadie tenga que reaprender el codigo de colores.
--}}
@php
    $tonos = [
        'BORRADOR' => 'bg-acero text-niquel',
        'PENDIENTE' => 'bg-purple-100 text-purple-800',
        'APROBADO' => 'bg-green-100 text-green-800',
        'RECHAZADO' => 'bg-red-100 text-red-800',
        'LIBERADO' => 'bg-blue-100 text-blue-800',
        'IMPORTADO' => 'bg-green-800 text-white',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-block rounded px-1.5 py-0.5 text-xs font-medium '.($tonos[$estado->value] ?? 'bg-acero text-niquel')]) }}>
    {{ $estado->etiqueta() }}
</span>
