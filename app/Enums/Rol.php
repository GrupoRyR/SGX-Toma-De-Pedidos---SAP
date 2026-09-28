<?php

namespace App\Enums;

/**
 * Roles. ADMIN_VENTAS ya incluye administrar la plataforma; TI agrega la
 * configuracion del sistema, la integracion con SAP y la gestion de los propios
 * administradores.
 */
enum Rol: string
{
    case ASESOR = 'ASESOR';
    case GERENTE_CANAL = 'GERENTE_CANAL';
    case ADMIN_VENTAS = 'ADMIN_VENTAS';
    case TI = 'TI';

    /** Como se llama el rol en pantalla. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::ASESOR => 'Asesor',
            self::GERENTE_CANAL => 'Gerente de canal',
            self::ADMIN_VENTAS => 'Admin de ventas',
            self::TI => 'TI',
        };
    }

    /** Que hace cada rol, en una linea, para la pantalla de administracion. */
    public function descripcion(): string
    {
        return match ($this) {
            self::ASESOR => 'Hace pedidos para sus clientes.',
            self::GERENTE_CANAL => 'Aprueba los pedidos de sus canales.',
            self::ADMIN_VENTAS => 'Aprueba todo y administra la plataforma.',
            self::TI => 'Administra la plataforma y sus administradores.',
        };
    }

    public function administra(): bool
    {
        return in_array($this, [self::ADMIN_VENTAS, self::TI], true);
    }

    public function apruebaPedidos(): bool
    {
        return $this !== self::ASESOR;
    }

    /** Solo TI puede crear o quitar administradores. */
    public function gestionaAdministradores(): bool
    {
        return $this === self::TI;
    }
}
