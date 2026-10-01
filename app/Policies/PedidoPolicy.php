<?php

namespace App\Policies;

use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Models\Pedido;
use App\Models\Usuario;

/**
 * Autorizacion sobre pedidos.
 *
 * Tres ideas que conviene no perder de vista:
 *  - El asesor edita mientras nadie haya aprobado, incluso estando PENDIENTE.
 *  - Aprobar y liberar son cosas distintas: liberar es un permiso aparte del
 *    rol, y hoy solo lo tienen Cesar Garzon y Marly Ossa.
 *  - Un pedido importado a SAP no se toca desde aqui; se corrige en SAP.
 *  - Nadie aprueba su propio pedido, salvo TI, que si puede para poder probar
 *    el flujo y hacer inducciones.
 */
class PedidoPolicy
{
    public function ver(Usuario $usuario, Pedido $pedido): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        return Pedido::query()
            ->visiblePara($usuario)
            ->whereKey($pedido->getKey())
            ->exists();
    }

    public function editar(Usuario $usuario, Pedido $pedido): bool
    {
        return $this->ver($usuario, $pedido) && $pedido->puedeEditarlo($usuario);
    }

    public function eliminar(Usuario $usuario, Pedido $pedido): bool
    {
        if ($pedido->importado_sap) {
            return false;
        }

        return $this->editar($usuario, $pedido);
    }

    public function aprobar(Usuario $usuario, Pedido $pedido): bool
    {
        if (! $usuario->puedeAprobar() || ! $this->ver($usuario, $pedido)) {
            return false;
        }

        // Solo se aprueba lo que esta esperando aprobacion. El servicio ya lo
        // verifica dentro de la transaccion; aqui es para que la pantalla no
        // ofrezca un boton que va a fallar.
        if ($pedido->estado !== EstadoPedido::PENDIENTE) {
            return false;
        }

        /*
         * Regla general: nadie aprueba su propio pedido, porque un solo par de
         * ojos no es un control.
         *
         * Excepcion pedida por SEGUREX: el rol TI si puede, para poder probar el
         * flujo completo y hacer inducciones sin necesitar a un segundo usuario.
         * Queda acotada de dos formas: solo TI, y esa aprobacion se marca en la
         * bitacora como autoaprobacion para poder distinguirla despues.
         *
         * No abre un hueco hacia SAP: liberar es un permiso aparte que TI no
         * tiene, asi que un pedido autoaprobado sigue necesitando el visto bueno
         * de Cesar Garzon o Marly Ossa para salir.
         */
        if ($pedido->creado_por === $usuario->id && $usuario->rol !== Rol::TI) {
            return false;
        }

        /*
         * No se aprueba un pedido que OTRA persona esta editando: se estaria
         * confirmando algo que esta cambiando. El bloqueo propio no cuenta,
         * porque si no un administrador que abre el pedido para revisarlo se
         * impide a si mismo aprobarlo.
         */
        return ! $pedido->bloqueadoPorOtro($usuario);
    }

    /**
     * Si esta aprobacion seria sobre el propio pedido de quien aprueba.
     *
     * Lo usa el servicio de aprobacion para marcarlo en la bitacora y la
     * interfaz para avisarlo en pantalla.
     */
    public function esAutoaprobacion(Usuario $usuario, Pedido $pedido): bool
    {
        return $pedido->creado_por === $usuario->id;
    }

    /**
     * Ajustar un pedido que ya se aprobo, antes de que entre a SAP.
     *
     * El caso real: el cliente cancela un item cuando el pedido ya paso por
     * aprobacion. Devolverlo al asesor para que lo corrija y que vuelva a
     * recorrer todo el camino es desproporcionado, asi que quien aprueba puede
     * quitar la linea directamente.
     *
     * Solo quitar, nunca agregar: agregar un producto es cambiar lo que se
     * aprobo hacia arriba, y eso si necesita volver a pasar por aprobacion.
     *
     * Se corta en seco cuando el pedido ya entro a SAP. A partir de ahi la
     * verdad esta alla y se corrige alla; si no, la web diria una cosa y SAP
     * otra, que es exactamente lo que hay que evitar.
     */
    public function ajustar(Usuario $usuario, Pedido $pedido): bool
    {
        if ($pedido->importado_sap || ! $usuario->puedeAprobar()) {
            return false;
        }

        if (! in_array($pedido->estado, [EstadoPedido::APROBADO, EstadoPedido::LIBERADO], true)) {
            return false;
        }

        return $this->ver($usuario, $pedido) && ! $pedido->bloqueadoPorOtro($usuario);
    }

    /**
     * Devolver un pedido aprobado a BORRADOR para que el asesor lo corrija.
     *
     * Es el camino cuando hay que agregar o cambiar algo despues de aprobado:
     * eso cambia lo que se aprobo, asi que tiene que volver a pasar por
     * aprobacion. Lo hace quien podria aprobar ese pedido.
     *
     * Sin la regla de autoaprobacion: devolver no aprueba nada, solo quita
     * una aprobacion. Y nunca si ya entro a SAP, por la misma razon que
     * ajustar: a partir de ahi la verdad esta alla.
     */
    public function reversar(Usuario $usuario, Pedido $pedido): bool
    {
        if ($pedido->importado_sap || ! $usuario->puedeAprobar()) {
            return false;
        }

        if (! in_array($pedido->estado, [EstadoPedido::APROBADO, EstadoPedido::LIBERADO], true)) {
            return false;
        }

        return $this->ver($usuario, $pedido) && ! $pedido->bloqueadoPorOtro($usuario);
    }

    /** Deshacer el visto bueno, mientras el robot no lo haya tomado. */
    public function devolver(Usuario $usuario, Pedido $pedido): bool
    {
        return $usuario->puedeLiberarASap()
            && $pedido->estado === EstadoPedido::LIBERADO
            && ! $pedido->importado_sap;
    }

    /** El visto bueno sin el cual ningun pedido llega a SAP. */
    public function liberar(Usuario $usuario, Pedido $pedido): bool
    {
        return $usuario->puedeLiberarASap()
            && $pedido->estado === EstadoPedido::APROBADO
            && ! $pedido->bloqueadoPorOtro($usuario);
    }
}
