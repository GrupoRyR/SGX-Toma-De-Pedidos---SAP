<?php

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Services\ServicioMaestros;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Maestro de clientes.
 *
 * Antes un cliente nuevo solo entraba por consola en el servidor. Aqui el admin
 * de ventas lo crea con su cartera, y desde ese momento lo ve el asesor que la
 * tenga asignada.
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
        $this->nuevo = [
            'codigo_sn' => '', 'nombre' => '', 'direccion' => '', 'ciudad' => '',
            'canal_id' => '', 'asesor_sap_id' => '', 'porcentaje_descuento' => '0',
        ];
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
            $cliente = $servicio->crearCliente($this->nuevo);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->agregando = false;
        $this->limpiarNuevo();

        session()->flash('aviso', 'Cliente creado.');
        $this->redirectRoute('maestros-cliente', ['cliente' => $cliente], navigate: true);
    }

    public function with(): array
    {
        $consulta = Cliente::query()
            ->with(['canal', 'asesorSap'])
            ->where('activo', $this->estado !== 'inactivos');

        $texto = trim($this->buscar);

        if ($texto !== '') {
            $consulta->where(fn ($q) => $q->where('codigo_sn', 'like', $texto.'%')
                ->orWhere('nombre', 'like', '%'.$texto.'%'));
        }

        return [
            'clientes' => $consulta->orderBy('nombre')->paginate(30),
            'canales' => Canal::orderBy('nombre')->get(),
            'carteras' => AsesorSap::orderBy('codigo_texto')->get(),
            'inactivos' => Cliente::where('activo', false)->count(),
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
            <h2 class="font-titulo mb-1 font-semibold">Nuevo cliente</h2>
            <p class="mb-3 text-sm text-niquel">
                El código tiene que ser el mismo de SAP: es el que viaja en la plantilla de pedidos.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Código SN</span>
                    <input type="text" wire:model="nuevo.codigo_sn" autocomplete="off" autocapitalize="characters"
                           class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs text-niquel">Nombre o razón social</span>
                    <input type="text" wire:model="nuevo.nombre" autocomplete="off"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Dirección</span>
                    <input type="text" wire:model="nuevo.direccion" autocomplete="off"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Ciudad</span>
                    <input type="text" wire:model="nuevo.ciudad" autocomplete="off"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Canal</span>
                    <select wire:model="nuevo.canal_id"
                            class="w-full rounded-lg border border-acero-hondo bg-white px-3 py-2.5 focus:border-naranja focus:outline-none">
                        <option value="">Sin canal</option>
                        @foreach ($canales as $canal)
                            <option value="{{ $canal->id }}">{{ $canal->nombre }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">% de descuento</span>
                    <input type="number" inputmode="decimal" min="0" max="100" step="0.01" wire:model="nuevo.porcentaje_descuento"
                           class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs text-niquel">Cartera de SAP</span>
                    <select wire:model="nuevo.asesor_sap_id"
                            class="w-full rounded-lg border border-acero-hondo bg-white px-3 py-2.5 focus:border-naranja focus:outline-none">
                        <option value="">Sin cartera</option>
                        @foreach ($carteras as $cartera)
                            <option value="{{ $cartera->id }}">{{ $cartera->codigo_texto }}</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs text-niquel">Lo ve el asesor que tenga asignada esta cartera.</span>
                </label>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="crear"
                        class="flex-1 rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                    Crear cliente
                </button>
                <button type="button" wire:click="$set('agregando', false)"
                        class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">Cancelar</button>
            </div>
        </section>
    @else
        <div class="mb-4 flex gap-2">
            <button type="button" wire:click="$set('agregando', true)"
                    class="flex-1 rounded-lg border border-acero-hondo bg-white px-4 py-3 text-sm font-medium hover:bg-acero">
                Nuevo cliente
            </button>
        </div>
    @endif

    <div class="mb-3 space-y-3">
        <label class="block">
            <span class="sr-only">Buscar por código o nombre</span>
            <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por código o nombre"
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
        @forelse ($clientes as $cliente)
            <a href="{{ route('maestros-cliente', $cliente) }}" wire:navigate wire:key="c-{{ $cliente->id }}"
               class="block border-b border-acero px-4 py-3.5 last:border-b-0 hover:bg-acero/60">
                <div class="flex items-baseline gap-2">
                    <span class="cifras shrink-0 text-sm text-niquel">{{ $cliente->codigo_sn }}</span>
                    <span class="min-w-0 flex-1 font-medium leading-snug">{{ $cliente->nombre }}</span>
                    @if ((float) $cliente->porcentaje_descuento > 0)
                        <span class="cifras shrink-0 text-sm text-niquel">{{ rtrim(rtrim(number_format((float) $cliente->porcentaje_descuento, 2, ',', '.'), '0'), ',') }}%</span>
                    @endif
                </div>
                <p class="mt-0.5 text-sm text-niquel">
                    {{ $cliente->ciudad ?: 'Sin ciudad' }}
                    · {{ $cliente->canal?->nombre ?? 'Sin canal' }}
                    · {{ $cliente->asesorSap?->codigo_texto ?? 'Sin cartera' }}
                </p>
            </a>
        @empty
            <div class="px-6 py-14 text-center">
                <p class="font-medium">Ningún cliente coincide</p>
                <p class="mt-1 text-sm text-niquel">Busca por el comienzo del código o por parte del nombre.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $clientes->links() }}</div>
</div>
