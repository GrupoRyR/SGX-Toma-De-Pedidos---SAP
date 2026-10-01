<?php

use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServicioMaestros;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Ficha de un producto en el maestro.
 *
 * El precio de lista es lo que lee la calculadora en cada pedido nuevo: los
 * pedidos ya hechos conservan el precio con el que se hicieron.
 */
new class extends Component
{
    public Producto $producto;

    public array $datos = [];

    public string $error = '';

    public string $aviso = '';

    public function mount(Producto $producto): void
    {
        Gate::authorize('administrar', Usuario::class);

        $this->producto = $producto;
        $this->cargarDatos();
        $this->aviso = (string) session('aviso', '');
    }

    private function cargarDatos(): void
    {
        $p = $this->producto;

        $this->datos = [
            'codigo' => $p->codigo,
            'descripcion' => $p->descripcion,
            'familia' => (string) $p->familia,
            'precio_lista' => (string) (float) $p->precio_lista,
        ];
    }

    public function guardar(ServicioMaestros $servicio): void
    {
        Gate::authorize('administrar', Usuario::class);
        $this->error = '';
        $this->aviso = '';

        try {
            $servicio->actualizarProducto($this->producto, $this->datos);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->producto->refresh();
        $this->cargarDatos();
        $this->aviso = 'Cambios guardados.';
    }

    /** Desactivar en vez de borrar: los pedidos viejos lo siguen nombrando. */
    public function cambiarActivo(ServicioMaestros $servicio): void
    {
        Gate::authorize('administrar', Usuario::class);
        $this->error = '';

        $servicio->actualizarProducto($this->producto, ['activo' => ! $this->producto->activo]);
        $this->producto->refresh();
        $this->aviso = $this->producto->activo ? 'Producto activado.' : 'Producto desactivado. Ya no aparece en los pedidos nuevos.';
    }

    public function with(): array
    {
        return [
            'familias' => Producto::whereNotNull('familia')->distinct()->orderBy('familia')->pluck('familia'),
        ];
    }
}; ?>

<div>
    <a href="{{ route('maestros-productos') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-niquel hover:text-grafito">
        <svg class="size-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Productos
    </a>

    <div class="mb-1 flex flex-wrap items-center gap-2">
        <h1 class="font-titulo text-2xl font-semibold tracking-tight">{{ $producto->descripcion }}</h1>
        @if (! $producto->activo)
            <span class="rounded bg-acero px-1.5 py-0.5 text-xs text-niquel">Inactivo</span>
        @endif
    </div>

    <p class="mb-4 text-sm text-niquel">
        <span class="cifras">{{ $producto->codigo }}</span>
        · <span class="cifras">${{ number_format((float) $producto->precio_lista, 0, ',', '.') }}</span>
    </p>

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    @if ($aviso)
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-900">{{ $aviso }}</p>
    @endif

    <section class="mb-4 rounded-lg bg-white p-4">
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-xs text-niquel">Código</span>
                <input type="text" wire:model="datos.codigo" autocomplete="off" autocapitalize="characters"
                       class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-niquel">Precio de lista (COP)</span>
                <input type="number" inputmode="decimal" min="0" step="1" wire:model="datos.precio_lista"
                       class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
            </label>

            <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs text-niquel">Descripción</span>
                <input type="text" wire:model="datos.descripcion" autocomplete="off"
                       class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
            </label>

            <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs text-niquel">Familia</span>
                <input type="text" wire:model="datos.familia" autocomplete="off" list="familias"
                       class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                <datalist id="familias">
                    @foreach ($familias as $familia)
                        <option value="{{ $familia }}">
                    @endforeach
                </datalist>
            </label>
        </div>

        <button type="button" wire:click="guardar"
                class="mt-4 w-full rounded-lg bg-naranja px-4 py-3 font-semibold text-white hover:bg-naranja-hondo">
            Guardar cambios
        </button>
    </section>

    <button type="button" wire:click="cambiarActivo"
            wire:confirm="{{ $producto->activo ? '¿Desactivar '.$producto->codigo.'? Deja de aparecer en los pedidos nuevos.' : '¿Volver a activar '.$producto->codigo.'?' }}"
            class="mt-2 w-full rounded-lg px-4 py-3 text-sm text-niquel hover:text-red-700">
        {{ $producto->activo ? 'Desactivar producto' : 'Activar producto' }}
    </button>
</div>
