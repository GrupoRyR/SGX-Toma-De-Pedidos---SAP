<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zona extends Model
{
    protected $table = 'zonas';

    protected $fillable = ['nombre', 'canal_id', 'activa'];

    protected $casts = ['activa' => 'boolean'];

    public function canal(): BelongsTo
    {
        return $this->belongsTo(Canal::class, 'canal_id');
    }

    public function asesores(): HasMany
    {
        return $this->hasMany(AsesorSap::class, 'zona_id');
    }
}
