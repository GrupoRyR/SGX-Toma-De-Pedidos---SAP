<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Configuracion extends Model
{
    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor', 'tipo', 'descripcion'];

    public static function valor(string $clave, mixed $porDefecto = null): mixed
    {
        return Cache::remember("config:{$clave}", 300, function () use ($clave, $porDefecto) {
            $fila = static::where('clave', $clave)->first();

            if (! $fila) {
                return $porDefecto;
            }

            return match ($fila->tipo) {
                'numero' => is_numeric($fila->valor) ? $fila->valor + 0 : $porDefecto,
                'booleano' => filter_var($fila->valor, FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode((string) $fila->valor, true),
                default => $fila->valor,
            };
        });
    }

    protected static function booted(): void
    {
        static::saved(fn (self $c) => Cache::forget("config:{$c->clave}"));
        static::deleted(fn (self $c) => Cache::forget("config:{$c->clave}"));
    }
}
