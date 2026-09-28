<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Usuario;

/**
 * Quien administra a quien.
 *
 * La idea de fondo: administrar la operacion no es lo mismo que repartir poder.
 * El admin de ventas gestiona a los asesores y gerentes que dependen de el;
 * tocar administradores y repartir el permiso de liberar a SAP es de TI.
 */
class UsuarioPolicy
{
    /** Entrar al modulo de administracion. */
    public function administrar(Usuario $usuario): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }

    /**
     * Modificar a otra persona.
     *
     * Nadie se edita a si mismo desde aqui: es la forma mas facil de quedarse
     * por fuera de la aplicacion sin que nadie pueda arreglarlo.
     */
    public function editar(Usuario $usuario, Usuario $objetivo): bool
    {
        if (! $this->administrar($usuario) || $usuario->id === $objetivo->id) {
            return false;
        }

        // A un administrador solo lo toca TI.
        if ($objetivo->esAdministrador()) {
            return $usuario->rol->gestionaAdministradores();
        }

        return true;
    }

    /** Convertir a alguien en administrador, o dejar de serlo. */
    public function cambiarRol(Usuario $usuario, Usuario $objetivo, Rol $nuevo): bool
    {
        if (! $this->editar($usuario, $objetivo)) {
            return false;
        }

        if ($nuevo->administra()) {
            return $usuario->rol->gestionaAdministradores();
        }

        return true;
    }

    /**
     * Repartir el permiso de liberar a SAP.
     *
     * Es la ultima puerta antes de SAP, asi que no la reparte quien la usa: si
     * el admin de ventas pudiera otorgarla, podria montar un atajo para sus
     * propios pedidos. Queda en TI, que a su vez no puede liberar.
     */
    public function otorgarLiberar(Usuario $usuario, Usuario $objetivo): bool
    {
        return $this->administrar($usuario)
            && $usuario->rol->gestionaAdministradores()
            && $objetivo->activo;
    }
}
