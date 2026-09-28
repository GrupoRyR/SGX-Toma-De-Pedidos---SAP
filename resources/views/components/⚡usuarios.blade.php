<?php

use App\Enums\Rol;
use App\Models\Usuario;
use App\Services\ServicioUsuarios;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Quien entra y que puede hacer.
 *
 * En la app vieja esto se manejaba editando a mano una lista de SharePoint, sin
 * rastro de quien cambiaba que. Aqui cada movimiento queda en el log.
 */
new class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(as: 'rol', except: 'TODOS')]
    public string $rol = 'TODOS';

    public bool $mostrarInactivos = false;

    // Alta de un usuario nuevo.
    public bool $agregando = false;

    public string $correoNuevo = '';

    public string $nombreNuevo = '';

    public string $rolNuevo = 'ASESOR';

    public string $error = '';

    public function mount(): void
    {
        Gate::authorize('administrar', Usuario::class);
    }

    /**
     * Da de alta a alguien.
     *
     * No crea contrasenas: la identidad la da Microsoft. Esto solo registra
     * que ese correo tiene permiso de entrar y con que rol. Sin esta fila, la
     * autenticacion funciona y el ingreso igual se rechaza.
     */
    public function crear(ServicioUsuarios $servicio): void
    {
        $this->error = '';
        $nuevo = Rol::from($this->rolNuevo);

        if ($nuevo->administra() && ! Auth::user()->rol->gestionaAdministradores()) {
            $this->error = 'Solo TI puede crear administradores.';

            return;
        }

        try {
            $usuario = $servicio->crear($this->correoNuevo, $this->nombreNuevo, $nuevo, Auth::user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('agregando', 'correoNuevo', 'nombreNuevo', 'rolNuevo');

        $this->redirectRoute('usuario', ['usuario' => $usuario], navigate: true);
    }

    public function with(): array
    {
        $consulta = Usuario::query()
            ->with('permisos')
            ->withCount(['asesores', 'canales', 'pedidos']);

        if (! $this->mostrarInactivos) {
            $consulta->where('activo', true);
        }

        if ($this->rol !== 'TODOS') {
            $consulta->where('rol', $this->rol);
        }

        $texto = trim($this->buscar);

        if ($texto !== '') {
            $consulta->where(fn ($q) => $q->where('nombre', 'like', '%'.$texto.'%')
                ->orWhere('correo', 'like', '%'.$texto.'%'));
        }

        return [
            'usuarios' => $consulta->orderBy('rol')->orderBy('nombre')->get(),
            'roles' => Rol::cases(),
            'inactivos' => Usuario::where('activo', false)->count(),
        ];
    }
}; ?>

<div>
    <x-admin-nav />

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    {{-- Alta. Se abre solo cuando hace falta: casi siempre esta pantalla se
         usa para buscar a alguien, no para agregar. --}}
    @if ($agregando)
        <section class="mb-4 rounded-lg bg-white p-4">
            <h2 class="font-titulo mb-1 font-semibold">Dar acceso a alguien</h2>
            <p class="mb-3 text-sm text-niquel">
                Con esto entra con su cuenta de Microsoft de la empresa. No se crean contraseñas aquí.
            </p>

            <div class="space-y-3">
                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Correo de la empresa</span>
                    <input type="email" inputmode="email" wire:model="correoNuevo" autocomplete="off"
                           placeholder="nombre.apellido@segurex.com"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Nombre</span>
                    <input type="text" wire:model="nombreNuevo" autocomplete="off"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Rol</span>
                    <select wire:model="rolNuevo"
                            class="w-full rounded-lg border border-acero-hondo bg-white px-3 py-2.5 focus:border-naranja focus:outline-none">
                        @foreach ($roles as $caso)
                            <option value="{{ $caso->value }}">{{ $caso->etiqueta() }} — {{ $caso->descripcion() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="crear" @disabled(trim($correoNuevo) === '')
                        class="flex-1 rounded-lg bg-grafito px-4 py-3 font-semibold text-white
                               hover:bg-grafito-suave disabled:cursor-not-allowed disabled:opacity-50">
                    Dar acceso
                </button>
                <button type="button" wire:click="$set('agregando', false)"
                        class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">Cancelar</button>
            </div>
        </section>
    @else
        <button type="button" wire:click="$set('agregando', true)"
                class="mb-4 w-full rounded-lg border border-acero-hondo bg-white px-4 py-3 text-sm font-medium hover:bg-acero">
            Dar acceso a alguien
        </button>
    @endif

    <div class="mb-3 space-y-3">
        <label class="block">
            <span class="sr-only">Buscar por nombre o correo</span>
            <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por nombre o correo"
                   autocomplete="off"
                   class="w-full rounded-lg border border-acero-hondo bg-white py-3 px-4 text-base
                          placeholder:text-niquel-claro focus:border-naranja focus:outline-none">
        </label>

        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
            <button type="button" wire:click="$set('rol', 'TODOS')"
                @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                    'bg-grafito text-white' => $rol === 'TODOS',
                    'bg-white text-grafito-suave' => $rol !== 'TODOS'])>Todos</button>

            @foreach ($roles as $caso)
                <button type="button" wire:click="$set('rol', '{{ $caso->value }}')"
                    @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                        'bg-grafito text-white' => $rol === $caso->value,
                        'bg-white text-grafito-suave' => $rol !== $caso->value])>{{ $caso->etiqueta() }}</button>
            @endforeach
        </div>

        @if ($inactivos > 0)
            <label class="flex items-center gap-2 text-sm text-niquel">
                <input type="checkbox" wire:model.live="mostrarInactivos"
                       class="size-4 rounded border-acero-hondo text-naranja focus:ring-naranja">
                Mostrar los {{ $inactivos }} inactivos
            </label>
        @endif
    </div>

    <div class="overflow-hidden rounded-lg bg-white">
        @forelse ($usuarios as $usuario)
            <a href="{{ route('usuario', $usuario) }}" wire:navigate
               class="flex items-start gap-3 border-b border-acero px-4 py-3.5 last:border-b-0 hover:bg-acero/60">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium leading-snug">{{ $usuario->nombre }}</span>
                        @if (! $usuario->activo)
                            <span class="rounded bg-acero px-1.5 py-0.5 text-xs text-niquel">Inactivo</span>
                        @endif
                        @if ($usuario->puedeLiberarASap())
                            <span class="rounded bg-naranja/10 px-1.5 py-0.5 text-xs font-medium text-naranja-hondo">Libera a SAP</span>
                        @endif
                    </div>

                    <p class="text-sm text-niquel">{{ $usuario->correo }}</p>

                    <p class="mt-0.5 text-sm text-niquel">
                        {{ $usuario->rol->etiqueta() }}
                        @if ($usuario->asesores_count > 0)
                            · {{ $usuario->asesores_count }} {{ $usuario->asesores_count === 1 ? 'cartera' : 'carteras' }}
                        @endif
                        @if ($usuario->canales_count > 0)
                            · {{ $usuario->canales_count }} {{ $usuario->canales_count === 1 ? 'canal' : 'canales' }}
                        @endif
                        @if ($usuario->pedidos_count > 0)
                            · <span class="cifras">{{ $usuario->pedidos_count }}</span> {{ $usuario->pedidos_count === 1 ? 'pedido' : 'pedidos' }}
                        @endif
                    </p>
                </div>
            </a>
        @empty
            <div class="px-6 py-14 text-center">
                <p class="font-medium">Ningún usuario coincide</p>
                <p class="mt-1 text-sm text-niquel">Prueba con otro rol o con parte del correo.</p>
            </div>
        @endforelse
    </div>
</div>
