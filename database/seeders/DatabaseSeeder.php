<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orden de siembra. Los asesores SAP NO se siembran a mano: se crean solos al
 * importar CLIENTES_SAP, a partir de los valores reales del campo asesor. Por eso
 * AsignacionesSeeder se corre DESPUES de la importacion de clientes.
 *
 *   php artisan migrate --seed
 *   php artisan pedidos:importar-clientes storage/importaciones/CLIENTES_SAP.csv
 *   php artisan pedidos:importar-precios  storage/importaciones/LISTA_PRECIOS.csv
 *   php artisan db:seed --class=AsignacionesSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CanalesSeeder::class,
            UsuariosSeeder::class,
            ConfiguracionSeeder::class,
        ]);
    }
}
