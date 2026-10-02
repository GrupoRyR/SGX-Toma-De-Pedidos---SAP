<?php

namespace App\Services;

use App\Enums\EstadoPedido;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\PedidoLinea;
use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Toda la escritura de pedidos pasa por aqui.
 *
 * Existe para que las reglas vivan en un solo lugar y no repartidas entre
 * pantallas: numeracion de lineas, recalculo de totales, bloqueo de edicion y
 * el control de version. En la app vieja cada pantalla hacia su propia version
 * de estas cuentas, y por eso diferian.
 */
class ServicioPedidos
{
    public function __construct(
        private readonly CalculadoraPrecios $calculadora,
        private readonly Bitacorero $bitacora,
        private readonly CorreoGraph $correo,
    ) {}

    /**
     * Crea un pedido en BORRADOR para un cliente.
     *
     * Los datos del cliente se copian al pedido a proposito: el pedido debe
     * poder leerse tal como se hizo, aunque despues cambien la direccion o el
     * nombre del cliente en SAP.
     */
    public function crear(Cliente $cliente, Usuario $usuario): Pedido
    {
        $pedido = DB::transaction(fn () => Pedido::create([
            'cliente_id' => $cliente->id,
            'codigo_cliente' => $cliente->codigo_sn,
            'nombre_cliente' => $cliente->nombre,
            'direccion' => $cliente->direccion,
            'ciudad' => $cliente->ciudad,
            'estado' => EstadoPedido::BORRADOR,
            'creado_por' => $usuario->id,
        ]));

        // El numero lo da la base de datos, nunca "el ultimo de la lista": ese
        // fue el error que hacia que dos asesores simultaneos chocaran.
        $this->bitacora->registrar('CREAR', 'pedido', $pedido->id, [
            'cliente' => $cliente->codigo_sn,
        ]);

        return $pedido;
    }

    /**
     * Agrega una linea y devuelve el pedido recalculado.
     *
     * ATP y precio manual son excluyentes; si llegan los dos, manda el manual.
     * Ninguno tiene tope: SEGUREX decidio que el control es humano.
     */
    public function agregarLinea(
        Pedido $pedido,
        Producto $producto,
        float $cantidad,
        ?float $atp = null,
        ?float $precioManual = null,
    ): PedidoLinea {
        if ($cantidad <= 0) {
            throw new RuntimeException('La cantidad tiene que ser mayor que cero.');
        }

        $this->exigirEditable($pedido);

        $descuento = (float) ($pedido->cliente->porcentaje_descuento ?? 0);

        $valores = $this->calculadora->linea(
            precioLista: (float) $producto->precio_lista,
            descuentoCliente: $descuento,
            cantidad: $cantidad,
            atp: $atp,
            precioManual: $precioManual,
        );

        return DB::transaction(function () use ($pedido, $producto, $cantidad, $atp, $precioManual, $valores) {
            $linea = $pedido->lineas()->create([
                'linea_num' => (int) $pedido->lineas()->max('linea_num') + 1,
                'codigo_producto' => $producto->codigo,
                'descripcion' => $producto->descripcion,
                'cantidad' => $cantidad,
                'atp_descuento_pct' => $precioManual ? null : $atp,
                'precio_manual' => $precioManual,
                'precio_lista' => $valores['precio_lista'],
                'precio_con_descuento' => $valores['precio_con_descuento'],
                'precio_unitario' => $valores['precio_unitario'],
                'subtotal_linea' => $valores['subtotal_linea'],
            ]);

            $this->recalcular($pedido);

            $this->bitacora->registrar('AGREGAR_LINEA', 'pedido', $pedido->id, [
                'producto' => $producto->codigo,
                'cantidad' => $cantidad,
                'precio_unitario' => $valores['precio_unitario'],
            ]);

            return $linea;
        });
    }

    public function quitarLinea(Pedido $pedido, PedidoLinea $linea): void
    {
        $this->exigirEditable($pedido);

        if ($linea->pedido_id !== $pedido->id) {
            throw new RuntimeException('Esa linea no pertenece a este pedido.');
        }

        DB::transaction(function () use ($pedido, $linea) {
            $codigo = $linea->codigo_producto;
            $linea->delete();

            $this->renumerar($pedido);
            $this->recalcular($pedido);

            $this->bitacora->registrar('QUITAR_LINEA', 'pedido', $pedido->id, ['producto' => $codigo]);
        });
    }

    /**
     * Envia el pedido a aprobacion.
     *
     * Renumera las lineas 0..n-1 justo antes de cambiar de estado: SAP rechaza
     * la importacion si LineNum llega vacio o con huecos, que es lo que pasaba
     * cuando se borraba una linea en la app vieja.
     */
    /**
     * Quita una linea de un pedido ya aprobado, antes de que entre a SAP.
     *
     * El caso: el cliente cancela un item despues de la aprobacion. Quien
     * aprobo puede quitarlo sin devolver el pedido al asesor.
     *
     * No se toca `snapshot_aprobado` a proposito. Esa foto dice que fue lo que
     * se aprobo, y sigue siendo cierta; lo que paso despues queda en el log
     * como un ajuste aparte. Reescribirla borraria la unica evidencia de que
     * el pedido cambio despues de aprobado.
     */
    public function quitarLineaAprobada(Pedido $pedido, PedidoLinea $linea, Usuario $usuario): Pedido
    {
        if ($linea->pedido_id !== $pedido->id) {
            throw new RuntimeException('Esa linea no pertenece a este pedido.');
        }

        return DB::transaction(function () use ($pedido, $linea) {
            $actual = Pedido::whereKey($pedido->getKey())->lockForUpdate()->first();

            if (! $actual || $actual->importado_sap) {
                throw new RuntimeException('Este pedido ya entro a SAP: lo que haya que corregir se corrige alla.');
            }

            if ($actual->lineas()->count() <= 1) {
                throw new RuntimeException('Es la unica linea del pedido. Si ya no va, rechaza el pedido completo.');
            }

            $codigo = $linea->codigo_producto;
            $totalAntes = (float) $actual->total;

            $linea->delete();

            $this->renumerar($actual);
            $this->recalcular($actual);

            $actual->refresh();

            $this->bitacora->registrar('AJUSTAR_APROBADO', 'pedido', $actual->id, [
                'quito' => $codigo,
                'total_antes' => $totalAntes,
                'total_despues' => (float) $actual->total,
                'estado' => $actual->estado->value,
                // Si ya se habian descargado las plantillas, el archivo que
                // alguien tiene en la mano quedo viejo. Queda anotado.
                'plantillas_ya_descargadas' => $actual->plantillas_descargadas_en !== null,
            ]);

            return $actual;
        });
    }

    public function enviar(Pedido $pedido, Usuario $usuario): Pedido
    {
        $this->exigirEditable($pedido);

        if ($pedido->lineas()->count() === 0) {
            throw new RuntimeException('El pedido necesita al menos un producto.');
        }

        if ($pedido->direccion_2 && ! $pedido->ciudad_2) {
            throw new RuntimeException('Si hay direccion alterna, la ciudad alterna es obligatoria.');
        }

        return DB::transaction(function () use ($pedido) {
            $this->renumerar($pedido);
            $this->recalcular($pedido);

            $pedido->forceFill([
                'estado' => EstadoPedido::PENDIENTE,
                'motivo_rechazo' => null,
                'bloqueado_por' => null,
                'bloqueado_hasta' => null,
            ])->save();

            $this->bitacora->registrar('ENVIAR', 'pedido', $pedido->id, [
                'lineas' => $pedido->lineas()->count(),
                'total' => (float) $pedido->total,
            ]);

            return $pedido->refresh();
        });
    }

    /**
     * Manda el pedido a la papelera.
     *
     * Es borrado logico: queda recuperable 30 dias. Las lineas se conservan con
     * el, asi que restaurarlo lo devuelve completo. En la app vieja el borrado
     * era definitivo y dejaba las lineas sueltas para siempre.
     */
    public function eliminar(Pedido $pedido, Usuario $usuario): void
    {
        if ($pedido->importado_sap) {
            throw new RuntimeException('Un pedido que ya entro a SAP no se elimina: se corrige en SAP.');
        }

        DB::transaction(function () use ($pedido) {
            $this->bitacora->registrar('ELIMINAR', 'pedido', $pedido->id, [
                'estado' => $pedido->estado->value,
                'cliente' => $pedido->codigo_cliente,
                'total' => (float) $pedido->total,
                'lineas' => $pedido->lineas()->count(),
            ]);

            $pedido->delete();
        });
    }

    /** Saca un pedido de la papelera. */
    public function restaurar(Pedido $pedido): void
    {
        $pedido->restore();

        $this->bitacora->registrar('RESTAURAR', 'pedido', $pedido->id, []);
    }

    /** Recalcula totales desde las lineas y sube la version del pedido. */
    public function recalcular(Pedido $pedido): void
    {
        $subtotales = $pedido->lineas()->pluck('subtotal_linea')->map(fn ($v) => (float) $v)->all();
        $totales = $this->calculadora->totales($subtotales);

        $pedido->forceFill([
            'subtotal' => $totales['subtotal'],
            'iva' => $totales['iva'],
            'total' => $totales['total'],
            // La version sube con cada cambio. Es lo que permite al aprobador
            // detectar que el pedido cambio mientras lo estaba revisando.
            'version' => $pedido->version + 1,
        ])->save();
    }

    /** Deja las lineas en 0..n-1, sin huecos, como las exige SAP. */
    public function renumerar(Pedido $pedido): void
    {
        $numero = 0;

        foreach ($pedido->lineas()->orderBy('linea_num')->orderBy('id')->get() as $linea) {
            if ((int) $linea->linea_num !== $numero) {
                $linea->forceFill(['linea_num' => $numero])->save();
            }

            $numero++;
        }
    }

    /**
     * Toma el bloqueo de edicion.
     *
     * Reemplaza el estado EDITANDO de la app vieja, que dejaba pedidos
     * atascados para siempre cuando el asesor cerraba el navegador.
     */
    public function bloquear(Pedido $pedido, Usuario $usuario): bool
    {
        if ($pedido->bloqueadoPorOtro($usuario)) {
            return false;
        }

        $minutos = (int) Configuracion::valor('minutos_bloqueo', 30);

        $pedido->forceFill([
            'bloqueado_por' => $usuario->id,
            'bloqueado_hasta' => now()->addMinutes($minutos),
        ])->save();

        return true;
    }

    public function liberarBloqueo(Pedido $pedido): void
    {
        $pedido->forceFill(['bloqueado_por' => null, 'bloqueado_hasta' => null])->save();
    }

    /**
     * Aprueba un pedido.
     *
     * Dos verificaciones dentro de la misma transaccion, cada una tapando un
     * error concreto de la app vieja:
     *
     *  1. Que siga PENDIENTE. Evita la doble aprobacion por doble clic o por
     *     dos aprobadores mirando el mismo pedido.
     *  2. Que la version sea la que el aprobador tenia en pantalla. El asesor
     *     puede editar un pedido PENDIENTE, asi que sin esto se podria terminar
     *     aprobando algo distinto de lo que se leyo.
     */
    public function aprobar(Pedido $pedido, Usuario $usuario, ?int $versionVista = null): Pedido
    {
        return DB::transaction(function () use ($pedido, $usuario, $versionVista) {
            $actual = Pedido::whereKey($pedido->getKey())->lockForUpdate()->first();

            if (! $actual || $actual->estado !== EstadoPedido::PENDIENTE) {
                throw new RuntimeException('Este pedido ya no esta pendiente: alguien lo atendio antes.');
            }

            if ($versionVista !== null && (int) $actual->version !== $versionVista) {
                throw new RuntimeException('El pedido cambio mientras lo revisabas. Vuelve a mirarlo antes de aprobar.');
            }

            if ($actual->lineas()->count() === 0) {
                throw new RuntimeException('No se puede aprobar un pedido sin productos.');
            }

            $this->renumerar($actual);

            $actual->forceFill([
                'estado' => EstadoPedido::APROBADO,
                'aprobado_por' => $usuario->id,
                'fecha_aprobacion' => now(),
                'motivo_rechazo' => null,
                // Copia congelada de lo que se aprobo. Si despues cambia la lista
                // de precios, sigue siendo posible responder que se aprobo.
                'snapshot_aprobado' => $this->fotografiar($actual),
            ])->save();

            $esPropio = $actual->creado_por === $usuario->id;

            $this->bitacora->registrar('APROBAR', 'pedido', $actual->id, [
                'total' => (float) $actual->total,
                // SEGUREX permite que TI apruebe lo suyo para pruebas e
                // inducciones. Se marca para poder distinguirlo despues.
                'autoaprobacion' => $esPropio,
                'rol' => $usuario->rol->value,
            ]);

            return $actual;
        });
    }

    public function rechazar(Pedido $pedido, Usuario $usuario, string $motivo): Pedido
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('El rechazo necesita un motivo: el asesor tiene que saber que corregir.');
        }

        $pedido = DB::transaction(function () use ($pedido, $motivo) {
            $actual = Pedido::whereKey($pedido->getKey())->lockForUpdate()->first();

            if (! $actual || $actual->estado !== EstadoPedido::PENDIENTE) {
                throw new RuntimeException('Este pedido ya no esta pendiente: alguien lo atendio antes.');
            }

            $actual->forceFill([
                'estado' => EstadoPedido::RECHAZADO,
                'motivo_rechazo' => $motivo,
                'aprobado_por' => null,
                'fecha_aprobacion' => null,
            ])->save();

            $this->bitacora->registrar('RECHAZAR', 'pedido', $actual->id, ['motivo' => $motivo]);

            return $actual;
        });

        /*
         * El aviso va despues de la transaccion, no dentro. Si se mandara
         * adentro, una demora de Graph tendria la fila del pedido bloqueada
         * mientras tanto, y un fallo de correo desharia un rechazo que ya se
         * decidio.
         */
        $this->avisarDevolucion($pedido, $usuario, 'RECHAZO');

        return $pedido;
    }

    /**
     * Devuelve un pedido APROBADO o LIBERADO a BORRADOR para que el asesor lo
     * corrija y lo vuelva a enviar.
     *
     * Existe porque despues de aprobado solo se podia quitar lineas. Agregar o
     * cambiar cantidades cambia lo que se aprobo, asi que tiene que volver a
     * pasar por aprobacion completa.
     *
     * Se limpian la aprobacion, el visto bueno, la copia congelada y la marca
     * de plantillas: el pedido vuelve a empezar. Lo que se habia aprobado no se
     * pierde, queda en el detalle de la bitacora.
     *
     * Si las plantillas ya se descargaron, ese pedido puede estar en un archivo
     * de DTW listo para importar. Reversarlo sin saberlo dejaria la web
     * diciendo borrador y SAP con el pedido creado, por eso exige que quien
     * reversa confirme que no se importo.
     */
    public function reversarABorrador(Pedido $pedido, Usuario $usuario, string $motivo, bool $confirmaPlantillas = false): Pedido
    {
        if (! $usuario->puedeAprobar()) {
            throw new RuntimeException('No tienes permiso para reversar pedidos aprobados.');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('Reversar necesita un motivo: el asesor tiene que saber que corregir.');
        }

        $pedido = DB::transaction(function () use ($pedido, $usuario, $motivo, $confirmaPlantillas) {
            $actual = Pedido::whereKey($pedido->getKey())->lockForUpdate()->first();

            if (! $actual
                || ! in_array($actual->estado, [EstadoPedido::APROBADO, EstadoPedido::LIBERADO], true)
                || $actual->importado_sap) {
                throw new RuntimeException('Solo se reversa un pedido aprobado que todavia no entro a SAP.');
            }

            // El alcance se revisa aqui tambien, no solo en la politica: una
            // gerente reversa los pedidos de su canal y ninguno mas, entre por
            // donde entre.
            if (! Pedido::visiblePara($usuario)->whereKey($actual->getKey())->exists()) {
                throw new RuntimeException('No tienes permiso para reversar este pedido.');
            }

            if ($actual->bloqueadoPorOtro($usuario)) {
                throw new RuntimeException('Alguien esta editando este pedido en este momento.');
            }

            if ($actual->plantillas_descargadas_en !== null && ! $confirmaPlantillas) {
                throw new RuntimeException('Las plantillas de este pedido ya se descargaron. Confirma que no se importo en DTW antes de reversarlo.');
            }

            $detalle = [
                'motivo' => $motivo,
                'estado_anterior' => $actual->estado->value,
                'aprobado_por' => $actual->aprobado_por,
                'fecha_aprobacion' => $actual->fecha_aprobacion?->toIso8601String(),
                'liberado_por' => $actual->liberado_por,
                'fecha_liberacion' => $actual->fecha_liberacion?->toIso8601String(),
                'plantillas_descargadas_en' => $actual->plantillas_descargadas_en?->toIso8601String(),
                'version_al_descargar' => $actual->version_al_descargar,
                // La evidencia de lo que se habia aprobado. En el pedido se
                // borra porque la proxima aprobacion toma una copia nueva.
                'snapshot_aprobado' => $actual->snapshot_aprobado,
            ];

            $actual->forceFill([
                'estado' => EstadoPedido::BORRADOR,
                'motivo_rechazo' => $motivo,
                'aprobado_por' => null,
                'fecha_aprobacion' => null,
                'liberado_por' => null,
                'fecha_liberacion' => null,
                'snapshot_aprobado' => null,
                'plantillas_descargadas_en' => null,
                'version_al_descargar' => null,
                // El bloqueo de quien reversa no le sirve al asesor: lo deja
                // libre para que lo tome al abrir el pedido.
                'bloqueado_por' => null,
                'bloqueado_hasta' => null,
                // Cualquier pantalla abierta con la version anterior queda vieja.
                'version' => $actual->version + 1,
            ])->save();

            $this->bitacora->registrar('REVERSAR_APROBADO', 'pedido', $actual->id, $detalle);

            return $actual;
        });

        // Fuera de la transaccion, por la misma razon que en el rechazo.
        $this->avisarDevolucion($pedido, $usuario, 'REVERSAR');

        return $pedido;
    }

    /**
     * Le avisa al asesor que le devolvieron el pedido, por rechazo o porque se
     * reverso una aprobacion. Para el asesor es lo mismo: tiene que corregir.
     *
     * Nunca lanza: la devolucion ya ocurrio, y que el correo falle no puede
     * deshacerla. El intento queda en la tabla de notificaciones con el evento,
     * para distinguir un caso del otro.
     */
    private function avisarDevolucion(Pedido $pedido, Usuario $quienRechazo, string $evento): void
    {
        $asesor = $pedido->creador;

        if (! $asesor || ! $asesor->correo) {
            return;
        }

        // No tiene sentido avisarle a alguien de algo que acaba de hacer el mismo.
        if ($asesor->id === $quienRechazo->id) {
            return;
        }

        $this->correo->enviar(
            para: $asesor->correo,
            asunto: "Te devolvieron el pedido #{$pedido->id}",
            cuerpoHtml: view('correos.rechazo', [
                'pedido' => $pedido,
                'quienRechazo' => $quienRechazo->nombre,
                'enlace' => route('pedido', $pedido),
            ])->render(),
            evento: $evento,
            pedidoId: $pedido->id,
        );
    }

    /**
     * Da el visto bueno para SAP.
     *
     * Es el segundo control, y es humano por decision de SEGUREX: ningun pedido
     * llega a SAP solo por estar aprobado. Solo lo pueden dar quienes tienen el
     * permiso LIBERAR_SAP, hoy Cesar Garzon y Marly Ossa.
     */
    public function liberar(Pedido $pedido, Usuario $usuario): Pedido
    {
        if (! $usuario->puedeLiberarASap()) {
            throw new RuntimeException('No tienes el permiso para liberar pedidos a SAP.');
        }

        return DB::transaction(function () use ($pedido, $usuario) {
            $actual = Pedido::whereKey($pedido->getKey())->lockForUpdate()->first();

            if (! $actual || $actual->estado !== EstadoPedido::APROBADO) {
                throw new RuntimeException('Solo se libera un pedido aprobado.');
            }

            // "Alguien" es otra persona. Si el bloqueo es de quien libera,
            // no hay nadie mas tocando el pedido.
            if ($actual->bloqueadoPorOtro($usuario)) {
                throw new RuntimeException('Alguien esta editando este pedido en este momento.');
            }

            $actual->forceFill([
                'estado' => EstadoPedido::LIBERADO,
                'liberado_por' => $usuario->id,
                'fecha_liberacion' => now(),
            ])->save();

            $this->bitacora->registrar('LIBERAR', 'pedido', $actual->id, [
                'total' => (float) $actual->total,
            ]);

            return $actual;
        });
    }

    /** Devuelve un pedido liberado a APROBADO, mientras no haya entrado a SAP. */
    public function devolverDeLiberado(Pedido $pedido, Usuario $usuario, string $motivo): Pedido
    {
        // Devolver deshace el visto bueno, asi que lo puede hacer quien lo dio.
        if (! $usuario->puedeLiberarASap()) {
            throw new RuntimeException('No tienes el permiso para devolver pedidos liberados.');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('La devolucion necesita un motivo: queda en la bitacora.');
        }

        return DB::transaction(function () use ($pedido, $motivo) {
            $actual = Pedido::whereKey($pedido->getKey())->lockForUpdate()->first();

            if (! $actual || $actual->estado !== EstadoPedido::LIBERADO || $actual->importado_sap) {
                throw new RuntimeException('Solo se devuelve un pedido liberado que todavia no entro a SAP.');
            }

            $actual->forceFill([
                'estado' => EstadoPedido::APROBADO,
                'liberado_por' => null,
                'fecha_liberacion' => null,
            ])->save();

            $this->bitacora->registrar('DEVOLVER_LIBERADO', 'pedido', $actual->id, ['motivo' => $motivo]);

            return $actual;
        });
    }

    /** Copia congelada del pedido, para poder responder que se aprobo. */
    private function fotografiar(Pedido $pedido): array
    {
        return [
            'tomada_en' => now()->toIso8601String(),
            'cliente' => $pedido->codigo_cliente,
            'subtotal' => (float) $pedido->subtotal,
            'iva' => (float) $pedido->iva,
            'total' => (float) $pedido->total,
            'version' => (int) $pedido->version,
            'lineas' => $pedido->lineas()->orderBy('linea_num')->get([
                'linea_num', 'codigo_producto', 'descripcion', 'cantidad',
                'atp_descuento_pct', 'precio_manual', 'precio_unitario', 'subtotal_linea',
            ])->toArray(),
        ];
    }

    private function exigirEditable(Pedido $pedido): void
    {
        if (! $pedido->estado->editablePorAsesor()) {
            throw new RuntimeException('Un pedido '.$pedido->estado->etiqueta().' ya no se puede modificar.');
        }
    }
}
