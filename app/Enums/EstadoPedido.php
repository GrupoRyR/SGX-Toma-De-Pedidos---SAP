<?php

namespace App\Enums;

/**
 * Estados del pedido.
 *
 * BORRADOR -> PENDIENTE -> APROBADO -> LIBERADO -> IMPORTADO
 *                       \-> RECHAZADO (se edita y vuelve a PENDIENTE)
 *
 * LIBERADO es el visto bueno de Cesar Garzon o Marly Ossa. Sin el, nada llega a
 * SAP: el robot puente y la exportacion manual solo leen pedidos LIBERADO.
 */
enum EstadoPedido: string
{
    case BORRADOR = 'BORRADOR';
    case PENDIENTE = 'PENDIENTE';
    case APROBADO = 'APROBADO';
    case RECHAZADO = 'RECHAZADO';
    case LIBERADO = 'LIBERADO';
    case IMPORTADO = 'IMPORTADO';

    /** El asesor puede editar y eliminar mientras nadie haya aprobado. */
    public function editablePorAsesor(): bool
    {
        return in_array($this, [self::BORRADOR, self::PENDIENTE, self::RECHAZADO], true);
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::BORRADOR => 'Borrador',
            self::PENDIENTE => 'Pendiente',
            self::APROBADO => 'Aprobado',
            self::RECHAZADO => 'Rechazado',
            self::LIBERADO => 'Liberado a SAP',
            self::IMPORTADO => 'Importado',
        };
    }

    /** Clases de color para la interfaz. Pendiente morado, aprobado verde, rechazado rojo. */
    public function color(): string
    {
        return match ($this) {
            self::BORRADOR => 'gris',
            self::PENDIENTE => 'morado',
            self::APROBADO => 'verde',
            self::RECHAZADO => 'rojo',
            self::LIBERADO => 'azul',
            self::IMPORTADO => 'verde-oscuro',
        };
    }
}
