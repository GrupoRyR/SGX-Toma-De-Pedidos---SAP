<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoLinea extends Model
{
    protected $table = 'pedido_lineas';

    protected $fillable = [
        'pedido_id', 'linea_num', 'codigo_producto', 'descripcion', 'cantidad',
        'atp_descuento_pct', 'precio_manual', 'precio_lista',
        'precio_con_descuento', 'precio_unitario', 'subtotal_linea',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'atp_descuento_pct' => 'decimal:2',
        'precio_manual' => 'decimal:2',
        'precio_lista' => 'decimal:2',
        'precio_con_descuento' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'subtotal_linea' => 'decimal:2',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }
}
