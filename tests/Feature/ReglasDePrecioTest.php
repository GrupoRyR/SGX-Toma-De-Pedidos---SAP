<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Services\CalculadoraPrecios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reglas de precio copiadas del comportamiento actual de Power Apps.
 *
 * Son el corazon del pedido: si estas cuentas fallan, se factura mal.
 */
class ReglasDePrecioTest extends TestCase
{
    use RefreshDatabase;

    private CalculadoraPrecios $calculadora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculadora = new CalculadoraPrecios;
        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);
    }

    public function test_aplica_el_descuento_del_cliente(): void
    {
        $linea = $this->calculadora->linea(
            precioLista: 100_000,
            descuentoCliente: 20,
            cantidad: 1,
        );

        $this->assertSame(80_000.0, $linea['precio_con_descuento']);
        $this->assertSame(80_000.0, $linea['precio_unitario']);
    }

    public function test_el_atp_se_aplica_sobre_el_precio_ya_descontado(): void
    {
        // 100.000 - 20% = 80.000; 80.000 - 10% = 72.000.
        // No es 100.000 - 30%: los descuentos se encadenan, no se suman.
        $linea = $this->calculadora->linea(
            precioLista: 100_000,
            descuentoCliente: 20,
            cantidad: 1,
            atp: 10,
        );

        $this->assertSame(72_000.0, $linea['precio_unitario']);
        $this->assertNotSame(70_000.0, $linea['precio_unitario']);
    }

    public function test_el_precio_manual_reemplaza_todo_el_calculo(): void
    {
        $linea = $this->calculadora->linea(
            precioLista: 100_000,
            descuentoCliente: 20,
            cantidad: 3,
            precioManual: 55_000,
        );

        $this->assertSame(55_000.0, $linea['precio_unitario']);
        $this->assertSame(165_000.0, $linea['subtotal_linea']);
    }

    public function test_el_precio_manual_gana_sobre_el_atp(): void
    {
        // En la interfaz son excluyentes; si llegaran los dos, manda el manual.
        $linea = $this->calculadora->linea(
            precioLista: 100_000,
            descuentoCliente: 0,
            cantidad: 1,
            atp: 50,
            precioManual: 90_000,
        );

        $this->assertSame(90_000.0, $linea['precio_unitario']);
    }

    public function test_sin_descuento_ni_atp_el_precio_es_el_de_lista(): void
    {
        $linea = $this->calculadora->linea(
            precioLista: 123_456,
            descuentoCliente: 0,
            cantidad: 2,
        );

        $this->assertSame(123_456.0, $linea['precio_unitario']);
        $this->assertSame(246_912.0, $linea['subtotal_linea']);
    }

    public function test_no_hay_tope_de_descuento(): void
    {
        // SEGUREX decidio que el control es humano, no una validacion.
        $linea = $this->calculadora->linea(
            precioLista: 100_000,
            descuentoCliente: 0,
            cantidad: 1,
            atp: 90,
        );

        $this->assertSame(10_000.0, $linea['precio_unitario']);
    }

    public function test_calcula_iva_del_19_por_ciento_y_total(): void
    {
        $totales = $this->calculadora->totales([100_000, 50_000]);

        $this->assertSame(150_000.0, $totales['subtotal']);
        $this->assertSame(28_500.0, $totales['iva']);
        $this->assertSame(178_500.0, $totales['total']);
    }

    public function test_la_tasa_de_iva_es_configurable(): void
    {
        Configuracion::where('clave', 'iva_porcentaje')->update(['valor' => '5']);
        Configuracion::flushEventListeners();
        cache()->forget('config:iva_porcentaje');

        $totales = $this->calculadora->totales([100_000]);

        $this->assertSame(5_000.0, $totales['iva']);
        $this->assertSame(105_000.0, $totales['total']);
    }

    public function test_redondea_a_pesos_sin_centavos(): void
    {
        // 33.333 - 33,33% = 22.221,11... -> en COP no hay centavos.
        $linea = $this->calculadora->linea(
            precioLista: 33_333,
            descuentoCliente: 33.33,
            cantidad: 1,
        );

        $this->assertSame(round($linea['precio_unitario']), $linea['precio_unitario']);
    }
}
