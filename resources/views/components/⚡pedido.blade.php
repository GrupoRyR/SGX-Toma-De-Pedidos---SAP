<?php

use App\Models\Pedido;
use App\Models\Producto;
use App\Services\CalculadoraPrecios;
use App\Services\ServicioPedidos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Armado del pedido.
 *
 * El buscador de productos consulta el servidor con debounce y trae pocas
 * filas. La app vieja bajaba los 216 productos al navegador y filtraba alli, y
 * ademas recalculaba precios sobre texto ya formateado.
 */
new class extends Component
{
    public Pedido $pedido;

    // Encabezado editable.
    public string $orden_compra = '';
    public string $fecha_facturacion = '';
    public string $observaciones = '';
    public string $direccion_2 = '';
    public string $ciudad_2 = '';

    // Buscador.
    public string $buscarProducto = '';
    public ?int $productoElegido = null;
    public string $cantidad = '';
    public string $atp = '';
    public string $precioManual = '';

    public string $error = '';
    public string $motivoRechazo = '';
    public bool $pidiendoMotivo = false;
    public string $motivoDevolucion = '';
    public bool $pidiendoDevolucion = false;
    public string $motivoReversa = '';
    public bool $pidiendoReversa = false;
    public bool $confirmaPlantillas = false;

    /**
     * Version del pedido tal como la vio quien esta revisando.
     *
     * Se captura al abrir la pantalla y se manda al aprobar: si el asesor edito
     * el pedido entretanto, la aprobacion se rechaza en vez de confirmar algo
     * distinto de lo que se leyo.
     */
    public int $versionVista = 0;

    public function mount(Pedido $pedido): void
    {
        Gate::authorize('ver', $pedido);

        $this->pedido = $pedido;
        $this->versionVista = (int) $pedido->version;

        /*
         * El bloqueo se toma solo cuando quien abre es el autor, que es quien
         * entra a armar el pedido. Un administrador tambien puede editar, pero
         * casi siempre entra a revisar: si bloqueara con solo abrir, dejaria el
         * pedido trancado para el asesor sin haber tocado nada. Para editarlo
         * tiene el boton "Editar este pedido", que si toma el bloqueo.
         */
        if ($pedido->creado_por === Auth::id() && Gate::allows('editar', $pedido)) {
            app(ServicioPedidos::class)->bloquear($pedido, Auth::user());
            $this->pedido->refresh();
        }

        $this->orden_compra = (string) $pedido->orden_compra;
        $this->fecha_facturacion = $pedido->fecha_facturacion?->format('Y-m-d') ?? '';
        $this->observaciones = (string) $pedido->observaciones;
        $this->direccion_2 = (string) $pedido->direccion_2;
        $this->ciudad_2 = (string) $pedido->ciudad_2;
    }

    public function elegirProducto(int $id): void
    {
        $this->productoElegido = $id;
        $this->buscarProducto = '';
        $this->cantidad = '1';
        $this->error = '';
    }

    public function cancelarProducto(): void
    {
        $this->reset('productoElegido', 'cantidad', 'atp', 'precioManual', 'error');
    }

    public function agregar(ServicioPedidos $servicio): void
    {
        Gate::authorize('editar', $this->pedido);
        $this->error = '';

        $producto = Producto::find($this->productoElegido);

        if (! $producto) {
            $this->error = 'Elige un producto.';

            return;
        }

        try {
            $servicio->agregarLinea(
                $this->pedido,
                $producto,
                cantidad: (float) str_replace(',', '.', $this->cantidad),
                atp: $this->atp === '' ? null : (float) str_replace(',', '.', $this->atp),
                precioManual: $this->precioManual === '' ? null : (float) str_replace('.', '', $this->precioManual),
            );
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->pedido->refresh();
        $this->cancelarProducto();
    }

    public function quitar(int $lineaId, ServicioPedidos $servicio): void
    {
        Gate::authorize('editar', $this->pedido);

        $linea = $this->pedido->lineas()->find($lineaId);

        if ($linea) {
            $servicio->quitarLinea($this->pedido, $linea);
            $this->pedido->refresh();
        }
    }

    /**
     * Quita una línea de un pedido ya aprobado.
     *
     * Es otra acción y no la de arriba porque son dos permisos distintos: esta
     * la ejerce quien aprobó, sobre un pedido que el asesor ya no puede tocar.
     */
    public function quitarAprobada(int $lineaId, ServicioPedidos $servicio): void
    {
        Gate::authorize('ajustar', $this->pedido);
        $this->error = '';

        $linea = $this->pedido->lineas()->find($lineaId);

        if (! $linea) {
            return;
        }

        try {
            $servicio->quitarLineaAprobada($this->pedido, $linea, Auth::user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->pedido->refresh();
        $this->versionVista = (int) $this->pedido->version;
    }

    public function guardarEncabezado(ServicioPedidos $servicio): void
    {
        Gate::authorize('editar', $this->pedido);

        // SAP corta la direccion de entrega en 60 y los comentarios en 250.
        $this->validate([
            'direccion_2' => ['nullable', 'string', 'max:60'],
            'observaciones' => ['nullable', 'string', 'max:250'],
        ], [
            'direccion_2.max' => 'La dirección alterna no puede pasar de 60 caracteres.',
            'observaciones.max' => 'Las observaciones no pueden pasar de 250 caracteres.',
        ]);

        $this->pedido->update([
            'orden_compra' => $this->orden_compra ?: null,
            'fecha_facturacion' => $this->fecha_facturacion ?: null,
            'observaciones' => $this->observaciones ?: null,
            'direccion_2' => $this->direccion_2 ?: null,
            'ciudad_2' => $this->ciudad_2 ?: null,
        ]);
    }

    public function enviar(ServicioPedidos $servicio)
    {
        Gate::authorize('editar', $this->pedido);
        $this->error = '';

        $this->guardarEncabezado($servicio);

        try {
            $servicio->enviar($this->pedido->fresh(), Auth::user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('cliente', ['cliente' => $this->pedido->cliente_id], navigate: true);
    }

    /**
     * Toma el bloqueo a proposito.
     *
     * Es el paso explicito para quien no es el autor: abrir es revisar, editar
     * se pide.
     */
    public function tomarEdicion(ServicioPedidos $servicio): void
    {
        Gate::authorize('editar', $this->pedido);

        $servicio->bloquear($this->pedido, Auth::user());
        $this->pedido->refresh();
    }

    /**
     * Guarda el encabezado, suelta el bloqueo y vuelve al cliente.
     *
     * Los campos del encabezado solo viven en la pantalla hasta que alguien los
     * guarda; las lineas, en cambio, se guardan al agregarlas. Si este boton no
     * guardara tambien el encabezado, la orden de compra o las observaciones que
     * el asesor escribio se perderian al salir, aunque el boton diga "Guardar".
     *
     * Solo se guarda si el pedido sigue siendo editable por quien sale: salir
     * de un pedido que ya se envio no puede cambiarlo.
     *
     * Si el guardado falla (una fecha que no se puede leer, la base que no
     * responde), no se sale: perder en silencio lo que el asesor escribio es
     * peor que pedirle que lo corrija. El bloqueo sigue siendo suyo, y si se va
     * sin corregir vence solo como cualquier otro.
     */
    public function terminarEdicion(ServicioPedidos $servicio)
    {
        if (Gate::allows('editar', $this->pedido)) {
            try {
                $this->guardarEncabezado($servicio);
            } catch (\Illuminate\Validation\ValidationException $e) {
                // Los errores de largo se muestran junto a su campo.
                throw $e;
            } catch (\Exception $e) {
                report($e);
                $this->error = 'No se pudo guardar el pedido. Revisa los datos del encabezado, como la fecha de facturación, e inténtalo de nuevo.';

                return null;
            }
        }

        if ($this->pedido->bloqueado_por === Auth::id()) {
            $servicio->liberarBloqueo($this->pedido);
        }

        return $this->redirectRoute('cliente', ['cliente' => $this->pedido->cliente_id], navigate: true);
    }

    public function eliminar(ServicioPedidos $servicio)
    {
        Gate::authorize('eliminar', $this->pedido);

        $cliente = $this->pedido->cliente_id;
        $servicio->eliminar($this->pedido, Auth::user());

        return $this->redirectRoute('cliente', ['cliente' => $cliente], navigate: true);
    }

    public function aprobar(ServicioPedidos $servicio): void
    {
        Gate::authorize('aprobar', $this->pedido);
        $this->error = '';

        try {
            $servicio->aprobar($this->pedido, Auth::user(), $this->versionVista);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }

        $this->pedido->refresh();
        $this->versionVista = (int) $this->pedido->version;
    }

    public function rechazar(ServicioPedidos $servicio): void
    {
        Gate::authorize('aprobar', $this->pedido);
        $this->error = '';

        try {
            $servicio->rechazar($this->pedido, Auth::user(), $this->motivoRechazo);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->pidiendoMotivo = false;
        $this->motivoRechazo = '';
        $this->pedido->refresh();
    }

    public function liberar(ServicioPedidos $servicio): void
    {
        Gate::authorize('liberar', $this->pedido);
        $this->error = '';

        try {
            $servicio->liberar($this->pedido, Auth::user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }

        $this->pedido->refresh();
    }

    /**
     * Deshace el visto bueno mientras el robot no lo haya tomado.
     *
     * Liberar es la ultima puerta antes de SAP, asi que tiene que poder
     * cerrarse de nuevo: sin esto, un clic de mas solo se arregla en SAP.
     */
    public function devolver(ServicioPedidos $servicio): void
    {
        Gate::authorize('devolver', $this->pedido);
        $this->error = '';

        try {
            $servicio->devolverDeLiberado($this->pedido, Auth::user(), $this->motivoDevolucion);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->pidiendoDevolucion = false;
        $this->motivoDevolucion = '';
        $this->pedido->refresh();
    }

    /**
     * Devuelve un pedido aprobado a borrador para que el asesor lo corrija.
     *
     * Es el camino cuando hay que agregar o cambiar algo despues de aprobado.
     * La confirmacion de plantillas viaja al servicio, que es quien la exige.
     */
    public function reversar(ServicioPedidos $servicio): void
    {
        Gate::authorize('reversar', $this->pedido);
        $this->error = '';

        try {
            $servicio->reversarABorrador($this->pedido, Auth::user(), $this->motivoReversa, $this->confirmaPlantillas);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->cancelarReversa();
        $this->pedido->refresh();
        $this->versionVista = (int) $this->pedido->version;
    }

    /**
     * Cierra el panel y olvida lo que se lleno.
     *
     * Sobre todo la casilla de DTW: cada intento tiene que confirmarla de nuevo.
     * Si sobreviviera al cancelar, el siguiente intento reversaria con una
     * confirmacion que nadie dio en ese momento.
     */
    public function cancelarReversa(): void
    {
        $this->pidiendoReversa = false;
        $this->motivoReversa = '';
        $this->confirmaPlantillas = false;
    }

    public function with(): array
    {
        $texto = trim($this->buscarProducto);

        // Se edita mientras se tenga el bloqueo, no solo por tener permiso: asi
        // nunca hay dos personas escribiendo sobre el mismo pedido.
        $loTengoYo = $this->pedido->bloqueadoAhora()
            && $this->pedido->bloqueado_por === Auth::id();

        return [
            'lineas' => $this->pedido->lineas()->get(),
            'editable' => $loTengoYo && Gate::allows('editar', $this->pedido),
            'puedeTomarEdicion' => ! $loTengoYo && Gate::allows('editar', $this->pedido),
            'bloqueadoPorOtro' => $this->pedido->bloqueadoPorOtro(Auth::user()),
            'puedeAprobar' => Gate::allows('aprobar', $this->pedido),
            'puedeLiberar' => Gate::allows('liberar', $this->pedido),
            'puedeDevolver' => Gate::allows('devolver', $this->pedido),
            'puedeEliminar' => Gate::allows('eliminar', $this->pedido),
            'puedeAjustar' => Gate::allows('ajustar', $this->pedido),
            'puedeReversar' => Gate::allows('reversar', $this->pedido),
            'esAutoaprobacion' => $this->pedido->creado_por === Auth::id(),
            'esRevisor' => Auth::user()->puedeAprobar(),
            'avisoRechazo' => $this->pedido->notificaciones()
                ->whereIn('evento', ['RECHAZO', 'REVERSAR'])
                ->latest('id')
                ->first(),
            'producto' => $this->productoElegido
                ? Producto::conExistencias()->find($this->productoElegido)
                : null,
            'descuentoCliente' => (float) ($this->pedido->cliente->porcentaje_descuento ?? 0),
            'resultados' => $texto === '' ? collect() : Producto::query()
                ->where('activo', true)
                ->conExistencias()
                ->buscar($texto)
                ->orderBy('descripcion')
                ->limit(8)
                ->get(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('cliente', $pedido->cliente_id) }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-niquel hover:text-grafito">
        <svg class="size-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        {{ $pedido->nombre_cliente }}
    </a>

    <div class="mb-4 flex items-center gap-3">
        <h1 class="font-titulo cifras text-2xl font-semibold tracking-tight">Pedido #{{ $pedido->id }}</h1>
        <x-estado :estado="$pedido->estado" />
    </div>

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

    {{-- Si el pedido cambió después de que las plantillas salieron, quien las
         tenga en la mano está mirando un archivo viejo. Vale más decirlo aquí
         que dejar que SAP reciba lo que ya no es. --}}
    @if ($puedeAjustar && $pedido->cambioDespuesDeDescargar())
        <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3">
            <p class="text-sm font-medium text-amber-900">Las plantillas quedaron viejas</p>
            <p class="mt-1 text-sm text-amber-800">
                Este pedido cambió después de descargarlas. Vuelve a bajarlas antes de importar en DTW.
            </p>
        </div>
    @endif

    {{-- Lo que SAP contesto. Se muestra tal cual lo dijo SAP, sin traducir:
         quien lo va a arreglar necesita el mensaje exacto. --}}
    @if ($pedido->sap_error && ! $pedido->importado_sap)
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3">
            <p class="text-sm font-medium text-red-900">SAP no pudo crear este pedido</p>
            <p class="mt-1 text-sm text-red-800">{{ $pedido->sap_error }}</p>
            <p class="mt-1 text-sm text-niquel">
                {{ $pedido->sap_intentos }}
                {{ $pedido->sap_intentos == 1 ? 'intento' : 'intentos' }}.
                @if ($pedido->sap_intentos >= \App\Services\PuenteSap::MAXIMO_INTENTOS)
                    El robot ya no lo reintenta.
                @endif
            </p>
        </div>
    @endif

    {{-- El motivo del rechazo es lo primero que el asesor necesita leer: es
         todo lo que le dice que corregir. --}}
    @if ($pedido->motivo_rechazo)
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3">
            <p class="text-sm font-medium text-red-900">Este pedido se devolvió</p>
            <p class="mt-1 whitespace-pre-line text-sm text-red-800">{{ $pedido->motivo_rechazo }}</p>

            {{-- Responde "a mí nunca me llegó" con un dato, no con una
                 suposición. Se pregunta por el rol y no por `puedeAprobar`:
                 un pedido ya rechazado no se puede aprobar, así que esa
                 comprobación sería siempre falsa justo cuando hace falta. --}}
            @if ($avisoRechazo && $esRevisor)
                <p class="mt-2 text-sm text-niquel">
                    @if ($avisoRechazo->resultado === 'ENVIADO')
                        Se le avisó a {{ $avisoRechazo->destinatario }}
                        el <span class="cifras">{{ $avisoRechazo->enviado_en?->format('d/m/Y H:i') }}</span>.
                    @else
                        No se pudo avisar por correo a {{ $avisoRechazo->destinatario }}.
                        Conviene decírselo de otra forma.
                    @endif
                </p>
            @endif
        </div>
    @endif

    @if ($bloqueadoPorOtro)
        <p class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>{{ $pedido->bloqueador?->nombre }}</strong> está editando este pedido.
            Se libera solo cuando termine, o a los {{ (int) \App\Models\Configuracion::valor('minutos_bloqueo', 30) }} minutos.
        </p>
    @elseif (! $pedido->estado->editablePorAsesor())
        <p class="mb-4 rounded-lg bg-white px-4 py-3 text-sm text-niquel">
            @if ($pedido->importado_sap)
                Este pedido ya está en SAP. Lo que haya que corregir se corrige allá.
            @elseif ($puedeAjustar)
                {{-- Quien aprueba sí puede quitar líneas mientras el pedido no
                     entre a SAP, así que decirle que no se puede modificar
                     contradiría el botón que tiene al lado. --}}
                El asesor ya no puede tocarlo. Si el cliente canceló algo, quita la línea aquí.
            @elseif ($pedido->estado === \App\Enums\EstadoPedido::LIBERADO)
                Este pedido tiene el visto bueno y espera su carga a SAP.
            @else
                Este pedido ya fue aprobado, así que no se puede modificar.
            @endif
        </p>
    @endif

    {{-- Acciones de quien revisa. Van arriba porque es lo unico que vino a hacer. --}}
    @if ($puedeAprobar || $puedeLiberar || $puedeDevolver || $puedeReversar)
        <section class="mb-4 rounded-lg bg-white p-4">
            @if ($puedeAprobar)
                @if ($esAutoaprobacion)
                    <p class="mb-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        Este pedido es tuyo. Aprobarlo queda registrado como autoaprobación.
                    </p>
                @endif

                @if (! $pidiendoMotivo)
                    <div class="flex gap-2">
                        <button type="button" wire:click="aprobar" wire:loading.attr="disabled"
                                class="flex-1 rounded-lg bg-green-700 px-4 py-3.5 font-semibold text-white hover:bg-green-800">
                            Aprobar
                        </button>
                        <button type="button" wire:click="$set('pidiendoMotivo', true)"
                                class="rounded-lg border border-acero-hondo px-4 py-3.5 font-semibold text-grafito hover:bg-acero">
                            Rechazar
                        </button>
                    </div>
                @else
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">¿Por qué se rechaza?</span>
                        <textarea rows="2" wire:model="motivoRechazo" autofocus
                                  placeholder="El asesor va a leer esto para corregir el pedido"
                                  class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none"></textarea>
                    </label>
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="rechazar"
                                class="flex-1 rounded-lg bg-red-700 px-4 py-3 font-semibold text-white hover:bg-red-800">
                            Rechazar pedido
                        </button>
                        <button type="button" wire:click="$set('pidiendoMotivo', false)"
                                class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">
                            Cancelar
                        </button>
                    </div>
                @endif
            @endif

            @if ($puedeLiberar)
                <p class="mb-3 text-sm text-niquel">
                    Revisa el pedido completo antes de liberarlo. Una vez liberado, el robot lo crea en SAP.
                </p>
                <button type="button" wire:click="liberar" wire:loading.attr="disabled"
                        class="w-full rounded-lg bg-naranja px-4 py-3.5 font-semibold text-white hover:bg-naranja-hondo">
                    Liberar a SAP
                </button>
            @endif

            {{-- La puerta de salida tiene que poder cerrarse otra vez,
                 mientras el robot no haya pasado. --}}
            @if ($puedeDevolver)
                @if (! $pidiendoDevolucion)
                    <button type="button" wire:click="$set('pidiendoDevolucion', true)"
                            class="w-full rounded-lg border border-acero-hondo px-4 py-3 text-sm font-medium hover:bg-acero">
                        Devolver a aprobado
                    </button>
                @else
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">¿Por qué se devuelve?</span>
                        <textarea rows="2" wire:model="motivoDevolucion" autofocus
                                  placeholder="Queda en la bitácora"
                                  class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none"></textarea>
                    </label>
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="devolver"
                                class="flex-1 rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                            Devolver el pedido
                        </button>
                        <button type="button" wire:click="$set('pidiendoDevolucion', false)"
                                class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">
                            Cancelar
                        </button>
                    </div>
                @endif
            @endif

            {{-- Para agregar o cambiar algo después de aprobado: vuelve al
                 asesor y repite la aprobación. Es secundario a propósito; el
                 naranja queda para la acción principal. --}}
            @if ($puedeReversar)
                @if (! $pidiendoReversa)
                    <button type="button" wire:click="$set('pidiendoReversa', true)"
                            class="mt-3 w-full rounded-lg border border-acero-hondo px-4 py-3 text-sm font-medium hover:bg-acero">
                        Reversar a borrador
                    </button>
                @else
                    <div class="mt-3">
                        <p class="mb-3 text-sm text-niquel">
                            El pedido vuelve al asesor como borrador. Lo corrige, lo envía y pasa otra vez por aprobación.
                        </p>

                        {{-- Si las plantillas ya salieron, el pedido puede estar
                             en un archivo de DTW. Reversarlo después de importado
                             dejaría la web y SAP diciendo cosas distintas. --}}
                        @if ($pedido->plantillas_descargadas_en)
                            <div class="mb-3 rounded-lg bg-amber-50 px-3 py-2">
                                <p class="text-sm font-medium text-amber-900">Las plantillas de este pedido ya se descargaron</p>
                                <p class="mt-1 text-sm text-amber-800">
                                    Se bajaron el <span class="cifras">{{ $pedido->plantillas_descargadas_en->format('d/m/Y H:i') }}</span>.
                                    Si ya lo importaste en DTW, no lo reverses: márcalo como importado.
                                </p>
                                <label class="mt-2 flex items-start gap-2 text-sm text-amber-900">
                                    <input type="checkbox" wire:model="confirmaPlantillas" class="mt-0.5 size-4">
                                    <span>Confirmo que este pedido no se importó en DTW</span>
                                </label>
                            </div>
                        @endif

                        <label class="block">
                            <span class="mb-1 block text-sm font-medium">¿Qué hay que corregir?</span>
                            <textarea rows="2" wire:model="motivoReversa" autofocus
                                      placeholder="El asesor va a leer esto para corregir el pedido"
                                      class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none"></textarea>
                        </label>
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="reversar" wire:loading.attr="disabled"
                                    class="flex-1 rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                                Reversar a borrador
                            </button>
                            <button type="button" wire:click="cancelarReversa"
                                    class="rounded-lg px-4 py-3 text-niquel hover:text-grafito">
                                Cancelar
                            </button>
                        </div>
                    </div>
                @endif
            @endif
        </section>
    @endif

    {{-- Quien no es el autor entra a revisar. Editar es una decision aparte,
         y se ve como tal. --}}
    @if ($puedeTomarEdicion)
        <button type="button" wire:click="tomarEdicion"
                class="mb-4 w-full rounded-lg border border-acero-hondo bg-white px-4 py-3 text-sm font-medium hover:bg-acero">
            Editar este pedido
        </button>
    @endif

    {{-- Productos --}}
    @if ($editable)
        <section class="mb-4 rounded-lg bg-white p-4">
            @if (! $producto)
                <label class="block">
                    <span class="sr-only">Buscar producto</span>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="buscarProducto"
                        placeholder="Buscar producto por código, nombre o familia"
                        autocomplete="off"
                        class="w-full rounded-lg border border-acero-hondo py-3 px-4 text-base
                               placeholder:text-niquel-claro focus:border-naranja focus:outline-none"
                    >
                </label>

                @if ($resultados->isNotEmpty())
                    <ul class="mt-2 divide-y divide-acero">
                        @foreach ($resultados as $resultado)
                            <li>
                                <button
                                    type="button"
                                    wire:click="elegirProducto({{ $resultado->id }})"
                                    class="flex w-full items-center gap-3 py-3 text-left hover:bg-acero/60"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="cifras block text-xs text-niquel">{{ $resultado->codigo }}</span>
                                        <span class="block text-sm leading-snug">{{ $resultado->descripcion }}</span>
                                        <x-existencias class="mt-0.5"
                                            :disponible="$resultado->disponible_total"
                                            :corte="$resultado->corte_inventario" />
                                    </span>
                                    <span class="cifras shrink-0 text-sm text-niquel">
                                        $ {{ number_format($resultado->precio_lista, 0, ',', '.') }}
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif (trim($buscarProducto) !== '')
                    <p class="mt-3 text-sm text-niquel">Ningún producto coincide con «{{ $buscarProducto }}».</p>
                @endif
            @else
                @php
                    $calc = app(CalculadoraPrecios::class);
                    $vista = $calc->linea(
                        (float) $producto->precio_lista,
                        $descuentoCliente,
                        (float) str_replace(',', '.', $cantidad ?: '0'),
                        $atp === '' ? null : (float) str_replace(',', '.', $atp),
                        $precioManual === '' ? null : (float) str_replace('.', '', $precioManual),
                    );
                @endphp

                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="cifras text-xs text-niquel">{{ $producto->codigo }}</p>
                        <p class="text-sm leading-snug">{{ $producto->descripcion }}</p>
                        <x-existencias class="mt-0.5"
                            :disponible="$producto->disponible_total"
                            :corte="$producto->corte_inventario"
                            :pedida="(float) str_replace(',', '.', $cantidad ?: '0')" />
                    </div>
                    <button type="button" wire:click="cancelarProducto" class="shrink-0 text-sm text-niquel hover:text-grafito">
                        Cambiar
                    </button>
                </div>

                <div class="mt-4 grid grid-cols-3 gap-3">
                    <label class="block">
                        <span class="mb-1 block text-xs text-niquel">Cantidad</span>
                        <input type="number" inputmode="numeric" min="1" wire:model.live.debounce.300ms="cantidad"
                               class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-niquel">ATP %</span>
                        <input type="number" inputmode="decimal" min="0" wire:model.live.debounce.300ms="atp"
                               @disabled($precioManual !== '')
                               class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none disabled:bg-acero disabled:text-niquel-claro">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs text-niquel">Precio manual</span>
                        <input type="number" inputmode="numeric" min="0" wire:model.live.debounce.300ms="precioManual"
                               @disabled($atp !== '')
                               class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none disabled:bg-acero disabled:text-niquel-claro">
                    </label>
                </div>

                {{-- El precio final se ve antes de agregar, no despues. --}}
                <dl class="cifras mt-4 space-y-1 text-sm">
                    <div class="flex justify-between text-niquel">
                        <dt>Precio de lista</dt>
                        <dd>$ {{ number_format($vista['precio_lista'], 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex justify-between text-niquel">
                        <dt>Con descuento del cliente ({{ rtrim(rtrim(number_format($descuentoCliente, 2, ',', '.'), '0'), ',') }}%)</dt>
                        <dd>$ {{ number_format($vista['precio_con_descuento'], 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex justify-between font-medium">
                        <dt>Precio unitario</dt>
                        <dd>$ {{ number_format($vista['precio_unitario'], 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-acero pt-1 font-medium">
                        <dt>Subtotal</dt>
                        <dd>$ {{ number_format($vista['subtotal_linea'], 0, ',', '.') }}</dd>
                    </div>
                </dl>

                {{-- Se avisa pero no se bloquea: a veces se pide contra
                     reposicion, y el asesor sabe algo que la foto no. --}}
                @if ($producto->corte_inventario
                    && (float) str_replace(',', '.', $cantidad ?: '0') > (float) $producto->disponible_total)
                    <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        Estás pidiendo más de lo que hay en existencias. Se puede enviar igual,
                        pero bodega podría no despacharlo completo.
                    </p>
                @endif

                <button type="button" wire:click="agregar"
                        class="mt-4 w-full rounded-lg bg-grafito px-4 py-3 font-semibold text-white hover:bg-grafito-suave">
                    Agregar al pedido
                </button>
            @endif
        </section>
    @endif

    {{-- Lineas --}}
    <section class="mb-4 overflow-hidden rounded-lg bg-white">
        @forelse ($lineas as $linea)
            <div class="flex items-start gap-3 border-b border-acero px-4 py-3.5 last:border-b-0">
                <div class="min-w-0 flex-1">
                    <p class="cifras text-xs text-niquel">{{ $linea->codigo_producto }}</p>
                    <p class="text-sm leading-snug">{{ $linea->descripcion }}</p>
                    <p class="cifras mt-1 text-sm text-niquel">
                        {{ rtrim(rtrim(number_format($linea->cantidad, 2, ',', '.'), '0'), ',') }}
                        &times; $ {{ number_format($linea->precio_unitario, 0, ',', '.') }}
                        @if ($linea->atp_descuento_pct)
                            <span class="text-grafito-suave">· ATP {{ rtrim(rtrim(number_format($linea->atp_descuento_pct, 2, ',', '.'), '0'), ',') }}%</span>
                        @elseif ($linea->precio_manual)
                            <span class="text-grafito-suave">· precio manual</span>
                        @endif
                    </p>
                </div>

                <div class="flex shrink-0 flex-col items-end gap-1">
                    <span class="cifras font-medium">$ {{ number_format($linea->subtotal_linea, 0, ',', '.') }}</span>
                    @if ($editable)
                        <button type="button" wire:click="quitar({{ $linea->id }})"
                                wire:confirm="¿Quitar {{ $linea->codigo_producto }} del pedido?"
                                class="text-xs text-niquel hover:text-red-700">Quitar</button>
                    @elseif ($puedeAjustar && $lineas->count() > 1)
                        {{-- El cliente canceló un ítem después de la aprobación.
                             Devolver el pedido al asesor para eso es
                             desproporcionado. --}}
                        <button type="button" wire:click="quitarAprobada({{ $linea->id }})"
                                wire:confirm="¿Quitar {{ $linea->codigo_producto }}? El pedido ya está aprobado: se recalculan los totales y queda registrado."
                                class="text-xs text-niquel hover:text-red-700">Quitar</button>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-6 py-10 text-center">
                <p class="font-medium">El pedido está vacío</p>
                <p class="mt-1 text-sm text-niquel">Busca un producto arriba para empezar.</p>
            </div>
        @endforelse

        @if ($lineas->isNotEmpty())
            <dl class="cifras space-y-1 bg-acero/50 px-4 py-3 text-sm">
                <div class="flex justify-between text-niquel">
                    <dt>Subtotal</dt>
                    <dd>$ {{ number_format($pedido->subtotal, 0, ',', '.') }}</dd>
                </div>
                <div class="flex justify-between text-niquel">
                    <dt>IVA</dt>
                    <dd>$ {{ number_format($pedido->iva, 0, ',', '.') }}</dd>
                </div>
                <div class="flex justify-between text-base font-semibold">
                    <dt>Total</dt>
                    <dd>$ {{ number_format($pedido->total, 0, ',', '.') }}</dd>
                </div>
            </dl>
        @endif
    </section>

    {{-- Lo mismo de arriba, pero para quien no edita. Un aprobador necesita
         leer la orden de compra y las observaciones antes de decidir: sin esto
         estaria aprobando solo la lista de productos. --}}
    @if (! $editable)
        <section class="mb-4 rounded-lg bg-white p-4">
            <h2 class="font-titulo mb-3 font-semibold">Datos del pedido</h2>

            <dl class="space-y-2.5 text-sm">
                <div>
                    <dt class="text-xs text-niquel">Lo hizo</dt>
                    <dd>{{ $pedido->creador?->nombre ?? 'Sin registrar' }}
                        <span class="cifras text-niquel">· {{ $pedido->created_at->format('d/m/Y H:i') }}</span>
                    </dd>
                </div>

                <div>
                    <dt class="text-xs text-niquel">Orden de compra</dt>
                    <dd class="cifras">{{ $pedido->orden_compra ?: '—' }}</dd>
                </div>

                <div>
                    <dt class="text-xs text-niquel">Fecha de facturación</dt>
                    <dd class="cifras">{{ $pedido->fecha_facturacion?->format('d/m/Y') ?: '—' }}</dd>
                </div>

                @if ($pedido->direccion_2)
                    <div>
                        <dt class="text-xs text-niquel">Entrega en otra dirección</dt>
                        <dd>{{ $pedido->direccion_2 }}@if ($pedido->ciudad_2), {{ $pedido->ciudad_2 }}@endif</dd>
                    </div>
                @endif

                @if ($pedido->observaciones)
                    <div>
                        <dt class="text-xs text-niquel">Observaciones</dt>
                        <dd class="whitespace-pre-line">{{ $pedido->observaciones }}</dd>
                    </div>
                @endif

                @if ($pedido->aprobador)
                    <div>
                        <dt class="text-xs text-niquel">Aprobó</dt>
                        <dd>{{ $pedido->aprobador->nombre }}
                            <span class="cifras text-niquel">· {{ $pedido->fecha_aprobacion?->format('d/m/Y H:i') }}</span>
                        </dd>
                    </div>
                @endif

                @if ($pedido->liberador)
                    <div>
                        <dt class="text-xs text-niquel">Liberó a SAP</dt>
                        <dd>{{ $pedido->liberador->nombre }}
                            <span class="cifras text-niquel">· {{ $pedido->fecha_liberacion?->format('d/m/Y H:i') }}</span>
                        </dd>
                    </div>
                @endif

                {{-- El numero con el que quedó en SAP. Es lo primero que se
                     pregunta cuando hay que buscarlo allá. --}}
                @if ($pedido->sap_docnum)
                    <div>
                        <dt class="text-xs text-niquel">Número en SAP</dt>
                        <dd class="cifras">{{ $pedido->sap_docnum }}
                            <span class="text-niquel">· {{ $pedido->fecha_importacion?->format('d/m/Y H:i') }}</span>
                        </dd>
                    </div>
                @endif
            </dl>
        </section>
    @endif

    {{-- Encabezado --}}
    @if ($editable)
        <section class="mb-4 rounded-lg bg-white p-4">
            <h2 class="font-titulo mb-3 font-semibold">Datos del pedido</h2>

            <div class="space-y-3">
                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Orden de compra</span>
                    <input type="text" wire:model.blur="orden_compra"
                           class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Fecha de facturación</span>
                    <input type="date" wire:model.blur="fecha_facturacion"
                           class="cifras w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none">
                </label>

                {{-- Dirección y ciudad van juntas y siempre visibles. Antes la
                     ciudad solo aparecía cuando la dirección llegaba al
                     servidor, así que quien escribía la dirección y enviaba de
                     una veía el error sin haber visto nunca el campo. Una
                     dirección sin ciudad no sirve para despachar: son un par. --}}
                <fieldset>
                    <legend class="mb-1 text-xs text-niquel">Entrega en otra dirección</legend>

                    <div class="space-y-2">
                        <label class="block">
                            <span class="sr-only">Dirección de entrega alterna</span>
                            <input type="text" wire:model.blur="direccion_2" placeholder="Dirección" maxlength="60"
                                   class="w-full rounded-lg border border-acero-hondo px-3 py-2.5
                                          placeholder:text-niquel-claro focus:border-naranja focus:outline-none">
                        </label>
                        @error('direccion_2')
                            <p class="text-sm text-red-700">{{ $message }}</p>
                        @enderror

                        <label class="block">
                            <span class="sr-only">Ciudad de la dirección alterna</span>
                            <input type="text" wire:model.blur="ciudad_2" placeholder="Ciudad"
                                   @class([
                                       'w-full rounded-lg border px-3 py-2.5 placeholder:text-niquel-claro focus:outline-none',
                                       'border-red-400 focus:border-red-500' => trim($direccion_2) !== '' && trim($ciudad_2) === '',
                                       'border-acero-hondo focus:border-naranja' => ! (trim($direccion_2) !== '' && trim($ciudad_2) === ''),
                                   ])>
                        </label>
                    </div>

                    @if (trim($direccion_2) !== '' && trim($ciudad_2) === '')
                        <p class="mt-1 text-sm text-red-700">
                            Falta la ciudad. Sin ella el pedido no se puede enviar.
                        </p>
                    @else
                        <p class="mt-1 text-sm text-niquel">
                            Solo si el cliente recibe en un sitio distinto al de su ficha.
                        </p>
                    @endif
                </fieldset>

                <label class="block">
                    <span class="mb-1 block text-xs text-niquel">Observaciones</span>
                    <textarea rows="2" wire:model.blur="observaciones" maxlength="250"
                              class="w-full rounded-lg border border-acero-hondo px-3 py-2.5 focus:border-naranja focus:outline-none"></textarea>
                    @error('observaciones')
                        <span class="mt-1 block text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>
            </div>
        </section>

        @php
            // Se mira aquí y no solo al enviar: es mejor que el botón diga por
            // qué no se puede, a que el asesor lo pulse y reciba un error.
            $faltaCiudad = trim($direccion_2) !== '' && trim($ciudad_2) === '';
        @endphp

        <button type="button" wire:click="enviar" wire:loading.attr="disabled"
                @disabled($lineas->isEmpty() || $faltaCiudad)
                class="w-full rounded-lg bg-naranja px-4 py-4 text-lg font-semibold text-white
                       hover:bg-naranja-hondo disabled:cursor-not-allowed disabled:opacity-50">
            Enviar a aprobación
        </button>

        @if ($lineas->isEmpty())
            <p class="mt-2 text-center text-sm text-niquel">Agrega al menos un producto para poder enviarlo.</p>
        @elseif ($faltaCiudad)
            <p class="mt-2 text-center text-sm text-niquel">Falta la ciudad de la dirección alterna.</p>
        @endif

        {{-- Soltar el bloqueo a proposito, sin esperar a que expire: asi el
             pedido queda aprobable de inmediato cuando el asesor termina. --}}
        @if ($pedido->bloqueado_por === auth()->id())
            <button type="button" wire:click="terminarEdicion"
                    class="mt-3 w-full rounded-lg border border-acero-hondo px-4 py-3 text-sm font-medium hover:bg-acero">
                Guardar y salir
            </button>
        @endif
    @endif

    @if ($puedeEliminar)
        <button type="button" wire:click="eliminar"
                wire:confirm="¿Eliminar el pedido #{{ $pedido->id }}? Queda en la papelera 30 días."
                class="mt-6 w-full rounded-lg px-4 py-3 text-sm text-niquel hover:text-red-700">
            Eliminar pedido
        </button>
    @endif
</div>
