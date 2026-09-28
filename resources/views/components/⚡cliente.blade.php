<?php

use App\Models\Cliente;
use App\Models\Pedido;
use App\Services\ServicioPedidos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Ficha del cliente: sus datos, sus pedidos y el boton para empezar uno nuevo.
 */
new class extends Component
{
    public Cliente $cliente;

    public function mount(Cliente $cliente): void
    {
        // Segunda capa de autorizacion: la consulta ya filtra, pero esta ruta
        // recibe un id y hay que frenar el cambio de numero en la URL.
        Gate::authorize('ver', $cliente);

        $this->cliente = $cliente;
    }

    public function nuevoPedido(ServicioPedidos $servicio)
    {
        Gate::authorize('crearPedido', $this->cliente);

        $pedido = $servicio->crear($this->cliente, Auth::user());

        return $this->redirectRoute('pedido', ['pedido' => $pedido->id], navigate: true);
    }

    public function with(): array
    {
        return [
            'pedidos' => Pedido::query()
                ->where('cliente_id', $this->cliente->id)
                ->visiblePara(Auth::user())
                ->withCount('lineas')
                ->latest('id')
                ->limit(30)
                ->get(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('clientes') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-niquel hover:text-grafito">
        <svg class="size-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Mis clientes
    </a>

    <div class="rounded-lg bg-white p-5">
        <p class="cifras text-xs font-medium text-niquel">{{ $cliente->codigo_sn }}</p>
        <h1 class="font-titulo mt-1 text-xl font-semibold leading-tight tracking-tight">{{ $cliente->nombre }}</h1>

        <dl class="mt-4 space-y-1.5 text-sm">
            <div class="flex gap-2">
                <dt class="w-24 shrink-0 text-niquel">Dirección</dt>
                <dd>{{ $cliente->direccion ?: '—' }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-24 shrink-0 text-niquel">Ciudad</dt>
                <dd>{{ $cliente->ciudad ?: '—' }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-24 shrink-0 text-niquel">Descuento</dt>
                <dd class="cifras">{{ rtrim(rtrim(number_format($cliente->porcentaje_descuento, 2, ',', '.'), '0'), ',') }}%</dd>
            </div>
        </dl>

        <button
            type="button"
            wire:click="nuevoPedido"
            wire:loading.attr="disabled"
            class="mt-5 w-full rounded-lg bg-naranja px-4 py-3.5 font-semibold text-white
                   hover:bg-naranja-hondo disabled:opacity-60"
        >
            <span wire:loading.remove wire:target="nuevoPedido">Nuevo pedido</span>
            <span wire:loading wire:target="nuevoPedido">Creando…</span>
        </button>
    </div>

    <h2 class="font-titulo mb-3 mt-8 text-lg font-semibold tracking-tight">Pedidos de este cliente</h2>

    <div class="overflow-hidden rounded-lg bg-white">
        @forelse ($pedidos as $pedido)
            <a
                href="{{ route('pedido', $pedido) }}"
                wire:navigate
                class="flex items-center gap-3 border-b border-acero px-4 py-3.5 last:border-b-0 hover:bg-acero/60"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="cifras font-medium">#{{ $pedido->id }}</span>
                        <x-estado :estado="$pedido->estado" />
                    </div>
                    <p class="mt-0.5 text-sm text-niquel">
                        {{ $pedido->created_at->format('d/m/Y') }}
                        · {{ $pedido->lineas_count }} {{ $pedido->lineas_count === 1 ? 'producto' : 'productos' }}
                    </p>
                    @if ($pedido->motivo_rechazo)
                        <p class="mt-1 text-sm text-red-700">{{ $pedido->motivo_rechazo }}</p>
                    @endif
                </div>

                <span class="cifras shrink-0 font-medium">$ {{ number_format($pedido->total, 0, ',', '.') }}</span>
            </a>
        @empty
            <div class="px-6 py-10 text-center">
                <p class="font-medium">Este cliente todavía no tiene pedidos</p>
                <p class="mt-1 text-sm text-niquel">El primero que crees aparecerá aquí.</p>
            </div>
        @endforelse
    </div>
</div>
