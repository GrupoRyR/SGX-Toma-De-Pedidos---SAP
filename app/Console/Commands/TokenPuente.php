<?php

namespace App\Console\Commands;

use App\Models\TokenServicio;
use Illuminate\Console\Command;

/**
 * Crea, lista y revoca los tokens del robot puente.
 *
 * Se hace por consola y no desde la web a proposito: es una llave de servidor a
 * servidor, se genera una vez al montar el robot y se pega en su configuracion.
 * El valor se muestra una sola vez y no queda guardado en ninguna parte.
 */
class TokenPuente extends Command
{
    protected $signature = 'pedidos:token-puente
                            {accion=listar : listar, crear o revocar}
                            {--nombre= : Nombre del token al crearlo}
                            {--ips= : IP de salida autorizadas, separadas por coma}
                            {--id= : Id del token a revocar}';

    protected $description = 'Gestiona los tokens del robot puente SAP';

    public function handle(): int
    {
        return match ($this->argument('accion')) {
            'crear' => $this->crear(),
            'revocar' => $this->revocar(),
            default => $this->listar(),
        };
    }

    private function listar(): int
    {
        $tokens = TokenServicio::orderByDesc('id')->get();

        if ($tokens->isEmpty()) {
            $this->line('No hay tokens. Crea uno con:');
            $this->line('  php artisan pedidos:token-puente crear --nombre="Robot puente SAP"');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Nombre', 'Prefijo', 'IP autorizadas', 'Estado', 'Ultimo uso'],
            $tokens->map(fn (TokenServicio $t) => [
                $t->id,
                $t->nombre,
                $t->prefijo.'...',
                $t->ips ?: 'cualquiera',
                $t->revocado_en ? 'revocado' : ($t->activo ? 'activo' : 'inactivo'),
                $t->ultimo_uso?->diffForHumans() ?? 'nunca',
            ])->all()
        );

        $sinFiltro = $tokens->filter(fn ($t) => ! $t->revocado_en && $t->activo && ! $t->ips);

        if ($sinFiltro->isNotEmpty()) {
            $this->newLine();
            $this->warn('Hay tokens activos sin lista de IP. Conviene limitarlos a la IP de salida de SEGUREX.');
        }

        return self::SUCCESS;
    }

    private function crear(): int
    {
        $nombre = $this->option('nombre') ?: 'Robot puente SAP';

        [$token, $claro] = TokenServicio::generar($nombre, null, $this->option('ips') ?: null);

        $this->newLine();
        $this->info('Token creado. Se muestra una sola vez:');
        $this->newLine();
        $this->line('  '.$claro);
        $this->newLine();
        $this->line('El robot lo manda asi en cada llamada:');
        $this->line('  Authorization: Bearer '.$claro);
        $this->newLine();

        if (! $token->ips) {
            $this->warn('Sin lista de IP: cualquiera con el token puede usarlo.');
            $this->line('Para limitarlo:  --ips="181.x.x.x"');
        }

        return self::SUCCESS;
    }

    private function revocar(): int
    {
        $id = (int) $this->option('id');
        $token = TokenServicio::find($id);

        if (! $token) {
            $this->error("No existe el token {$id}.");

            return self::FAILURE;
        }

        $token->forceFill(['activo' => false, 'revocado_en' => now()])->save();

        $this->info("Token {$token->id} ({$token->nombre}) revocado. El robot dejara de entrar de inmediato.");

        return self::SUCCESS;
    }
}
