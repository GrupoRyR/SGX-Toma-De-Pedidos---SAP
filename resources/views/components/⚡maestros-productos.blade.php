<?php

use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServicioMaestros;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Maestro de productos: lista de precios.
 *
 * Un precio mal puesto es lo mas caro que puede pasar en esta aplicacion, asi
 * que cada cambio queda en la bitacora con el antes y el despues.
 */
new class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: 'activos')]
    public string $estado = 'activos';

    public bool $agregando = false;

    public array $nuevo = [];

    public string $error = '';

    public function mount(): void
    {
        Gate::authorize('administrar', Usuario::class);
        $this->limpiarNuevo();
    }

    private function limpiarNuevo(): void
    {
        $this->nuevo = ['codigo' => '', 'descripcion' => '', 'familia' => '', 'precio_lista' => ''];
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function updatedEstado(): void
    {
        $this->resetPage();
    }

    public function crear(ServicioMaestros $servicio): void
    {
        Gate::authorize('administrar', Usuario::class);
        $this->error = '';

        try {
            $producto = $servicio->crearProducto($this->nuevo);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->agregando = false;
        $this->limpiarNuevo();

        session()->flash('aviso', 'Producto creado.');
        $this->redirectRoute('maestros-producto', ['producto' => $producto], navigate: true);
    }

    public function with(): array
    {
        $consulta = Producto::query()->where('activo', $this->estado !== 'inactivos');

        $texto = trim($this->buscar);

        if ($texto !== '') {
            $consulta->where(fn ($q) => $q->where('codigo', 'like', $texto.'%')
                ->orWhere('descripcion', 'like', '%'.$texto.'%'));
        }

        return [
            'productos' => $consulta->orderBy('descripcion')->paginate(30),
            'familias' => $this->agregando
                ? Producto::whereNotNull('familia')->distinct()->orderBy('familia')->pluck('familia')
                : collect(),
            'inactivos' => Producto::where('activo', false)->count(),
        ];
    }
}; ?>

<div>
    <x-admin-nav />

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    @if ($agregando)
        <section class="mb-4 rounded-lg bg-white p-4">
            <h2 class="font-titulo mb-1 font-semibold">Nuevo producto</h2>
            <p class="mb-3 text-sm text-niquel">
                El código tiene que ser el mismo de SAP (ItemCode): es el que viaja en la plantilla de pedidos.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Código</span>
                    <input type="text" wire:model="nuevo.codigo" autocomplete="off" autocapitalize="characters"
                           class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Precio de lista (COP)</span>
                    <input type="number" inputmode="decimal" min="0" step="1" wire:model="nuevo.precio_lista"
                           class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs text-niquel">Descripción</span>
                    <input type="text" wire:model="nuevo.descripcion" autocomplete="off"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs text-niquel">Familia</span>
                    <input type="text" wire:model="nuevo.familia" autocomplete="off" list="familias"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                    <datalist id="familias">
                        @foreach ($familias as $familia)
                            <option value="{{ $familia }}">
                        @endforeach
                    </datalist>
                </label>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="crear"
                        class="flex-1 rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                    Crear producto
                </button>
                <button type="button" wire:click="$set('agregando', false)"
                        class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">Cancelar</button>
            </div>
        </section>
    @else
        <div class="mb-4 flex gap-2">
            <button type="button" wire:click="$set('agregando', true)"
                    class="flex-1 rounded-lg border border-acero-hondo bg-white px-4 py-3 text-sm font-medium hover:bg-acero">
                Nuevo producto
            </button>
        </div>
    @endif

    <div class="mb-3 space-y-3">
        <label class="block">
            <span class="sr-only">Buscar por código o descripción</span>
            <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por código o descripción"
                   autocomplete="off"
                   class="w-full rounded-lg border border-acero-hondo bg-white px-4 py-3 text-base
                          placeholder:text-niquel-claro focus:border-naranja focus:outline-none">
        </label>

        <div class="flex gap-2">
            <button type="button" wire:click="$set('estado', 'activos')"
                @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                    'bg-grafito text-white' => $estado !== 'inactivos',
                    'bg-white text-grafito-suave' => $estado === 'inactivos'])>Activos</button>
            <button type="button" wire:click="$set('estado', 'inactivos')"
                @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                    'bg-grafito text-white' => $estado === 'inactivos',
                    'bg-white text-grafito-suave' => $estado !== 'inactivos'])>Inactivos (<span class="cifras">{{ $inactivos }}</span>)</button>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg bg-white">
        @forelse ($productos as $producto)
            <a href="{{ route('maestros-producto', $producto) }}" wire:navigate wire:key="p-{{ $producto->id }}"
               class="block border-b border-acero px-4 py-3.5 last:border-b-0 hover:bg-acero/60">
                <div class="flex items-baseline gap-2">
                    <span class="cifras shrink-0 text-sm text-niquel">{{ $producto->codigo }}</span>
                    <span class="min-w-0 flex-1 font-medium leading-snug">{{ $producto->descripcion }}</span>
                    <span class="cifras shrink-0 text-sm">${{ number_format((float) $producto->precio_lista, 0, ',', '.') }}</span>
                </div>
                <p class="mt-0.5 text-sm text-niquel">{{ $producto->familia ?: 'Sin familia' }}</p>
            </a>
        @empty
            <div class="px-6 py-14 text-center">
                <p class="font-medium">Ningún producto coincide</p>
                <p class="mt-1 text-sm text-niquel">Busca por el comienzo del código o por parte de la descripción.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $productos->links() }}</div>
</div>
