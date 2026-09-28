<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El log de registros. Solo de lectura: la aplicacion no ofrece editar ni borrar
 * filas, ni siquiera a TI.
 */
class Bitacora extends Model
{
    protected $table = 'bitacora';

    protected $fillable = [
        'usuario_id', 'usuario_correo', 'fecha_hora', 'accion',
        'entidad', 'entidad_id', 'detalle', 'ip',
    ];

    protected $casts = [
        'fecha_hora' => 'datetime',
        'detalle' => 'array',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
