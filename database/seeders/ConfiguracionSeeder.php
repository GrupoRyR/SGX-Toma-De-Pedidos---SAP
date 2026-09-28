<?php

namespace Database\Seeders;

use App\Models\Configuracion;
use Illuminate\Database\Seeder;

/**
 * Valores que deben poder cambiarse sin tocar codigo.
 */
class ConfiguracionSeeder extends Seeder
{
    public function run(): void
    {
        $valores = [
            ['clave' => 'iva_porcentaje',        'valor' => '19',    'tipo' => 'numero',   'descripcion' => 'Tasa de IVA aplicada al subtotal del pedido'],
            ['clave' => 'minutos_bloqueo',       'valor' => '30',    'tipo' => 'numero',   'descripcion' => 'Minutos que dura el bloqueo de edicion de un pedido'],
            ['clave' => 'correo_remitente',      'valor' => '',      'tipo' => 'texto',    'descripcion' => 'Buzon de servicio desde el que salen los correos'],
            ['clave' => 'dias_papelera',         'valor' => '30',    'tipo' => 'numero',   'descripcion' => 'Dias que un pedido eliminado queda en la papelera'],
            ['clave' => 'horas_inventario_viejo', 'valor' => '24',    'tipo' => 'numero',   'descripcion' => 'Horas tras las cuales el inventario se muestra como desactualizado'],
            ['clave' => 'aviso_home',            'valor' => '',      'tipo' => 'texto',    'descripcion' => 'Banner de avisos para todos los usuarios'],
        ];

        foreach ($valores as $fila) {
            Configuracion::firstOrCreate(['clave' => $fila['clave']], $fila);
        }
    }
}
