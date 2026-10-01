<?php

use App\Models\Usuario;
use App\Services\LectorMaestros;
use App\Services\ServicioMaestros;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Carga de clientes o productos desde un Excel o CSV.
 *
 * Subir el archivo no escribe nada: primero se ve que pasaria fila por fila
 * (nuevo, cambia, igual o con error) y el admin elige que cargar. El archivo
 * no se guarda: se lee, se borra, y lo que queda es la revision en pantalla.
 */
new class extends Component
{
    use WithFileUploads;

    #[Url(except: 'clientes')]
    public string $tipo = 'clientes';

    public $archivo = null;

    /** Revision fila por fila. Bloqueada: el navegador no puede reescribirla. */
    #[Locked]
    public array $filas = [];

    #[Locked]
    public string $nombreArchivo = '';

    public array $seleccion = [];

    public string $filtro = 'TODAS';

    public string $error = '';

    public string $aviso = '';

    /** Motivos de las filas que no entraron en la ultima carga. */
    public array $rechazadas = [];

    public function mount(): void
    {
        Gate::authorize('administrar', Usuario::class);

        if (! in_array($this->tipo, ['clientes', 'productos'], true)) {
            $this->tipo = 'clientes';
        }
    }

    public function updatedTipo(): void
    {
        if (! in_array($this->tipo, ['clientes', 'productos'], true)) {
            $this->tipo = 'clientes';
        }

        $this->descartar();
        $this->aviso = '';
        $this->rechazadas = [];
    }

    public function updatedArchivo(): void
    {
        $lector = app(LectorMaestros::class);
        $servicio = app(ServicioMaestros::class);
        Gate::authorize('administrar', Usuario::class);
        $this->descartar(conservarArchivo: true);
        $this->aviso = '';
        $this->rechazadas = [];

        $this->validate(['archivo' => 'required|file|max:5120|extensions:xlsx,csv']);

        try {
            $leido = $lector->leer(
                $this->archivo->getRealPath(),
                $this->archivo->getClientOriginalExtension(),
                $this->tipo,
            );
            $this->filas = $servicio->analizarCarga($this->tipo, $leido['filas']);
            $this->nombreArchivo = $this->archivo->getClientOriginalName();

            if ($this->filas === []) {
                $this->error = 'El archivo no tiene filas debajo del encabezado.';
            }
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        } finally {
            // El archivo no se guarda: ya se leyo.
            $this->archivo->delete();
            $this->archivo = null;
        }
    }

    public function seleccionarValidos(): void
    {
        $this->seleccion = collect($this->filas)
            ->whereIn('estado', ['NUEVO', 'CAMBIA'])
            ->pluck('fila')
            ->map(fn ($f) => (string) $f)
            ->values()
            ->all();
    }

    public function quitarSeleccion(): void
    {
        $this->seleccion = [];
    }

    public function cargar(ServicioMaestros $servicio): void
    {
        Gate::authorize('administrar', Usuario::class);
        $this->error = '';
        $this->aviso = '';

        $elegidas = array_map('intval', $this->seleccion);

        // Solo nuevas o con cambios: lo igual o con error no se carga aunque
        // llegue marcado desde el navegador.
        $filas = collect($this->filas)
            ->whereIn('estado', ['NUEVO', 'CAMBIA'])
            ->filter(fn ($f) => in_array($f['fila'], $elegidas, true))
            ->values()
            ->all();

        if ($filas === []) {
            $this->error = 'No hay filas elegidas para cargar.';

            return;
        }

        $importacion = $servicio->cargar($this->tipo, $filas, Auth::user(), $this->nombreArchivo);

        $this->aviso = 'Carga lista: '
            .$this->contar($importacion->creados, 'creado', 'creados').', '
            .$this->contar($importacion->actualizados, 'actualizado', 'actualizados').', '
            .$this->contar($importacion->sin_cambios, 'sin cambios', 'sin cambios').', '
            .$this->contar($importacion->errores, 'con error', 'con error').'.';
        $this->rechazadas = $importacion->detalle_errores ?? [];

        $this->descartar();
    }

    private function contar(int $n, string $uno, string $varios): string
    {
        return $n.' '.($n === 1 ? $uno : $varios);
    }

    public function descartar(bool $conservarArchivo = false): void
    {
        $this->filas = [];
        $this->seleccion = [];
        $this->nombreArchivo = '';
        $this->filtro = 'TODAS';
        $this->error = '';

        if (! $conservarArchivo) {
            $this->archivo = null;
        }
    }

    public function descargarPlantilla(LectorMaestros $lector)
    {
        Gate::authorize('administrar', Usuario::class);

        $ruta = tempnam(sys_get_temp_dir(), 'plantilla');
        $lector->escribirPlantilla($this->tipo, $ruta);

        return response()->download($ruta, "plantilla-{$this->tipo}.xlsx")->deleteFileAfterSend();
    }

    public function with(): array
    {
        $porEstado = collect($this->filas)->countBy('estado');

        return [
            'cuantas' => [
                'TODAS' => count($this->filas),
                'NUEVO' => $porEstado['NUEVO'] ?? 0,
                'CAMBIA' => $porEstado['CAMBIA'] ?? 0,
                'IGUAL' => $porEstado['IGUAL'] ?? 0,
                'ERROR' => $porEstado['ERROR'] ?? 0,
            ],
            'visibles' => $this->filtro === 'TODAS'
                ? $this->filas
                : array_values(array_filter($this->filas, fn ($f) => $f['estado'] === $this->filtro)),
            'etiquetas' => [
                'nombre' => 'Nombre', 'direccion' => 'Dirección', 'ciudad' => 'Ciudad', 'canal' => 'Canal',
                'asesor' => 'Cartera', 'descuento' => '% Descuento', 'descripcion' => 'Descripción',
                'familia' => 'Familia', 'precio' => 'Precio',
            ],
        ];
    }
}; ?>

<div>
    @php
        $estados = [
            'TODAS' => ['Todas', ''],
            'NUEVO' => ['Nuevos', 'bg-green-50 text-green-900'],
            'CAMBIA' => ['Cambian', 'bg-naranja/10 text-naranja-hondo'],
            'IGUAL' => ['Iguales', 'bg-acero text-niquel'],
            'ERROR' => ['Con error', 'bg-red-50 text-red-800'],
        ];
        $valor = fn ($campo, $v) => $v === null || $v === ''
            ? 'vacío'
            : (in_array($campo, ['precio'], true) ? '$'.number_format((float) $v, 0, ',', '.')
                : ($campo === 'descuento' ? rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',').'%' : $v));
    @endphp

    <x-admin-nav />

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    @if ($aviso)
        <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-900">
            <p>{{ $aviso }}</p>
            @foreach (array_slice($rechazadas, 0, 20) as $rechazo)
                <p class="mt-1 text-red-800">Fila <span class="cifras">{{ $rechazo['fila'] }}</span> ({{ $rechazo['codigo'] ?? 'sin código' }}): {{ $rechazo['motivo'] }}</p>
            @endforeach
        </div>
    @endif

    <section class="mb-4 rounded-lg bg-white p-4">
        <h2 class="font-titulo mb-1 font-semibold">Cargar clientes o productos</h2>
        <p class="mb-3 text-sm text-niquel">
            Sube un Excel (.xlsx) o CSV. Primero verás qué cambiaría; nada se guarda hasta que pulses Cargar.
            Una celda vacía no borra el dato que ya existe.
        </p>

        <div class="mb-3 flex gap-2">
            @foreach (['clientes' => 'Clientes', 'productos' => 'Productos'] as $clave => $texto)
                <button type="button" wire:click="$set('tipo', '{{ $clave }}')"
                    @class(['rounded-full px-3 py-1.5 text-sm font-medium',
                        'bg-grafito text-white' => $tipo === $clave,
                        'bg-acero text-grafito-suave' => $tipo !== $clave])>{{ $texto }}</button>
            @endforeach
        </div>

        <label class="block">
            <span class="mb-1 block text-xs text-niquel">Archivo de {{ $tipo }} (máximo 5 MB)</span>
            <input type="file" wire:model="archivo" accept=".xlsx,.csv"
                   class="block w-full text-sm file:mr-3 file:rounded-lg file:border file:border-acero-hondo file:bg-white file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-acero">
        </label>
        @error('archivo')
            <p class="mt-1 text-sm text-red-800">{{ $message }}</p>
        @enderror
        <p wire:loading wire:target="archivo" class="mt-2 text-sm text-niquel">Leyendo el archivo…</p>

        <button type="button" wire:click="descargarPlantilla"
                class="mt-3 text-sm font-medium text-grafito-suave underline underline-offset-2 hover:text-grafito">
            Descargar plantilla de {{ $tipo }}
        </button>
    </section>

    @if ($filas)
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-sm text-niquel">
                <span class="font-medium text-grafito">{{ $nombreArchivo }}</span>
                · <span class="cifras" x-text="$wire.seleccion.length">{{ count($seleccion) }}</span> de
                <span class="cifras">{{ $cuantas['NUEVO'] + $cuantas['CAMBIA'] }}</span> elegidas
            </p>
            <div class="flex gap-3 text-sm">
                <button type="button" wire:click="seleccionarValidos" class="font-medium text-grafito-suave hover:text-grafito">Seleccionar todos los válidos</button>
                <button type="button" wire:click="quitarSeleccion" class="text-niquel hover:text-grafito">Ninguno</button>
            </div>
        </div>

        <div class="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4 pb-1">
            @foreach ($estados as $clave => [$texto, $color])
                <button type="button" wire:click="$set('filtro', '{{ $clave }}')"
                    @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-medium',
                        'bg-grafito text-white' => $filtro === $clave,
                        'bg-white text-grafito-suave' => $filtro !== $clave])>{{ $texto }} <span class="cifras">{{ $cuantas[$clave] }}</span></button>
            @endforeach
        </div>

        <div class="mb-4 overflow-hidden rounded-lg bg-white">
            @forelse ($visibles as $fila)
                @php $elegible = in_array($fila['estado'], ['NUEVO', 'CAMBIA'], true); @endphp
                <label wire:key="fila-{{ $fila['fila'] }}"
                       @class(['flex items-start gap-3 border-b border-acero px-4 py-3 last:border-b-0',
                           'cursor-pointer hover:bg-acero/60' => $elegible])>
                    <input type="checkbox" wire:model="seleccion" value="{{ $fila['fila'] }}" @disabled(! $elegible)
                           class="mt-1 size-4 shrink-0 rounded border-acero-hondo text-naranja focus:ring-naranja disabled:opacity-30">

                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-baseline gap-x-2">
                            <span class="cifras text-xs text-niquel">Fila {{ $fila['fila'] }}</span>
                            <span class="cifras text-sm font-medium">{{ $fila['codigo'] ?? 'sin código' }}</span>
                            <span class="min-w-0 text-sm">{{ $fila['titulo'] }}</span>
                            <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $estados[$fila['estado']][1] }}">{{ ['NUEVO' => 'Nuevo', 'CAMBIA' => 'Cambia', 'IGUAL' => 'Igual', 'ERROR' => 'Error'][$fila['estado']] }}</span>
                        </span>

                        @if ($fila['estado'] === 'ERROR')
                            <span class="mt-0.5 block text-sm text-red-800">{{ $fila['motivo'] }}</span>
                        @elseif ($fila['estado'] === 'CAMBIA')
                            @foreach ($fila['cambios'] as $campo => $cambio)
                                <span class="mt-0.5 block text-sm text-niquel">
                                    {{ $etiquetas[$campo] ?? $campo }}:
                                    <span class="line-through">{{ $valor($campo, $cambio['antes']) }}</span>
                                    → <span class="text-grafito">{{ $valor($campo, $cambio['despues']) }}</span>
                                </span>
                            @endforeach
                        @elseif ($fila['estado'] === 'NUEVO')
                            <span class="mt-0.5 block text-sm text-niquel">
                                @foreach ($fila['cambios'] as $campo => $cambio)
                                    {{ $etiquetas[$campo] ?? $campo }}: <span class="text-grafito">{{ $valor($campo, $cambio['despues']) }}</span>{{ $loop->last ? '' : ' · ' }}
                                @endforeach
                            </span>
                        @endif

                        @foreach ($fila['avisos'] as $nota)
                            <span class="mt-0.5 block text-sm text-naranja-hondo">{{ $nota }}</span>
                        @endforeach
                    </span>
                </label>
            @empty
                <p class="px-6 py-10 text-center text-sm text-niquel">Ninguna fila en este grupo.</p>
            @endforelse
        </div>

        <div class="flex gap-2">
            <button type="button" wire:click="cargar" x-bind:disabled="$wire.seleccion.length === 0"
                    wire:confirm="¿Cargar las filas elegidas? Los cambios quedan en el registro."
                    class="flex-1 rounded-lg bg-naranja px-4 py-3 font-semibold text-white hover:bg-naranja-hondo disabled:cursor-not-allowed disabled:opacity-50">
                Cargar seleccionados
            </button>
            <button type="button" wire:click="descartar" class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">Descartar</button>
        </div>
    @endif
</div>
