<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de correos enviados. Existe para poder responder "a mi nunca me
 * llego" con un dato y no con una suposicion.
 */
class Notificacion extends Model
{
    protected $table = 'notificaciones';

    protected $fillable = [
        'evento', 'canal', 'destinatario', 'pedido_id', 'resultado', 'error', 'enviado_en',
    ];

    protected $casts = ['enviado_en' => 'datetime'];
}
