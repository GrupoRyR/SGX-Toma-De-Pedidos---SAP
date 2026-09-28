<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existencias por bodega. Es una foto tomada del SAP on-premise, no el dato en
 * vivo: por eso la interfaz debe mostrar siempre la fecha del corte, para que
 * nadie la tome como una reserva.
 */
class Inventario extends Model
{
    protected $table = 'inventario';

    protected $fillable = ['codigo_producto', 'bodega', 'disponible', 'fecha_corte'];

    protected $casts = [
        'disponible' => 'decimal:3',
        'fecha_corte' => 'datetime',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'codigo_producto', 'codigo');
    }

    public function desactualizado(int $horas = 24): bool
    {
        return $this->fecha_corte === null || $this->fecha_corte->lt(now()->subHours($horas));
    }
}
