<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Canal extends Model
{
    protected $table = 'canales';

    protected $fillable = ['nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function zonas(): HasMany
    {
        return $this->hasMany(Zona::class, 'canal_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class, 'canal_id');
    }
}
