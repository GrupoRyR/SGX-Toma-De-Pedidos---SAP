<?php

namespace App\Services;

use App\Models\Configuracion;

/**
 * Reglas de precio, copiadas del comportamiento actual de Power Apps.
 *
 * Todo se calcula aqui, en el servidor, con numeros. Nunca a partir de texto
 * formateado: ese fue uno de los errores de la app vieja.
 *
 *   1. precio_con_descuento = precio_lista - (precio_lista * descuento_cliente / 100)
 *   2. si hay ATP:  precio_unitario = precio_con_descuento - (precio_con_descuento * ATP / 100)
 *   3. si hay precio manual: precio_unitario = precio_manual
 *   4. subtotal_linea = cantidad * precio_unitario
 *   5. iva = round(subtotal * tasa / 100); total = subtotal + iva
 *
 * ATP y precio manual son excluyentes. No tienen tope: SEGUREX decidio que el
 * control es humano, en la aprobacion y en el visto bueno.
 */
class CalculadoraPrecios
{
    /**
     * Calcula una linea completa.
     *
     * @return array{precio_lista: float, precio_con_descuento: float, precio_unitario: float, subtotal_linea: float}
     */
    public function linea(
        float $precioLista,
        float $descuentoCliente,
        float $cantidad,
        ?float $atp = null,
        ?float $precioManual = null,
    ): array {
        $conDescuento = $this->precioConDescuento($precioLista, $descuentoCliente);

        if ($precioManual !== null && $precioManual > 0) {
            $unitario = $this->aPesos($precioManual);
        } elseif ($atp !== null && $atp > 0) {
            $unitario = $this->aPesos($conDescuento - ($conDescuento * $atp / 100));
        } else {
            $unitario = $conDescuento;
        }

        return [
            'precio_lista' => $this->aPesos($precioLista),
            'precio_con_descuento' => $conDescuento,
            'precio_unitario' => $unitario,
            'subtotal_linea' => $this->aPesos($cantidad * $unitario),
        ];
    }

    public function precioConDescuento(float $precioLista, float $descuentoCliente): float
    {
        return $this->aPesos($precioLista - ($precioLista * $descuentoCliente / 100));
    }

    /**
     * Totales del pedido a partir de los subtotales de linea.
     *
     * @param  array<int, float>  $subtotales
     * @return array{subtotal: float, iva: float, total: float}
     */
    public function totales(array $subtotales): array
    {
        $subtotal = $this->aPesos(array_sum($subtotales));
        $tasa = (float) Configuracion::valor('iva_porcentaje', 19);
        $iva = $this->aPesos($subtotal * $tasa / 100);

        return [
            'subtotal' => $subtotal,
            'iva' => $iva,
            'total' => $this->aPesos($subtotal + $iva),
        ];
    }

    /** En COP no se manejan centavos: se redondea a pesos. */
    private function aPesos(float $valor): float
    {
        return round($valor, 0);
    }
}
