<?php

use App\Models\Configuracion;
use App\Models\Usuario;
use App\Services\Bitacorero;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Los pocos valores que cambian sin tocar codigo.
 *
 * Solo TI edita: el IVA y la duracion del bloqueo afectan a todo el mundo, y
 * un cambio por descuido se paga en pedidos mal calculados. Cada cambio queda
 * en el log con el antes y el despues.
 */
new class extends Component
{
    public array $valores = [];

    public string $aviso = '';

    public string $error = '';

    public function mount(): void
    {
        Gate::authorize('administrar', Usuario::class);

        foreach (Configuracion::orderBy('clave')->get() as $fila) {
            $this->valores[$fila->clave] = (string) $fila->valor;
        }
    }

    public function guardar(Bitacorero $bitacora): void
    {
        $this->aviso = '';
        $this->error = '';

        if (! auth()->user()->rol->gestionaAdministradores()) {
            $this->error = 'La configuración la cambia TI.';

            return;
        }

        $iva = $this->valores['iva_porcentaje'] ?? null;

        if ($iva !== null && (! is_numeric($iva) || $iva < 0 || $iva > 100)) {
            $this->error = 'El IVA tiene que ser un número entre 0 y 100.';

            return;
        }

        $minutos = $this->valores['minutos_bloqueo'] ?? null;

        if ($minutos !== null && (! ctype_digit((string) $minutos) || (int) $minutos < 1)) {
            $this->error = 'El bloqueo tiene que durar al menos un minuto.';

            return;
        }

        $cambiados = 0;

        foreach (Configuracion::all() as $fila) {
            $nuevo = trim((string) ($this->valores[$fila->clave] ?? ''));

            if ($nuevo === (string) $fila->valor) {
                continue;
            }

            $anterior = (string) $fila->valor;
            $fila->update(['valor' => $nuevo]);
            $cambiados++;

            $bitacora->registrarCambio('CAMBIAR_CONFIGURACION', 'configuracion', $fila->id,
                [$fila->clave => $anterior], [$fila->clave => $nuevo]);
        }

        $this->aviso = $cambiados === 0
            ? 'No había nada que cambiar.'
            : ($cambiados === 1 ? 'Se guardó un valor.' : "Se guardaron {$cambiados} valores.");
    }

    /**
     * Nombre en pantalla y orden.
     *
     * La clave tecnica no sirve de titulo: "Iva porcentaje" no es como nadie
     * llama al IVA. El orden tampoco es alfabetico, va de lo que mas se toca a
     * lo que casi nunca se toca.
     */
    private const NOMBRES = [
        'iva_porcentaje' => 'IVA',
        'minutos_bloqueo' => 'Duración del bloqueo de edición',
        'dias_papelera' => 'Días en la papelera',
        'correo_remitente' => 'Buzón desde el que sale el correo',
        'horas_inventario_viejo' => 'Horas antes de marcar el inventario como viejo',
        'aviso_home' => 'Aviso para todos',
    ];

    public function with(): array
    {
        $orden = array_flip(array_keys(self::NOMBRES));

        return [
            'filas' => Configuracion::all()->sortBy(fn ($f) => $orden[$f->clave] ?? 99)->values(),
            'nombres' => self::NOMBRES,
            'editable' => auth()->user()->rol->gestionaAdministradores(),
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

    @if (! $editable)
        <p class="mb-4 rounded-lg bg-white px-4 py-3 text-sm text-niquel">
            Estos valores los cambia TI. Aquí puedes ver cómo están.
        </p>
    @endif

    <div class="rounded-lg bg-white p-4">
        <div class="space-y-4">
            @foreach ($filas as $fila)
                <label class="block">
                    <span class="mb-0.5 block text-sm font-medium">
                        {{ $nombres[$fila->clave] ?? \Illuminate\Support\Str::of($fila->clave)->replace('_', ' ')->ucfirst() }}
                    </span>
                    <span class="mb-1.5 block text-sm text-niquel">{{ $fila->descripcion }}</span>

                    @if ($fila->tipo === 'numero')
                        <input type="number" inputmode="decimal" wire:model="valores.{{ $fila->clave }}" @disabled(! $editable)
                               class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5
                                      focus:border-naranja focus:outline-none disabled:bg-acero disabled:text-niquel">
                    @else
                        <input type="text" wire:model="valores.{{ $fila->clave }}" @disabled(! $editable)
                               placeholder="Sin definir"
                               class="w-full rounded-lg border border-acero-hondo px-3 py-2.5
                                      focus:border-naranja focus:outline-none disabled:bg-acero disabled:text-niquel">
                    @endif
                </label>
            @endforeach
        </div>

        @if ($editable)
            <button type="button" wire:click="guardar"
                    class="mt-5 w-full rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                Guardar
            </button>
        @endif
    </div>

    <p class="mt-4 text-sm text-niquel">
        El número con el que arrancan los pedidos y los datos de conexión no se
        editan aquí: viven en el archivo <span class="cifras">.env</span> del servidor,
        porque cambiarlos en caliente rompería la numeración.
    </p>
</div>
