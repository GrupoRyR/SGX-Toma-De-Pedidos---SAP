<?php

namespace App\Console\Commands;

use App\Models\Bitacora;
use App\Models\Usuario;
use Illuminate\Console\Command;

/**
 * Quien ha entrado y quien lo intento sin lograrlo.
 *
 * Sirve para dos cosas muy concretas: confirmar que el ingreso con Microsoft
 * quedo funcionando, y responder "a mi no me deja entrar" con un dato en vez de
 * una suposicion.
 *
 *   php artisan pedidos:revisar-accesos
 */
class RevisarAccesos extends Command
{
    protected $signature = 'pedidos:revisar-accesos {--dias=7 : Cuantos dias hacia atras mirar}';

    protected $description = 'Muestra los ingresos y los intentos rechazados recientes';

    public function handle(): int
    {
        $desde = now()->subDays((int) $this->option('dias'));

        $entradas = Bitacora::where('accion', 'INGRESO')
            ->where('fecha_hora', '>=', $desde)
            ->with('usuario:id,correo,rol')
            ->latest('fecha_hora')
            ->limit(25)
            ->get();

        $this->info('INGRESOS');

        if ($entradas->isEmpty()) {
            $this->line('  Nadie ha entrado todavia.');
        }

        foreach ($entradas as $registro) {
            $this->line(sprintf(
                '  %s  %-38s %s',
                $registro->fecha_hora->format('d/m H:i'),
                $registro->usuario?->correo ?? $registro->usuario_correo,
                $registro->usuario?->rol->value ?? '',
            ));
        }

        $rechazos = Bitacora::where('accion', 'INGRESO_RECHAZADO')
            ->where('fecha_hora', '>=', $desde)
            ->latest('fecha_hora')
            ->limit(25)
            ->get();

        $this->newLine();
        $this->info('INTENTOS RECHAZADOS');

        if ($rechazos->isEmpty()) {
            $this->line('  Ninguno.');
        }

        foreach ($rechazos as $registro) {
            $this->warn(sprintf(
                '  %s  %-38s %s',
                $registro->fecha_hora->format('d/m H:i'),
                $registro->detalle['correo'] ?? '?',
                $registro->detalle['motivo'] ?? '',
            ));
        }

        $this->newLine();
        $this->info('USUARIOS QUE YA ENTRARON ALGUNA VEZ');

        $conAcceso = Usuario::whereNotNull('ultimo_acceso')->orderByDesc('ultimo_acceso')->get();

        if ($conAcceso->isEmpty()) {
            $this->line('  Ninguno todavia.');
        }

        foreach ($conAcceso as $usuario) {
            $this->line(sprintf(
                '  %-38s %-14s ultimo acceso %s',
                $usuario->correo,
                $usuario->rol->value,
                $usuario->ultimo_acceso->format('d/m/Y H:i'),
            ));
        }

        return self::SUCCESS;
    }
}
