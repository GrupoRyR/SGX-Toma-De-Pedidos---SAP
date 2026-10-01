<?php

use App\Models\AsesorSap;
use App\Models\Usuario;
use App\Services\ServicioCarteras;
use App\Support\FormatoMaestros;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Carteras SAP: crear, renombrar y activar o desactivar.
 *
 * Son pocas (unas veinte), asi que van todas en una lista sin paginar. El
 * numero no se edita: es la llave con SAP y la carga por archivo cruza por el.
 */
new class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public bool $agregando = false;

    public array $nuevo = ['numero' => '', 'nombre' => ''];

    public ?int $editando = null;

    public string $nombreEditado = '';

    public string $error = '';

    public string $aviso = '';

    public function mount(): void
    {
        Gate::authorize('administrar', Usuario::class);
    }

    private function empezar(): void
    {
        Gate::authorize('administrar', Usuario::class);
        $this->error = '';
        $this->aviso = '';
    }

    public function crear(ServicioCarteras $servicio): void
    {
        $this->empezar();

        $numero = trim((string) $this->nuevo['numero']);

        if (! ctype_digit($numero)) {
            $this->error = 'El numero de la cartera tiene que ser un entero mayor que cero.';

            return;
        }

        try {
            $cartera = $servicio->crear((int) $numero, (string) $this->nuevo['nombre'], auth()->user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->agregando = false;
        $this->nuevo = ['numero' => '', 'nombre' => ''];
        $this->aviso = "Cartera {$cartera->codigo_texto} creada. Asígnala a un asesor desde Usuarios para que vea sus clientes.";
    }

    public function editar(int $id): void
    {
        $this->empezar();

        $cartera = AsesorSap::findOrFail($id);
        $this->editando = $cartera->id;
        $this->nombreEditado = (string) ($cartera->nombre ?? FormatoMaestros::nombreDelAsesor($cartera->codigo_texto));
    }

    public function cancelarEdicion(): void
    {
        $this->editando = null;
        $this->nombreEditado = '';
    }

    public function guardarNombre(ServicioCarteras $servicio): void
    {
        $this->empezar();

        try {
            $servicio->renombrar(AsesorSap::findOrFail($this->editando), $this->nombreEditado, auth()->user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->cancelarEdicion();
        $this->aviso = 'Nombre guardado. Los clientes y los asesores siguen en la misma cartera.';
    }

    public function cambiarActivo(int $id, bool $activo, ServicioCarteras $servicio): void
    {
        $this->empezar();

        $servicio->cambiarActivo(AsesorSap::findOrFail($id), $activo, auth()->user());
    }

    public function with(): array
    {
        $consulta = AsesorSap::query()->withCount('clientes')->with('usuarios');

        $texto = trim($this->buscar);

        if ($texto !== '') {
            $consulta->where(fn ($q) => $q->where('codigo_texto', 'like', '%'.$texto.'%')
                ->when(ctype_digit($texto), fn ($q) => $q->orWhere('numero', (int) $texto)));
        }

        return [
            'carteras' => $consulta->orderBy('numero')->get(),
        ];
    }
}; ?>

<div>
    <x-admin-nav />

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    @if ($aviso)
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-900">{{ $aviso }}</p>
    @endif

    @if ($agregando)
        <section class="mb-4 rounded-lg bg-white p-4">
            <h2 class="font-titulo mb-1 font-semibold">Nueva cartera</h2>
            <p class="mb-3 text-sm text-niquel">
                Usa el mismo número que tiene la cartera en SAP. Después no se puede cambiar: es con el que se reconoce al cargar clientes.
            </p>

            <div class="grid gap-3 sm:grid-cols-4">
                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Número</span>
                    <input type="number" inputmode="numeric" min="1" step="1" wire:model="nuevo.numero"
                           class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block sm:col-span-3">
                    <span class="mb-1 block text-xs text-niquel">Nombre del asesor</span>
                    <input type="text" wire:model="nuevo.nombre" autocomplete="off" autocapitalize="characters"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="crear"
                        class="flex-1 rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                    Crear cartera
                </button>
                <button type="button" wire:click="$set('agregando', false)"
                        class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">Cancelar</button>
            </div>
        </section>
    @else
        <div class="mb-4 flex gap-2">
            <button type="button" wire:click="$set('agregando', true)"
                    class="flex-1 rounded-lg border border-acero-hondo bg-white px-4 py-3 text-sm font-medium hover:bg-acero">
                Nueva cartera
            </button>
        </div>
    @endif

    <label class="mb-3 block">
        <span class="sr-only">Buscar por número o nombre</span>
        <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por número o nombre"
               autocomplete="off"
               class="w-full rounded-lg border border-acero-hondo bg-white px-4 py-3 text-base
                      placeholder:text-niquel-claro focus:border-naranja focus:outline-none">
    </label>

    <div class="overflow-hidden rounded-lg bg-white">
        @forelse ($carteras as $cartera)
            <div wire:key="c-{{ $cartera->id }}" class="border-b border-acero px-4 py-3.5 last:border-b-0">
                @if ($editando === $cartera->id)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="cifras shrink-0 text-sm text-niquel">{{ $cartera->numero }}</span>
                        <label class="min-w-0 flex-1">
                            <span class="sr-only">Nuevo nombre de la cartera {{ $cartera->numero }}</span>
                            <input type="text" wire:model="nombreEditado" wire:keydown.enter="guardarNombre" autocomplete="off"
                                   class="w-full rounded-lg border border-acero-hondo px-3 py-2 focus:border-naranja focus:outline-none">
                        </label>
                        <button type="button" wire:click="guardarNombre"
                                class="rounded-lg bg-grafito px-3 py-2 text-sm font-semibold text-white hover:bg-grafito-suave">Guardar</button>
                        <button type="button" wire:click="cancelarEdicion"
                                class="rounded-lg px-3 py-2 text-sm text-niquel hover:text-grafito">Cancelar</button>
                    </div>
                @else
                    <div class="flex items-baseline gap-2">
                        <span @class(['min-w-0 flex-1 font-medium leading-snug', 'text-niquel' => ! $cartera->activo])>{{ $cartera->codigo_texto }}</span>
                        <span class="cifras shrink-0 text-sm text-niquel">{{ $cartera->clientes_count }} {{ $cartera->clientes_count === 1 ? 'cliente' : 'clientes' }}</span>
                    </div>
                    <p class="mt-0.5 text-sm text-niquel">
                        {{ $cartera->usuarios->isEmpty() ? 'Ningún asesor la tiene asignada' : $cartera->usuarios->pluck('nombre')->join(', ') }}
                        {{ $cartera->activo ? '' : '· Inactiva' }}
                    </p>
                    <div class="mt-2 flex gap-3 text-sm">
                        <button type="button" wire:click="editar({{ $cartera->id }})"
                                class="font-medium text-grafito-suave hover:text-grafito">Renombrar</button>
                        @if ($cartera->activo)
                            <button type="button" wire:click="cambiarActivo({{ $cartera->id }}, false)"
                                    @if ($cartera->clientes_count > 0)
                                        wire:confirm="La cartera {{ $cartera->numero }} tiene clientes ({{ $cartera->clientes_count }}). Desactivarla solo la saca de las listas para asignar: sus clientes los sigue viendo su asesor. ¿Desactivarla?"
                                    @endif
                                    class="text-niquel hover:text-grafito">Desactivar</button>
                        @else
                            <button type="button" wire:click="cambiarActivo({{ $cartera->id }}, true)"
                                    class="text-niquel hover:text-grafito">Activar</button>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="px-6 py-14 text-center">
                <p class="font-medium">Ninguna cartera coincide</p>
                <p class="mt-1 text-sm text-niquel">Busca por el número o por parte del nombre.</p>
            </div>
        @endforelse
    </div>
</div>
