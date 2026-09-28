<?php

use App\Models\Usuario;
use App\Services\PlantillasSap;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Plantillas para cargar a SAP con DTW.
 *
 * El camino manual, mientras no exista el robot puente. Quien entra aqui se
 * lleva los tres archivos, los importa en DTW y despues vuelve a marcar los
 * pedidos como importados.
 *
 * Los dos pasos estan separados a proposito: descargar no es importar. Si
 * marcar fuera automatico, un archivo que DTW rechace dejaria pedidos que la
 * web da por puestos en SAP y que nadie volveria a mirar.
 */
new class extends Component
{
    public array $elegidos = [];

    public string $error = '';

    public string $aviso = '';

    public bool $confirmando = false;

    public function mount(): void
    {
        Gate::authorize('administrar', Usuario::class);
    }

    /** Todos o ninguno, que es lo normal: se importa la tanda completa. */
    public function alternarTodos(PlantillasSap $plantillas): void
    {
        $todos = $plantillas->pendientes()->pluck('id')->map('strval')->all();

        $this->elegidos = count($this->elegidos) === count($todos) ? [] : $todos;
    }

    public function descargar(PlantillasSap $plantillas)
    {
        $this->error = '';
        $this->aviso = '';

        $pedidos = $plantillas->pendientes()->whereIn('id', $this->elegidos)->values();

        if ($pedidos->isEmpty()) {
            $this->error = 'Elige al menos un pedido.';

            return null;
        }

        $archivos = $plantillas->archivos($pedidos);
        $plantillas->registrarDescarga($pedidos, Auth::user());

        $nombre = 'plantillas-sap-'.now()->format('Y-m-d-Hi').'.zip';

        return response()->streamDownload(function () use ($archivos) {
            // El zip se arma en un archivo temporal porque ZipArchive no
            // escribe a un flujo; se manda y se borra enseguida.
            $temporal = tempnam(sys_get_temp_dir(), 'sgx');
            $zip = new ZipArchive;
            $zip->open($temporal, ZipArchive::OVERWRITE);

            foreach ($archivos as $archivo => $contenido) {
                $zip->addFromString($archivo, $contenido);
            }

            $zip->close();

            echo file_get_contents($temporal);
            @unlink($temporal);
        }, $nombre, ['Content-Type' => 'application/zip']);
    }

    public function marcarImportados(PlantillasSap $plantillas): void
    {
        $this->error = '';
        $this->aviso = '';

        try {
            $cuantos = $plantillas->marcarImportados(
                array_map('intval', $this->elegidos),
                Auth::user(),
            );
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->confirmando = false;
        $this->elegidos = [];
        $this->aviso = $cuantos === 1
            ? 'Un pedido quedó marcado como importado.'
            : "{$cuantos} pedidos quedaron marcados como importados.";
    }

    public function with(PlantillasSap $plantillas): array
    {
        $pendientes = $plantillas->pendientes();

        return [
            'pendientes' => $pendientes,
            'conPrecioManual' => $plantillas->conPrecioManual(
                $pendientes->whereIn('id', $this->elegidos)->values()
            ),
        ];
    }
}; ?>

<div>
    <x-admin-nav />

    <h1 class="font-titulo mb-1 text-2xl font-semibold tracking-tight">Plantillas para SAP</h1>
    <p class="mb-4 text-sm text-niquel">
        Los pedidos aprobados que faltan por cargar. Descarga las tres plantillas,
        impórtalas en DTW y vuelve a marcarlos como importados.
    </p>

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    @if ($aviso)
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-900">{{ $aviso }}</p>
    @endif

    @if ($pendientes->isEmpty())
        <div class="rounded-lg bg-white px-6 py-14 text-center">
            <p class="font-medium">No hay nada esperando carga</p>
            <p class="mt-1 text-sm text-niquel">
                Los pedidos aparecen aquí en cuanto alguien los aprueba.
            </p>
        </div>
    @else
        <div class="mb-3 flex items-center justify-between gap-3">
            <button type="button" wire:click="alternarTodos" class="text-sm font-medium text-grafito hover:text-naranja">
                {{ count($elegidos) === $pendientes->count() ? 'Quitar la selección' : 'Seleccionar todos' }}
            </button>
            <span class="text-sm text-niquel">
                {{ count($elegidos) }} de {{ $pendientes->count() }}
            </span>
        </div>

        <div class="overflow-hidden rounded-lg bg-white">
            @foreach ($pendientes as $pedido)
                <label class="flex items-start gap-3 border-b border-acero px-4 py-3.5 last:border-b-0 hover:bg-acero/60">
                    <input type="checkbox" wire:model.live="elegidos" value="{{ $pedido->id }}"
                           class="mt-1 size-4 shrink-0 rounded border-acero-hondo text-naranja focus:ring-naranja">

                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="cifras font-medium">#{{ $pedido->id }}</span>
                            @if ($pedido->cambioDespuesDeDescargar())
                                {{-- Alguien lo ajustó después de bajar las
                                     plantillas: el archivo que anda por ahí
                                     quedó viejo. --}}
                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-900">Cambió, vuelve a bajarlo</span>
                            @elseif ($pedido->plantillas_descargadas_en)
                                <span class="rounded bg-acero px-1.5 py-0.5 text-xs text-niquel">
                                    Descargado {{ $pedido->plantillas_descargadas_en->format('d/m H:i') }}
                                </span>
                            @endif
                            @if ($pedido->lineas->contains(fn ($l) => $l->precio_manual !== null))
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">Precio manual</span>
                            @endif
                            @if ($pedido->direccion_2)
                                <span class="rounded bg-acero px-1.5 py-0.5 text-xs text-niquel">Dirección alterna</span>
                            @endif
                        </span>

                        <span class="mt-1 block font-medium leading-snug">{{ $pedido->nombre_cliente }}</span>

                        <span class="mt-0.5 block text-sm text-niquel">
                            Aprobó {{ $pedido->aprobador?->nombre }}
                            · <span class="cifras">{{ $pedido->fecha_aprobacion?->format('d/m/Y') }}</span>
                            · {{ $pedido->lineas->count() }} {{ $pedido->lineas->count() === 1 ? 'producto' : 'productos' }}
                        </span>
                    </span>

                    <span class="cifras shrink-0 font-medium">$ {{ number_format($pedido->total, 0, ',', '.') }}</span>
                </label>
            @endforeach
        </div>

        {{-- La plantilla solo lleva el descuento, así que SAP calcula su propio
             precio para las líneas con precio escrito a mano. Se corrigen allá,
             como se hace hoy. El trabajo de esta pantalla es decir cuáles, antes
             de importar y no después de facturar. --}}
        @if ($conPrecioManual->isNotEmpty())
            <div class="mt-4 rounded-lg bg-amber-50 px-4 py-3">
                <p class="text-sm font-medium text-amber-900">
                    Corrige el precio a mano en SAP
                </p>
                <p class="mt-1 text-sm text-amber-800">
                    {{ $conPrecioManual->count() === 1 ? 'El pedido' : 'Los pedidos' }}
                    <span class="cifras font-medium">#{{ $conPrecioManual->pluck('id')->implode(', #') }}</span>
                    {{ $conPrecioManual->count() === 1 ? 'tiene una línea con precio escrito a mano' : 'tienen líneas con precio escrito a mano' }}.
                    La plantilla no lleva ese precio, así que SAP va a calcular el suyo:
                    después de importar, ajústalo en el documento.
                </p>
            </div>
        @endif

        <button type="button" wire:click="descargar" wire:loading.attr="disabled"
                @disabled(count($elegidos) === 0)
                class="mt-4 w-full rounded-lg bg-naranja px-4 py-4 text-lg font-semibold text-white
                       hover:bg-naranja-hondo disabled:cursor-not-allowed disabled:opacity-50">
            Descargar las 3 plantillas
        </button>

        <div class="mt-6 rounded-lg bg-white p-4">
            <h2 class="font-titulo mb-1 font-semibold">Después de importar en DTW</h2>
            <p class="mb-3 text-sm text-niquel">
                Descargar no marca nada. Cuando DTW confirme que los creó, vuelve aquí y márcalos:
                dejan de aparecer en esta lista y quedan como importados, con tu nombre y la fecha.
            </p>

            @if (! $confirmando)
                <button type="button" wire:click="$set('confirmando', true)"
                        @disabled(count($elegidos) === 0)
                        class="w-full rounded-lg border border-acero-hondo px-4 py-3 text-sm font-medium
                               hover:bg-acero disabled:cursor-not-allowed disabled:opacity-50">
                    Marcar como importados
                </button>
            @else
                <p class="mb-3 text-sm">
                    ¿Ya quedaron en SAP
                    {{ count($elegidos) === 1 ? 'el pedido' : 'los' }}
                    <span class="cifras font-medium">{{ count($elegidos) === 1 ? '#'.$elegidos[0] : count($elegidos).' pedidos' }}</span>{{ count($elegidos) === 1 ? '' : ' seleccionados' }}?
                    Esto no se deshace desde aquí.
                </p>
                <div class="flex gap-2">
                    <button type="button" wire:click="marcarImportados"
                            class="flex-1 rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                        Sí, ya están en SAP
                    </button>
                    <button type="button" wire:click="$set('confirmando', false)"
                            class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">Cancelar</button>
                </div>
            @endif
        </div>
    @endif
</div>
