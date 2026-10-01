<?php

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Services\ServicioMaestros;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Ficha de un cliente en el maestro.
 *
 * Un solo boton "Guardar": aqui si es un formulario que se envia completo, y
 * la bitacora guarda campo por campo que cambio.
 */
new class extends Component
{
    public Cliente $cliente;

    public array $datos = [];

    public string $error = '';

    public string $aviso = '';

    public function mount(Cliente $cliente): void
    {
        Gate::authorize('administrar', Usuario::class);

        $this->cliente = $cliente;
        $this->cargarDatos();
        $this->aviso = (string) session('aviso', '');
    }

    private function cargarDatos(): void
    {
        $c = $this->cliente;

        $this->datos = [
            'codigo_sn' => $c->codigo_sn,
            'nombre' => $c->nombre,
            'direccion' => (string) $c->direccion,
            'ciudad' => (string) $c->ciudad,
            'canal_id' => (string) $c->canal_id,
            'asesor_sap_id' => (string) $c->asesor_sap_id,
            // Sin ceros de relleno: "15" se lee mejor que "15.00" en el campo.
            'porcentaje_descuento' => (string) (float) $c->porcentaje_descuento,
        ];
    }

    public function guardar(ServicioMaestros $servicio): void
    {
        Gate::authorize('administrar', Usuario::class);
        $this->error = '';
        $this->aviso = '';

        try {
            $servicio->actualizarCliente($this->cliente, $this->datos);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->cliente->refresh();
        $this->cargarDatos();
        $this->aviso = 'Cambios guardados.';
    }

    /** Desactivar en vez de borrar: sus pedidos lo siguen nombrando. */
    public function cambiarActivo(ServicioMaestros $servicio): void
    {
        Gate::authorize('administrar', Usuario::class);
        $this->error = '';

        $servicio->actualizarCliente($this->cliente, ['activo' => ! $this->cliente->activo]);
        $this->cliente->refresh();
        $this->aviso = $this->cliente->activo ? 'Cliente activado.' : 'Cliente desactivado. Ya no aparece a los asesores.';
    }

    public function with(): array
    {
        return [
            'canales' => Canal::orderBy('nombre')->get(),
            'carteras' => AsesorSap::withCount('usuarios')->orderBy('codigo_texto')->get(),
            'pedidos' => $this->cliente->pedidos()->count(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('maestros-clientes') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-niquel hover:text-grafito">
        <svg class="size-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Clientes
    </a>

    <div class="mb-1 flex flex-wrap items-center gap-2">
        <h1 class="font-titulo text-2xl font-semibold tracking-tight">{{ $cliente->nombre }}</h1>
        @if (! $cliente->activo)
            <span class="rounded bg-acero px-1.5 py-0.5 text-xs text-niquel">Inactivo</span>
        @endif
    </div>

    <p class="mb-4 text-sm text-niquel">
        <span class="cifras">{{ $cliente->codigo_sn }}</span>
        · <span class="cifras">{{ $pedidos }}</span> {{ $pedidos === 1 ? 'pedido' : 'pedidos' }}
    </p>

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    @if ($aviso)
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-900">{{ $aviso }}</p>
    @endif

    @if ($cliente->asesor_sap_id && ($carteras->firstWhere('id', $cliente->asesor_sap_id)?->usuarios_count ?? 0) === 0)
        <p class="mb-4 rounded-lg bg-white px-4 py-3 text-sm text-niquel">
            Ningún asesor tiene asignada esta cartera todavía: el cliente no le aparece a nadie. Se asigna en Usuarios.
        </p>
    @endif

    <section class="mb-4 rounded-lg bg-white p-4">
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-xs text-niquel">Código SN</span>
                <input type="text" wire:model="datos.codigo_sn" autocomplete="off" autocapitalize="characters"
                       class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
            </label>

            <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs text-niquel">Nombre o razón social</span>
                <input type="text" wire:model="datos.nombre" autocomplete="off"
                       class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-niquel">Dirección</span>
                <input type="text" wire:model="datos.direccion" autocomplete="off"
                       class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-niquel">Ciudad</span>
                <input type="text" wire:model="datos.ciudad" autocomplete="off"
                       class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-niquel">Canal</span>
                <select wire:model="datos.canal_id"
                        class="w-full rounded-lg border border-acero-hondo bg-white px-3 py-2.5 focus:border-naranja focus:outline-none">
                    <option value="">Sin canal</option>
                    @foreach ($canales as $canal)
                        <option value="{{ $canal->id }}">{{ $canal->nombre }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-niquel">% de descuento</span>
                <input type="number" inputmode="decimal" min="0" max="100" step="0.01" wire:model="datos.porcentaje_descuento"
                       class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
            </label>

            <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs text-niquel">Cartera de SAP</span>
                <select wire:model="datos.asesor_sap_id"
                        class="w-full rounded-lg border border-acero-hondo bg-white px-3 py-2.5 focus:border-naranja focus:outline-none">
                    <option value="">Sin cartera</option>
                    @foreach ($carteras as $cartera)
                        <option value="{{ $cartera->id }}">{{ $cartera->codigo_texto }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-niquel">Lo ve el asesor que tenga asignada esta cartera.</span>
            </label>
        </div>

        <button type="button" wire:click="guardar"
                class="mt-4 w-full rounded-lg bg-naranja px-4 py-3 font-semibold text-white hover:bg-naranja-hondo">
            Guardar cambios
        </button>
    </section>

    <button type="button" wire:click="cambiarActivo"
            wire:confirm="{{ $cliente->activo ? '¿Desactivar a '.$cliente->nombre.'? Deja de aparecerle a los asesores, pero sus pedidos se conservan.' : '¿Volver a activar a '.$cliente->nombre.'?' }}"
            class="mt-2 w-full rounded-lg px-4 py-3 text-sm text-niquel hover:text-red-700">
        {{ $cliente->activo ? 'Desactivar cliente' : 'Activar cliente' }}
    </button>
</div>
