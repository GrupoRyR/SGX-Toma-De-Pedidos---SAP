<?php

namespace App\Policies;

use App\Models\Cliente;
use App\Models\Usuario;

/**
 * Autorizacion sobre clientes.
 *
 * Las consultas ya filtran con visiblePara; esto es la segunda capa, para las
 * rutas que reciben un cliente por su id. Sin ella, cambiar el numero en la URL
 * alcanzaria la ficha de un cliente ajeno.
 */
class ClientePolicy
{
    public function ver(Usuario $usuario, Cliente $cliente): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        // Se resuelve con la misma regla que la lista, para que no puedan
        // separarse con el tiempo y contradecirse.
        return Cliente::query()
            ->visiblePara($usuario)
            ->whereKey($cliente->getKey())
            ->exists();
    }

    /** Solo se le hace pedido a un cliente que se puede ver y esta activo. */
    public function crearPedido(Usuario $usuario, Cliente $cliente): bool
    {
        return $cliente->activo && $this->ver($usuario, $cliente);
    }

    public function administrar(Usuario $usuario): bool
    {
        return $usuario->activo && $usuario->esAdministrador();
    }
}
