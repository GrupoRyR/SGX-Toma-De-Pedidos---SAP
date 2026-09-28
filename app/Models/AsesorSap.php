<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cartera de asesor tal como la nombra SAP, por ejemplo
 * "14 MONICA RIVERA AREVALO". No es un usuario: es el valor del campo asesor
 * del cliente, y es lo que decide quien ve que.
 */
class AsesorSap extends Model
{
    protected $table = 'asesores_sap';

    protected $fillable = ['codigo_texto', 'nombre', 'zona_id', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class, 'zona_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class, 'asesor_sap_id');
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'usuario_asesor', 'asesor_sap_id', 'usuario_id');
    }

    /** El numero con el que empieza el codigo: "14 MONICA..." devuelve 14. */
    public function numero(): ?int
    {
        preg_match('/^(\d+)/', $this->codigo_texto, $m);

        return isset($m[1]) ? (int) $m[1] : null;
    }
}
