<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsuarioPermiso extends Model
{
    protected $table = 'usuario_permisos';

    protected $fillable = ['usuario_id', 'permiso', 'vigente_desde', 'vigente_hasta', 'otorgado_por'];

    protected $casts = [
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function vigente(): bool
    {
        $hoy = now()->startOfDay();

        if ($this->vigente_desde && $this->vigente_desde->gt($hoy)) {
            return false;
        }

        if ($this->vigente_hasta && $this->vigente_hasta->lt($hoy)) {
            return false;
        }

        return true;
    }
}
