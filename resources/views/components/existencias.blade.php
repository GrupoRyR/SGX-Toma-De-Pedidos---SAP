@props(['disponible' => null, 'corte' => null, 'pedida' => null])

{{--
    Existencias de SAP.

    Es una foto, no el dato en vivo, y eso tiene que ser evidente: por eso
    siempre va la fecha del corte al lado. Si nadie ve que el dato es de ayer,
    lo va a tomar como una reserva.

    Un dato viejo se muestra atenuado, no se esconde: media informacion marcada
    como vieja sirve mas que ninguna.
--}}
@php
    $hayDato = $corte !== null;
    $corte = $corte ? \Illuminate\Support\Carbon::parse($corte) : null;
    $horas = (int) \App\Models\Configuracion::valor('horas_inventario_viejo', 24);
    $viejo = $corte?->lt(now()->subHours($horas)) ?? true;
    $cantidad = (float) ($disponible ?? 0);
    $falta = $pedida !== null && (float) $pedida > $cantidad;
@endphp

@if (! $hayDato)
    {{-- Sin corte todavia: el robot no ha publicado nada. Callar es mejor que
         mostrar un cero que nadie puede interpretar. --}}
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-xs '.($viejo ? 'text-niquel-claro' : ($falta ? 'text-red-700' : 'text-niquel'))]) }}>
        <span class="cifras font-medium">{{ rtrim(rtrim(number_format($cantidad, 2, ',', '.'), '0'), ',') }}</span>
        {{ $cantidad == 1 ? 'disponible' : 'disponibles' }}
        {{-- El corte lo manda el robot con su propio reloj. Si viene adelantado,
             "en 4 horas" no le dice nada a nadie: se muestra como recien tomado. --}}
        <span class="text-niquel-claro">· {{ $corte->isFuture() ? 'hace un momento' : $corte->diffForHumans() }}</span>
    </span>
@endif
