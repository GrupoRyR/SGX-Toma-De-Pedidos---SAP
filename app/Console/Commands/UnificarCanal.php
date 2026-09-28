<?php

namespace App\Console\Commands;

use App\Models\Bitacora;
use App\Models\Canal;
use App\Models\Cliente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mueve todos los clientes de un canal a otro y borra el canal de origen.
 *
 * Existe porque el maestro de SAP trae canales mal escritos ("Cons. Resicencial"
 * por "Cons. Residencial"). Un canal fantasma no es cosmetico: los clientes que
 * caen ahi quedan fuera del alcance del gerente de canal y nadie los aprueba.
 *
 *   php artisan pedidos:unificar-canal "Cons. Resicencial" "Cons. Residencial"
 */
class UnificarCanal extends Command
{
    protected $signature = 'pedidos:unificar-canal
                            {origen : Canal mal escrito, se elimina}
                            {destino : Canal correcto, recibe los clientes}
                            {--simular : Muestra lo que haria sin escribir nada}';

    protected $description = 'Une dos canales moviendo sus clientes y eliminando el sobrante';

    public function handle(): int
    {
        $origen = Canal::where('nombre', $this->argument('origen'))->first();
        $destino = Canal::where('nombre', $this->argument('destino'))->first();

        if (! $origen) {
            $this->error("No existe el canal de origen: {$this->argument('origen')}");

            return self::FAILURE;
        }

        if (! $destino) {
            $this->error("No existe el canal de destino: {$this->argument('destino')}");

            return self::FAILURE;
        }

        if ($origen->id === $destino->id) {
            $this->error('El origen y el destino son el mismo canal.');

            return self::FAILURE;
        }

        $afectados = Cliente::where('canal_id', $origen->id)->get(['id', 'codigo_sn', 'nombre']);

        $this->line("Mover {$afectados->count()} clientes de \"{$origen->nombre}\" a \"{$destino->nombre}\":");
        foreach ($afectados as $cliente) {
            $this->line("  - {$cliente->codigo_sn}  {$cliente->nombre}");
        }

        if ($this->option('simular')) {
            $this->warn('SIMULACION: no se escribio nada.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($origen, $destino, $afectados) {
            Cliente::where('canal_id', $origen->id)->update(['canal_id' => $destino->id]);

            // Las zonas del canal viejo tambien tienen que mudarse, si no
            // quedarian apuntando a un canal que ya no existe.
            $origen->zonas()->update(['canal_id' => $destino->id]);
            $origen->delete();

            Bitacora::create([
                'accion' => 'UNIFICAR_CANAL',
                'entidad' => 'canal',
                'entidad_id' => $destino->id,
                'detalle' => [
                    'origen' => $origen->nombre,
                    'destino' => $destino->nombre,
                    'clientes' => $afectados->pluck('codigo_sn')->all(),
                ],
            ]);
        });

        $this->info("Listo. \"{$origen->nombre}\" eliminado y sus clientes quedaron en \"{$destino->nombre}\".");

        return self::SUCCESS;
    }
}
