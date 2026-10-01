<?php

namespace Database\Seeders;

use App\Models\AsesorSap;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

/**
 * Que carteras de asesor SAP ve cada usuario.
 *
 * Se corre DESPUES de importar CLIENTES_SAP, porque los asesores se crean a
 * partir de los valores reales del campo asesor de ese archivo. Aqui solo se
 * enlaza por el numero con el que empieza el codigo ("14 MONICA RIVERA AREVALO"
 * se enlaza con el numero 14).
 *
 * Si un numero no aparece en los datos importados, el seeder lo reporta en vez
 * de fallar: casi siempre significa que ese asesor ya no tiene clientes, o que
 * el texto en SAP cambio.
 *
 * Importante: en la app vieja, un usuario sin cartera asignada veia TODOS los
 * clientes. Aqui no. Sin asignacion, no ve ninguno.
 */
class AsignacionesSeeder extends Seeder
{
    /** @var array<string, array<int, int>> correo => numeros de asesor SAP */
    private array $asignaciones = [
        'jhon.castro@segurex.com' => [14, 15],
        'distribucion.boyaca@segurex.com' => [14],
        'paola.vargas@segurex.com' => [15],
        'construccionantioquia@segurex.com' => [69, 52, 61, 66],
        'carolina.ramirez@segurex.com' => [16],
        'maria.uribe@segurex.com' => [17],
        'lina.chacon@segurex.com' => [18],
        'jhony.tordecilla@segurex.com' => [21],
        'maria.pena@segurex.com' => [23],
        'harold.salazar@segurex.com' => [32, 33, 34],
        'cristina.rodriguez@segurex.com' => [36],
        'jenny.buitrago@segurex.com' => [41, 44],
        'adriana.russi@segurex.com' => [42, 82],
        'distribucion.norte@segurex.com' => [43],
        'luis.daza@segurex.com' => [51],
        'distribucion.occidente1@segurex.com' => [],   // sin cartera: no vera clientes
    ];

    public function run(): void
    {
        $sinAsesor = [];
        $sinUsuario = [];
        $enlazadas = 0;

        foreach ($this->asignaciones as $correo => $numeros) {
            $usuario = Usuario::where('correo', $correo)->first();

            if (! $usuario) {
                $sinUsuario[] = $correo;

                continue;
            }

            $ids = [];
            foreach ($numeros as $numero) {
                $asesores = AsesorSap::where('numero', $numero)->pluck('id');

                if ($asesores->isEmpty()) {
                    $sinAsesor[] = "{$correo} -> {$numero}";

                    continue;
                }

                $ids = array_merge($ids, $asesores->all());
            }

            $usuario->asesores()->sync($ids);
            $enlazadas += count($ids);
        }

        $this->command->info("Carteras enlazadas: {$enlazadas}");

        if ($sinUsuario) {
            $this->command->warn('Usuarios que no existen todavia: '.implode(', ', $sinUsuario));
        }

        if ($sinAsesor) {
            $this->command->warn('Numeros de asesor sin coincidencia en los clientes importados:');
            foreach ($sinAsesor as $linea) {
                $this->command->warn('  - '.$linea);
            }
            $this->command->warn('Revisar el texto exacto del campo asesor en CLIENTES_SAP.');
        }
    }
}
