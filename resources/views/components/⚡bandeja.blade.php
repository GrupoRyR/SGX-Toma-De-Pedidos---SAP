<?php

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bandeja de pedidos por aprobar.
 *
 * Arranca en PENDIENTE porque es lo unico que exige una accion. Todo el
 * filtrado y la paginacion corren en el servidor, con la regla de visibilidad
 * en la consulta: un gerente solo ve los pedidos de su canal.
 */
new class extends Component
{
    use WithPagination;

    #[Url(as: 'estado')]
    public string $estado = 'PENDIENTE';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public function updatedEstado(): void
    {
        $this->resetPage();
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $consulta = Pedido::query()
            ->visiblePara(Auth::user())
            ->with(['creador:id,nombre', 'cliente:id,canal_id', 'cliente.canal:id,nombre'])
            ->withCount('lineas');

        if ($this->estado !== 'TODOS') {
            $consulta->where('estado', $this->estado);
        }

        $texto = trim($this->buscar);

        if ($texto !== '') {
            $consulta->where(function ($q) use ($texto) {
                $q->where('nombre_cliente', 'like', '%'.$texto.'%')
                    ->orWhere('codigo_cliente', 'like', $texto.'%');

                if (ctype_digit($texto)) {
                    $q->orWhere('id', (int) $texto);
                }
            });
        }

        // Los conteos por estado se calculan sobre lo que este usuario alcanza,
        // no sobre toda la tabla.
        $conteos = Pedido::query()
            ->visiblePara(Auth::user())
            ->selectRaw('estado, count(*) as cuantos')
            ->groupBy('estado')
            ->pluck('cuantos', 'estado');

        return [
            'pedidos' => $consulta->latest('id')->paginate(25),
            'conteos' => $conteos,
            'estados' => EstadoPedido::cases(),
        ];
    }
}; ?>

<div>
    <h1 class="font-titulo mb-4 text-2xl font-semibold tracking-tight">Pedidos por aprobar</h1>

    <div class="sticky top-0 z-10 -mx-4 mb-1 space-y-3 bg-acero px-4 pb-3 pt-1">
        <label class="block">
            <span class="sr-only">Buscar por número de pedido o nombre de cliente</span>
            <input
                type="search"
                wire:model.live.debounce.300ms="buscar"
                placeholder="Buscar por número o cliente"
                autocomplete="off"
                class="w-full rounded-lg border border-acero-hondo bg-white py-3 px-4 text-base
                       placeholder:text-niquel-claro focus:border-naranja focus:outline-none"
            >
        </label>

        {{-- Los estados se desplazan en horizontal: en celular no caben todos. --}}
        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
            @foreach ($estados as $caso)
                <button
                    type="button"
                    wire:click="$set('estado', '{{ $caso->value }}')"
                    @class([
                        'shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                        'bg-grafito text-white' => $estado === $caso->value,
                        'bg-white text-grafito-suave hover:bg-white/70' => $estado !== $caso->value,
                    ])
                >
                    {{ $caso->etiqueta() }}
                    @if (($conteos[$caso->value] ?? 0) > 0)
                        <span class="cifras ml-1 opacity-70">{{ $conteos[$caso->value] }}</span>
                    @endif
                </button>
            @endforeach
            <button type="button" wire:click="$set('estado', 'TODOS')"
                @class([
                    'shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                    'bg-grafito text-white' => $estado === 'TODOS',
                    'bg-white text-grafito-suave' => $estado !== 'TODOS',
                ])>Todos</button>
        </div>
    </div>

    <div wire:loading.class="opacity-50" class="overflow-hidden rounded-lg bg-white transition-opacity">
        @forelse ($pedidos as $pedido)
            <a href="{{ route('pedido', $pedido) }}" wire:navigate
               class="flex items-start gap-3 border-b border-acero px-4 py-4 last:border-b-0 hover:bg-acero/60">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="cifras font-medium">#{{ $pedido->id }}</span>
                        <x-estado :estado="$pedido->estado" />
                        @if ($pedido->bloqueadoAhora())
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">En edición</span>
                        @endif
                    </div>

                    <p class="mt-1 font-medium leading-snug">{{ $pedido->nombre_cliente }}</p>

                    <p class="mt-0.5 text-sm text-niquel">
                        {{ $pedido->creador?->nombre }}
                        @if ($pedido->cliente?->canal)
                            · {{ $pedido->cliente->canal->nombre }}
                        @endif
                        <br>
                        <span class="cifras">{{ $pedido->created_at->format('d/m/Y') }}</span>
                        · {{ $pedido->lineas_count }} {{ $pedido->lineas_count === 1 ? 'producto' : 'productos' }}
                    </p>
                </div>

                <span class="shrink-0 text-right">
                    <span class="cifras block font-medium">$ {{ number_format($pedido->subtotal, 0, ',', '.') }}</span>
                    <span class="block text-xs text-niquel">Valor sin IVA</span>
                </span>
            </a>
        @empty
            <div class="px-6 py-14 text-center">
                @if ($estado === 'PENDIENTE' && trim($buscar) === '')
                    <p class="font-medium">No hay pedidos esperando aprobación</p>
                    <p class="mt-1 text-sm text-niquel">Cuando un asesor envíe uno, aparecerá aquí.</p>
                @else
                    <p class="font-medium">Ningún pedido coincide</p>
                    <p class="mt-1 text-sm text-niquel">Prueba con otro estado o con el número del pedido.</p>
                @endif
            </div>
        @endforelse
    </div>

    @if ($pedidos->hasPages())
        <div class="mt-4">{{ $pedidos->links() }}</div>
    @endif
</div>
