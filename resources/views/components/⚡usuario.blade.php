<?php

use App\Enums\Rol;
use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Usuario;
use App\Services\ServicioUsuarios;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Ficha de un usuario: rol, estado, permisos y que clientes alcanza.
 *
 * Cada cambio se guarda al momento y se registra. No hay boton "Guardar todo"
 * a proposito: repartir acceso no es un formulario que se envia de una, son
 * decisiones sueltas que conviene poder rastrear por separado.
 */
new class extends Component
{
    public Usuario $usuario;

    public string $rol = '';

    public array $carteras = [];

    public array $canales = [];

    public string $vigenteHasta = '';

    public bool $pidiendoLiberar = false;

    public string $error = '';

    public string $aviso = '';

    public function mount(Usuario $usuario): void
    {
        Gate::authorize('administrar', Usuario::class);

        $this->usuario = $usuario;
        $this->rol = $usuario->rol->value;
        $this->carteras = array_map('strval', $usuario->idsDeAsesores());
        $this->canales = $usuario->canales()->pluck('canales.id')->map('strval')->all();
    }

    private function refrescar(): void
    {
        $this->usuario->refresh();
        $this->rol = $this->usuario->rol->value;
    }

    public function guardarRol(ServicioUsuarios $servicio): void
    {
        $this->error = '';
        $this->aviso = '';
        $nuevo = Rol::from($this->rol);

        if (! Gate::allows('cambiarRol', [$this->usuario, $nuevo])) {
            $this->refrescar();
            $this->error = 'No puedes asignar ese rol.';

            return;
        }

        $servicio->cambiarRol($this->usuario, $nuevo, Auth::user());
        $this->refrescar();
        $this->aviso = 'Rol actualizado.';
    }

    public function cambiarActivo(ServicioUsuarios $servicio): void
    {
        Gate::authorize('editar', $this->usuario);
        $this->error = '';
        $this->aviso = '';

        try {
            $servicio->cambiarActivo($this->usuario, ! $this->usuario->activo, Auth::user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->refrescar();
    }

    public function otorgarLiberar(ServicioUsuarios $servicio): void
    {
        Gate::authorize('otorgarLiberar', $this->usuario);
        $this->error = '';
        $this->aviso = '';

        try {
            $servicio->otorgarPermiso($this->usuario, 'LIBERAR_SAP', Auth::user(), $this->vigenteHasta ?: null);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->pidiendoLiberar = false;
        $this->vigenteHasta = '';
        $this->refrescar();
        $this->aviso = 'Ahora puede dar el visto bueno para SAP.';
    }

    public function revocarLiberar(ServicioUsuarios $servicio): void
    {
        Gate::authorize('otorgarLiberar', $this->usuario);

        $servicio->revocarPermiso($this->usuario, 'LIBERAR_SAP', Auth::user());
        $this->refrescar();
        $this->aviso = 'Permiso retirado.';
    }

    public function guardarCarteras(ServicioUsuarios $servicio): void
    {
        Gate::authorize('editar', $this->usuario);

        $servicio->sincronizarCarteras($this->usuario, array_map('intval', $this->carteras), Auth::user());
        $this->refrescar();
        $this->aviso = 'Carteras actualizadas.';
    }

    public function guardarCanales(ServicioUsuarios $servicio): void
    {
        Gate::authorize('editar', $this->usuario);

        $servicio->sincronizarCanales($this->usuario, array_map('intval', $this->canales), Auth::user());
        $this->refrescar();
        $this->aviso = 'Canales actualizados.';
    }

    public function with(): array
    {
        return [
            'editable' => Gate::allows('editar', $this->usuario),
            'puedeRepartirLiberar' => Gate::allows('otorgarLiberar', $this->usuario),
            'roles' => Rol::cases(),
            'todasLasCarteras' => AsesorSap::withCount('clientes')->orderBy('codigo_texto')->get(),
            'todosLosCanales' => Canal::withCount('clientes')->orderBy('nombre')->get(),
            'permisoLiberar' => $this->usuario->permisos()->where('permiso', 'LIBERAR_SAP')->first(),
            'cuantosClientes' => $this->usuario->clientesAsignados()->count(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('usuarios') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-niquel hover:text-grafito">
        <svg class="size-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Usuarios
    </a>

    <div class="mb-1 flex flex-wrap items-center gap-2">
        <h1 class="font-titulo text-2xl font-semibold tracking-tight">{{ $usuario->nombre }}</h1>
        @if (! $usuario->activo)
            <span class="rounded bg-acero px-1.5 py-0.5 text-xs text-niquel">Inactivo</span>
        @endif
    </div>

    <p class="mb-4 text-sm text-niquel">
        {{ $usuario->correo }}
        @if ($usuario->ultimo_acceso)
            · entró por última vez el <span class="cifras">{{ $usuario->ultimo_acceso->format('d/m/Y') }}</span>
        @else
            · todavía no ha entrado
        @endif
    </p>

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    @if ($aviso)
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-900">{{ $aviso }}</p>
    @endif

    @if (! $editable)
        <p class="mb-4 rounded-lg bg-white px-4 py-3 text-sm text-niquel">
            @if ($usuario->id === auth()->id())
                Esta es tu propia ficha. Para cambiarla, pídeselo a otro administrador.
            @else
                A un administrador solo lo modifica TI.
            @endif
        </p>
    @endif

    {{-- Rol --}}
    <section class="mb-4 rounded-lg bg-white p-4">
        <h2 class="font-titulo mb-3 font-semibold">Rol</h2>

        <div class="space-y-2">
            @foreach ($roles as $caso)
                <label @class([
                    'flex gap-3 rounded-lg border p-3',
                    'border-naranja bg-naranja/5' => $rol === $caso->value,
                    'border-acero' => $rol !== $caso->value,
                ])>
                    <input type="radio" wire:model="rol" value="{{ $caso->value }}" @disabled(! $editable)
                           class="mt-0.5 size-4 border-acero-hondo text-naranja focus:ring-naranja">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">{{ $caso->etiqueta() }}</span>
                        <span class="block text-sm text-niquel">{{ $caso->descripcion() }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        @if ($editable && $rol !== $usuario->rol->value)
            <button type="button" wire:click="guardarRol"
                    class="mt-3 w-full rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                Cambiar a {{ \App\Enums\Rol::from($rol)->etiqueta() }}
            </button>
        @endif
    </section>

    {{-- Visto bueno para SAP --}}
    <section class="mb-4 rounded-lg bg-white p-4">
        <h2 class="font-titulo mb-1 font-semibold">Visto bueno para SAP</h2>
        <p class="mb-3 text-sm text-niquel">
            Quien lo tiene decide qué pedidos aprobados entran a SAP. Hoy lo tienen César Garzón y Marly Ossa.
        </p>

        @if ($permisoLiberar)
            <p class="mb-3 rounded bg-naranja/10 px-3 py-2 text-sm text-naranja-hondo">
                Lo tiene desde el
                <span class="cifras">{{ $permisoLiberar->vigente_desde?->format('d/m/Y') ?? 'siempre' }}</span>,
                {{ $permisoLiberar->vigente_hasta
                    ? 'y se le vence el '.$permisoLiberar->vigente_hasta->format('d/m/Y').'.'
                    : 'sin vencimiento.' }}
            </p>

            @if ($puedeRepartirLiberar)
                <button type="button" wire:click="revocarLiberar"
                        wire:confirm="¿Quitarle el visto bueno para SAP a {{ $usuario->nombre }}?"
                        class="w-full rounded-lg border border-acero-hondo px-4 py-3 text-sm font-medium hover:bg-acero">
                    Quitar el permiso
                </button>
            @endif
        @elseif ($puedeRepartirLiberar)
            @if (! $pidiendoLiberar)
                <button type="button" wire:click="$set('pidiendoLiberar', true)"
                        class="w-full rounded-lg border border-acero-hondo px-4 py-3 text-sm font-medium hover:bg-acero">
                    Darle el visto bueno para SAP
                </button>
            @else
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">¿Hasta cuándo?</span>
                    <input type="date" wire:model="vigenteHasta"
                           class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                    <span class="mt-1 block text-sm text-niquel">
                        Déjalo en blanco si es permanente. Con fecha se vence solo, que es lo que sirve para cubrir unas vacaciones.
                    </span>
                </label>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="otorgarLiberar"
                            class="flex-1 rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                        Dar el permiso
                    </button>
                    <button type="button" wire:click="$set('pidiendoLiberar', false)"
                            class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">Cancelar</button>
                </div>
            @endif
        @else
            <p class="text-sm text-niquel">No lo tiene. Solo TI puede repartir este permiso.</p>
        @endif
    </section>

    {{-- Carteras: de donde salen sus clientes --}}
    @if ($usuario->rol === \App\Enums\Rol::ASESOR)
        <section class="mb-4 rounded-lg bg-white p-4">
            <h2 class="font-titulo mb-1 font-semibold">Carteras de SAP</h2>
            <p class="mb-3 text-sm text-niquel">
                De aquí salen sus clientes.
                @if ($cuantosClientes > 0)
                    Además tiene <span class="cifras">{{ $cuantosClientes }}</span> asignados uno por uno.
                @endif
            </p>

            <div class="max-h-72 space-y-1 overflow-y-auto">
                @foreach ($todasLasCarteras as $cartera)
                    <label class="flex items-start gap-2.5 py-1.5 text-sm">
                        <input type="checkbox" wire:model="carteras" value="{{ $cartera->id }}" @disabled(! $editable)
                               class="mt-0.5 size-4 shrink-0 rounded border-acero-hondo text-naranja focus:ring-naranja">
                        <span class="min-w-0 flex-1">{{ $cartera->codigo_texto }}</span>
                        <span class="cifras shrink-0 text-niquel">{{ $cartera->clientes_count }}</span>
                    </label>
                @endforeach
            </div>

            @if ($editable)
                <button type="button" wire:click="guardarCarteras"
                        class="mt-3 w-full rounded-lg border border-acero-hondo px-4 py-3 text-sm font-medium hover:bg-acero">
                    Guardar carteras
                </button>
            @endif
        </section>
    @endif

    {{-- Canales: que pedidos le llegan a un gerente --}}
    @if ($usuario->rol === \App\Enums\Rol::GERENTE_CANAL)
        <section class="mb-4 rounded-lg bg-white p-4">
            <h2 class="font-titulo mb-1 font-semibold">Canales</h2>
            <p class="mb-3 text-sm text-niquel">Los pedidos de estos canales le llegan para aprobar.</p>

            <div class="space-y-1">
                @foreach ($todosLosCanales as $canal)
                    <label class="flex items-center gap-2.5 py-1.5 text-sm">
                        <input type="checkbox" wire:model="canales" value="{{ $canal->id }}" @disabled(! $editable)
                               class="size-4 shrink-0 rounded border-acero-hondo text-naranja focus:ring-naranja">
                        <span class="flex-1">{{ $canal->nombre }}</span>
                        <span class="cifras text-niquel">{{ $canal->clientes_count }}</span>
                    </label>
                @endforeach
            </div>

            @if ($editable)
                <button type="button" wire:click="guardarCanales"
                        class="mt-3 w-full rounded-lg border border-acero-hondo px-4 py-3 text-sm font-medium hover:bg-acero">
                    Guardar canales
                </button>
            @endif
        </section>
    @endif

    {{-- Sacar a alguien --}}
    @if ($editable)
        <button type="button" wire:click="cambiarActivo"
                wire:confirm="{{ $usuario->activo ? '¿Desactivar a '.$usuario->nombre.'? No podrá entrar, pero sus pedidos se conservan.' : '¿Volver a dar acceso a '.$usuario->nombre.'?' }}"
                class="mt-2 w-full rounded-lg px-4 py-3 text-sm text-niquel hover:text-red-700">
            {{ $usuario->activo ? 'Quitarle el acceso' : 'Devolverle el acceso' }}
        </button>
    @endif
</div>
