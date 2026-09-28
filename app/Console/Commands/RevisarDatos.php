<?php

namespace App\Console\Commands;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Console\Command;

/**
 * Revision de salud de los datos maestros.
 *
 * Pensado para correr despues de cada importacion. Responde las preguntas que
 * importan antes de dejar entrar usuarios: los precios quedaron en el orden de
 * magnitud correcto, todos los clientes tienen canal y asesor, y no hay
 * carteras con clientes que nadie pueda ver.
 *
 *   php artisan pedidos:revisar-datos
 */
class RevisarDatos extends Command
{
    protected $signature = 'pedidos:revisar-datos';

    protected $description = 'Revisa la salud de clientes, productos y asignaciones despues de importar';

    public function handle(): int
    {
        $problemas = 0;

        $this->info('PRECIOS');
        $this->line(sprintf(
            '  %d productos | minimo %s | maximo %s | promedio %s',
            Producto::count(),
            $this->pesos((float) Producto::min('precio_lista')),
            $this->pesos((float) Producto::max('precio_lista')),
            $this->pesos((float) Producto::avg('precio_lista')),
        ));

        // Un precio de menos de mil pesos casi siempre significa que la coma de
        // miles se interpreto como decimal y los precios quedaron divididos.
        $sospechosos = Producto::where('precio_lista', '<', 1000)->count();
        if ($sospechosos > 0) {
            $this->warn("  {$sospechosos} productos por debajo de \$1.000: revisar el formato de numeros del CSV");
            $problemas++;
        }

        $sinPrecio = Producto::where('precio_lista', '<=', 0)->count();
        if ($sinPrecio > 0) {
            $this->error("  {$sinPrecio} productos sin precio");
            $problemas++;
        }

        $this->newLine();
        $this->info('CLIENTES');
        $this->line('  '.Cliente::count().' clientes');

        foreach (Canal::withCount('clientes')->orderBy('nombre')->get() as $canal) {
            $this->line(sprintf('    %-24s %4d', $canal->nombre, $canal->clientes_count));
        }

        foreach ([
            'sin canal' => Cliente::whereNull('canal_id')->count(),
            'sin asesor' => Cliente::whereNull('asesor_sap_id')->count(),
        ] as $etiqueta => $cuantos) {
            if ($cuantos > 0) {
                $this->warn("  {$cuantos} clientes {$etiqueta}");
                $problemas++;
            }
        }

        $this->newLine();
        $this->info('CARTERAS SIN USUARIO ASIGNADO');
        $huerfanas = AsesorSap::doesntHave('usuarios')->withCount('clientes')->orderByDesc('clientes_count')->get();

        if ($huerfanas->isEmpty()) {
            $this->line('  ninguna');
        }

        foreach ($huerfanas as $asesor) {
            $this->warn(sprintf('  %-42s %4d clientes que nadie ve', $asesor->codigo_texto, $asesor->clientes_count));
            $problemas++;
        }

        $this->newLine();
        $this->info('QUE VE CADA USUARIO');
        $filas = [];

        foreach (Usuario::orderBy('rol')->orderBy('correo')->get() as $usuario) {
            $filas[] = [
                $usuario->correo,
                $usuario->rol->value,
                Cliente::visiblePara($usuario)->count(),
                $usuario->asesores()->count(),
                $usuario->canales()->pluck('nombre')->implode(', ') ?: '-',
            ];
        }

        $this->table(['Correo', 'Rol', 'Clientes que ve', 'Carteras', 'Canales'], $filas);

        $this->newLine();
        if ($problemas === 0) {
            $this->info('Sin problemas detectados.');

            return self::SUCCESS;
        }

        $this->warn("{$problemas} puntos a revisar.");

        return self::SUCCESS;
    }

    private function pesos(float $valor): string
    {
        return '$ '.number_format($valor, 0, ',', '.');
    }
}
