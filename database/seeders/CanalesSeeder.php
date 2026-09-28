<?php

namespace Database\Seeders;

use App\Models\Canal;
use Illuminate\Database\Seeder;

/**
 * Canales conocidos hoy. Si SEGUREX tiene mas, se agregan desde el modulo de
 * administracion: esto es solo el punto de partida.
 */
class CanalesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Distribucion', 'Cons. Residencial'] as $nombre) {
            Canal::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }
    }
}
