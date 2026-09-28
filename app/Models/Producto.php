<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = ['codigo', 'descripcion', 'familia', 'precio_lista', 'activo', 'imagen_url'];

    protected $casts = [
        'precio_lista' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function inventario(): HasMany
    {
        return $this->hasMany(Inventario::class, 'codigo_producto', 'codigo');
    }

    /** Busqueda en servidor por codigo, descripcion o familia. */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        if (! $texto = trim((string) $texto)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto) {
            $q->where('codigo', 'like', $texto.'%')
                ->orWhere('descripcion', 'like', '%'.$texto.'%')
                ->orWhere('familia', 'like', '%'.$texto.'%');
        });
    }

    /**
     * Trae las existencias junto con el producto, en la misma consulta.
     *
     * El buscador muestra ocho resultados: sin esto serian ocho consultas mas,
     * y el asesor esta en la calle con mala senal.
     */
    public function scopeConExistencias(Builder $query): Builder
    {
        return $query
            ->withSum('inventario as disponible_total', 'disponible')
            ->withMax('inventario as corte_inventario', 'fecha_corte');
    }

    /** Total disponible sumando bodegas. */
    public function disponible(): float
    {
        return (float) $this->inventario()->sum('disponible');
    }
}
