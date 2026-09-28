<?php

use App\Models\Cliente;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Mis clientes.
 *
 * Todo el filtrado ocurre en el servidor. La app vieja bajaba las listas
 * completas y filtraba en el navegador, que es de donde venia la lentitud y el
 * tope de 500 filas.
 */
new class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function limpiar(): void
    {
        $this->buscar = '';
        $this->resetPage();
    }

    public function with(): array
    {
        // visiblePara es la regla de seguridad y va en la consulta: un asesor
        // que manipule la URL tampoco alcanza clientes ajenos.
        $consulta = Cliente::query()
            ->visiblePara(Auth::user())
            ->where('activo', true);

        $texto = trim($this->buscar);

        if ($texto !== '') {
            $consulta->where(function ($q) use ($texto) {
                $q->where('nombre', 'like', '%'.$texto.'%')
                    ->orWhere('codigo_sn', 'like', $texto.'%')
                    ->orWhere('ciudad', 'like', $texto.'%');
            });
        }

        $clientes = $consulta->orderBy('nombre')->paginate(25);

        return [
            'clientes' => $clientes,
            'total' => $clientes->total(),
        ];
    }
}; ?>

<div>
    <div class="mb-4 flex items-baseline justify-between gap-4">
        <h1 class="font-titulo text-2xl font-semibold tracking-tight">Mis clientes</h1>
        <span class="cifras text-sm text-niquel">{{ $total }}</span>
    </div>

    {{-- La busqueda se queda pegada arriba: con cien clientes se usa todo el tiempo. --}}
    <div class="sticky top-0 z-10 -mx-4 mb-1 bg-acero px-4 pb-3 pt-1">
        <label class="relative block">
            <span class="sr-only">Buscar cliente por nombre, código o ciudad</span>
            <input
                type="search"
                wire:model.live.debounce.300ms="buscar"
                placeholder="Buscar por nombre, código o ciudad"
                autocomplete="off"
                class="w-full rounded-lg border border-acero-hondo bg-white py-3 pl-4 pr-10 text-base
                       placeholder:text-niquel-claro focus:border-naranja focus:outline-none"
            >
            @if ($buscar !== '')
                <button
                    type="button"
                    wire:click="limpiar"
                    class="absolute inset-y-0 right-0 px-4 text-xl leading-none text-niquel hover:text-grafito"
                    aria-label="Borrar búsqueda"
                >&times;</button>
            @endif
        </label>
    </div>

    <div wire:loading.class="opacity-50" class="overflow-hidden rounded-lg bg-white transition-opacity">
        @forelse ($clientes as $cliente)
            {{--
                La fila entera es el area de toque. Antes cada renglon llevaba su
                propio boton naranja, y con cinco clientes en pantalla el naranja
                dejaba de significar "la accion" y ademas le robaba la mitad del
                ancho al nombre, que viene en mayusculas desde SAP y es largo.
            --}}
            <a
                href="{{ route('cliente', $cliente) }}"
                wire:navigate
                class="flex items-center gap-3 border-b border-acero px-4 py-4 last:border-b-0
                       hover:bg-acero/60 focus-visible:bg-acero/60"
            >
                <div class="min-w-0 flex-1">
                    <p class="cifras text-xs font-medium text-niquel">{{ $cliente->codigo_sn }}</p>

                    <h2 class="mt-0.5 font-medium leading-snug">{{ $cliente->nombre }}</h2>

                    <p class="mt-1 text-sm leading-snug text-niquel">
                        @if ($cliente->direccion)
                            {{ $cliente->direccion }}<br>
                        @endif
                        <span class="text-grafito-suave">{{ $cliente->ciudad ?: 'Sin ciudad' }}</span>
                    </p>
                </div>

                @if ($cliente->porcentaje_descuento > 0)
                    {{-- El descuento define el precio que va a cotizar: se alinea
                         a la derecha con cifras tabulares para poder compararlo
                         de un vistazo entre filas. --}}
                    <span class="cifras shrink-0 text-right text-sm font-medium tabular-nums text-grafito-suave">
                        {{ rtrim(rtrim(number_format($cliente->porcentaje_descuento, 2, ',', '.'), '0'), ',') }}%
                    </span>
                @endif

                <svg class="size-4 shrink-0 text-niquel-claro" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
        @empty
            <div class="px-6 py-14 text-center">
                @if ($buscar !== '')
                    <p class="font-medium">Ningún cliente coincide con «{{ $buscar }}»</p>
                    <p class="mt-1 text-sm text-niquel">Prueba con el código SN o con otra parte del nombre.</p>
                    <button type="button" wire:click="limpiar" class="mt-4 text-sm font-medium text-naranja">
                        Ver todos mis clientes
                    </button>
                @else
                    <p class="font-medium">Todavía no tienes clientes asignados</p>
                    <p class="mt-1 text-sm text-niquel">
                        Pide al administrador de ventas que te asigne tu cartera o tus clientes.
                    </p>
                @endif
            </div>
        @endforelse
    </div>

    @if ($clientes->hasPages())
        <div class="mt-4">
            {{ $clientes->links() }}
        </div>
    @endif
</div>
