<?php

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Por liberar a SAP.
 *
 * El segundo control que pidio SEGUREX: ningun pedido llega a SAP solo por
 * estar aprobado. Cesar Garzon o Marly Ossa lo revisan y le dan el visto bueno,
 * pedido por pedido. No hay liberacion en lote a proposito.
 *
 * Como no hay notificaciones automaticas, esta pantalla tiene que mostrar por
 * si sola cuando algo lleva demasiado tiempo esperando: es el unico aviso de
 * que la cola se atasco.
 */
new class extends Component
{
    use WithPagination;

    public function mount(): void
    {
        abort_unless(Auth::user()->puedeLiberarASap(), 403);
    }

    public function with(): array
    {
        $esperando = Pedido::query()
            ->where('estado', EstadoPedido::APROBADO)
            ->with(['creador:id,nombre', 'aprobador:id,nombre'])
            ->withCount('lineas')
            ->orderBy('fecha_aprobacion');

        $masAntiguo = (clone $esperando)->first();

        return [
            'pedidos' => $esperando->paginate(25),
            'masAntiguo' => $masAntiguo,
            'enSap' => Pedido::listosParaSap()->count(),
            // Pedidos que ya se liberaron y SAP rechazo. Van arriba: son los
            // unicos que estan detenidos esperando que alguien haga algo.
            'fallidos' => Pedido::query()
                ->where('estado', EstadoPedido::LIBERADO)
                ->where('importado_sap', false)
                ->whereNotNull('sap_error')
                ->orderByDesc('sap_intentos')
                ->get(),
        ];
    }
}; ?>

<div>
    <h1 class="font-titulo mb-1 text-2xl font-semibold tracking-tight">Por liberar a SAP</h1>
    <p class="mb-4 text-sm text-niquel">
        Nada entra a SAP sin tu visto bueno. Revisa cada pedido antes de liberarlo.
    </p>

    {{-- Lo que SAP rechazo. Va primero porque esta detenido: no avanza solo. --}}
    @if ($fallidos->isNotEmpty())
        <section class="mb-5">
            <h2 class="font-titulo mb-2 font-semibold text-red-900">Fallaron en SAP</h2>

            <div class="overflow-hidden rounded-lg border border-red-200 bg-white">
                @foreach ($fallidos as $fallido)
                    <a href="{{ route('pedido', $fallido) }}" wire:navigate
                       class="block border-b border-red-100 px-4 py-3.5 last:border-b-0 hover:bg-red-50/60">
                        <div class="flex items-start justify-between gap-3">
                            <span class="cifras font-medium">#{{ $fallido->id }}</span>
                            <span class="cifras shrink-0 text-sm text-niquel">
                                {{ $fallido->sap_intentos }}
                                {{ $fallido->sap_intentos == 1 ? 'intento' : 'intentos' }}
                            </span>
                        </div>

                        <p class="mt-0.5 font-medium leading-snug">{{ $fallido->nombre_cliente }}</p>
                        <p class="mt-1 text-sm text-red-800">{{ $fallido->sap_error }}</p>

                        @if ($fallido->sap_intentos >= \App\Services\PuenteSap::MAXIMO_INTENTOS)
                            <p class="mt-1 text-sm text-niquel">
                                El robot ya no lo va a reintentar. Hay que corregirlo en SAP o devolverlo.
                            </p>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- El aviso de cola atascada. Reemplaza la alerta por correo que se
         descarto: si nadie libera, esto es lo unico que lo delata. --}}
    @if ($masAntiguo && $masAntiguo->fecha_aprobacion?->lt(now()->subDay()))
        <p class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            El pedido <strong>#{{ $masAntiguo->id }}</strong> lleva
            {{ $masAntiguo->fecha_aprobacion->diffForHumans(null, true) }} esperando liberación.
        </p>
    @endif

    @if ($enSap > 0)
        <p class="mb-4 text-sm text-niquel">
            {{ $enSap === 1
                ? 'Un pedido liberado espera a que el robot lo cree en SAP.'
                : $enSap.' pedidos liberados esperan a que el robot los cree en SAP.' }}
        </p>
    @endif

    <div class="overflow-hidden rounded-lg bg-white">
        @forelse ($pedidos as $pedido)
            <a href="{{ route('pedido', $pedido) }}" wire:navigate
               class="flex items-start gap-3 border-b border-acero px-4 py-4 last:border-b-0 hover:bg-acero/60">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="cifras font-medium">#{{ $pedido->id }}</span>

                        {{-- Senales de riesgo. Son lo que justifica revisar uno
                             por uno en vez de liberar a ciegas. --}}
                        @if ($pedido->lineas()->whereNotNull('precio_manual')->exists())
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">Precio manual</span>
                        @endif
                        @if ($pedido->lineas()->whereNotNull('atp_descuento_pct')->where('atp_descuento_pct', '>', 0)->exists())
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">Con ATP</span>
                        @endif
                        @if ($pedido->creado_por === $pedido->aprobado_por)
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">Autoaprobado</span>
                        @endif
                        @if ($pedido->bloqueadoAhora())
                            <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-900">En edición</span>
                        @endif
                    </div>

                    <p class="mt-1 font-medium leading-snug">{{ $pedido->nombre_cliente }}</p>

                    <p class="mt-0.5 text-sm text-niquel">
                        {{ $pedido->creador?->nombre }} · aprobó {{ $pedido->aprobador?->nombre }}<br>
                        <span class="cifras">{{ $pedido->fecha_aprobacion?->format('d/m/Y H:i') }}</span>
                        · {{ $pedido->lineas_count }} {{ $pedido->lineas_count === 1 ? 'producto' : 'productos' }}
                    </p>
                </div>

                <span class="cifras shrink-0 font-medium">$ {{ number_format($pedido->total, 0, ',', '.') }}</span>
            </a>
        @empty
            <div class="px-6 py-14 text-center">
                <p class="font-medium">No hay nada esperando tu visto bueno</p>
                <p class="mt-1 text-sm text-niquel">Los pedidos aprobados aparecerán aquí para que los revises.</p>
            </div>
        @endforelse
    </div>

    @if ($pedidos->hasPages())
        <div class="mt-4">{{ $pedidos->links() }}</div>
    @endif
</div>
