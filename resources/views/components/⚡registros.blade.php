<?php

use App\Models\Bitacora;
use App\Models\Usuario;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Log de registros.
 *
 * Reemplaza la propuesta de la foto con la camara: lo que hacia falta no era
 * una evidencia mas, sino poder responder "quien hizo esto y cuando" sin
 * depender de la memoria de nadie.
 *
 * Es de solo lectura. La aplicacion no ofrece editar ni borrar filas, ni
 * siquiera a TI: un log que se puede corregir no sirve de log.
 */
new class extends Component
{
    use WithPagination;

    #[Url(as: 'accion', except: 'TODAS')]
    public string $accion = 'TODAS';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public ?int $abierto = null;

    public function mount(): void
    {
        Gate::authorize('administrar', Usuario::class);
    }

    public function updatedAccion(): void
    {
        $this->resetPage();
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function alternar(int $id): void
    {
        $this->abierto = $this->abierto === $id ? null : $id;
    }

    public function with(): array
    {
        $consulta = Bitacora::query()->with('usuario:id,nombre');

        if ($this->accion !== 'TODAS') {
            $consulta->where('accion', $this->accion);
        }

        $texto = trim($this->buscar);

        if ($texto !== '') {
            $consulta->where(function ($q) use ($texto) {
                $q->where('usuario_correo', 'like', '%'.$texto.'%');

                // Buscar por numero de pedido es lo que mas se va a usar:
                // "que paso con el 5012".
                if (ctype_digit($texto)) {
                    $q->orWhere('entidad_id', (int) $texto);
                }
            });
        }

        return [
            'registros' => $consulta->latest('fecha_hora')->latest('id')->paginate(40),
            'acciones' => Bitacora::query()
                ->selectRaw('accion, count(*) as cuantos')
                ->groupBy('accion')
                ->orderByDesc('cuantos')
                ->pluck('cuantos', 'accion'),
        ];
    }
}; ?>

<div>
    <x-admin-nav />

    <div class="mb-3 space-y-3">
        <label class="block">
            <span class="sr-only">Buscar por número de pedido o correo</span>
            <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por número de pedido o correo"
                   autocomplete="off"
                   class="w-full rounded-lg border border-acero-hondo bg-white py-3 px-4 text-base
                          placeholder:text-niquel-claro focus:border-naranja focus:outline-none">
        </label>

        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
            <button type="button" wire:click="$set('accion', 'TODAS')"
                @class([
                    'shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                    'bg-grafito text-white' => $accion === 'TODAS',
                    'bg-white text-grafito-suave' => $accion !== 'TODAS',
                ])>Todas</button>

            @foreach ($acciones as $nombre => $cuantos)
                <button type="button" wire:click="$set('accion', '{{ $nombre }}')"
                    @class([
                        'shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                        'bg-grafito text-white' => $accion === $nombre,
                        'bg-white text-grafito-suave' => $accion !== $nombre,
                    ])>
                    {{ \Illuminate\Support\Str::of($nombre)->replace('_', ' ')->lower()->ucfirst() }}
                    <span class="cifras ml-1 opacity-70">{{ $cuantos }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div wire:loading.class="opacity-50" class="overflow-hidden rounded-lg bg-white transition-opacity">
        @forelse ($registros as $registro)
            <div class="border-b border-acero last:border-b-0">
                <button type="button" wire:click="alternar({{ $registro->id }})"
                        class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-acero/60">
                    <span class="cifras shrink-0 text-sm text-niquel">
                        {{ $registro->fecha_hora?->format('d/m H:i') }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium leading-snug">
                            {{ \Illuminate\Support\Str::of($registro->accion)->replace('_', ' ')->lower()->ucfirst() }}
                            @if ($registro->entidad === 'pedido' && $registro->entidad_id)
                                <span class="cifras text-niquel">#{{ $registro->entidad_id }}</span>
                            @endif
                        </span>
                        <span class="block text-sm text-niquel">
                            {{ $registro->usuario?->nombre ?? $registro->usuario_correo ?? 'Sistema' }}
                        </span>
                    </span>

                    @if ($registro->detalle)
                        <span class="shrink-0 text-xs text-niquel-claro">{{ $abierto === $registro->id ? '−' : '+' }}</span>
                    @endif
                </button>

                @if ($abierto === $registro->id && $registro->detalle)
                    <dl class="space-y-1.5 border-t border-acero bg-acero/40 px-4 py-3 text-sm">
                        @foreach ($registro->detalle as $campo => $valor)
                            @if ($campo === 'cambios' && is_array($valor))
                                @foreach ($valor as $cambiado => $par)
                                    <div class="flex gap-2">
                                        <dt class="shrink-0 text-niquel">{{ $cambiado }}</dt>
                                        <dd class="min-w-0 flex-1">
                                            <span class="text-niquel line-through">{{ json_encode($par['antes'] ?? null, JSON_UNESCAPED_UNICODE) }}</span>
                                            →
                                            {{ json_encode($par['despues'] ?? null, JSON_UNESCAPED_UNICODE) }}
                                        </dd>
                                    </div>
                                @endforeach
                            @else
                                <div class="flex gap-2">
                                    <dt class="shrink-0 text-niquel">{{ $campo }}</dt>
                                    <dd class="min-w-0 flex-1 break-words">
                                        {{ is_scalar($valor) || $valor === null
                                            ? (is_bool($valor) ? ($valor ? 'sí' : 'no') : ($valor ?? '—'))
                                            : json_encode($valor, JSON_UNESCAPED_UNICODE) }}
                                    </dd>
                                </div>
                            @endif
                        @endforeach

                        @if ($registro->ip)
                            <div class="flex gap-2">
                                <dt class="shrink-0 text-niquel">ip</dt>
                                <dd class="cifras">{{ $registro->ip }}</dd>
                            </div>
                        @endif
                    </dl>
                @endif
            </div>
        @empty
            <div class="px-6 py-14 text-center">
                <p class="font-medium">No hay registros que coincidan</p>
                <p class="mt-1 text-sm text-niquel">Prueba con otra acción o con el número de un pedido.</p>
            </div>
        @endforelse
    </div>

    @if ($registros->hasPages())
        <div class="mt-4">{{ $registros->links() }}</div>
    @endif
</div>
