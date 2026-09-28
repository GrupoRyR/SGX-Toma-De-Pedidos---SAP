<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Importacion extends Model
{
    protected $table = 'importaciones';

    protected $fillable = [
        'usuario_id', 'tipo', 'archivo', 'filas_leidas', 'creados',
        'actualizados', 'sin_cambios', 'errores', 'detalle_errores', 'estado',
    ];

    protected $casts = ['detalle_errores' => 'array'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
