<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $fillable = [
        'codigo_sn', 'nombre', 'direccion', 'ciudad',
        'canal_id', 'asesor_sap_id', 'porcentaje_descuento', 'activo',
    ];

    protected $casts = [
        'porcentaje_descuento' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function asesorSap(): BelongsTo
    {
        return $this->belongsTo(AsesorSap::class, 'asesor_sap_id');
    }

    public function canal(): BelongsTo
    {
        return $this->belongsTo(Canal::class, 'canal_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'cliente_id');
    }

    /** Usuarios con este cliente asignado directamente, sin pasar por cartera. */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'cliente_usuario', 'cliente_id', 'usuario_id')
            ->withPivot('asignado_por')
            ->withTimestamps();
    }

    /**
     * Filtra los clientes que este usuario tiene permitido ver.
     *
     * Esta es la regla de seguridad central y va SIEMPRE en la consulta, nunca
     * ocultando botones: un asesor que manipule la URL o la API tampoco debe
     * poder leer clientes ajenos.
     */
    public function scopeVisiblePara(Builder $query, Usuario $usuario): Builder
    {
        if ($usuario->esAdministrador()) {
            return $query;
        }

        if ($usuario->rol->value === 'GERENTE_CANAL') {
            $canales = $usuario->canales()->pluck('canales.id');

            return $query->whereIn('canal_id', $canales);
        }

        /*
         * Asesor: lo suyo son dos cosas sumadas, no una.
         *
         *   1. los clientes de las carteras de asesor SAP que tenga asignadas, y
         *   2. los clientes que le asignaron directamente.
         *
         * La segunda existe para que un asesor pueda trabajar aunque no tenga
         * cartera de SAP. Lo que no pasa nunca es que la falta de asignacion
         * abra la puerta a todo: eso era lo que hacia la app vieja.
         */
        $carteras = $usuario->idsDeAsesores();

        return $query->where(function (Builder $q) use ($usuario, $carteras) {
            if ($carteras) {
                $q->whereIn('asesor_sap_id', $carteras);
            }

            $q->orWhereHas('usuarios', fn (Builder $u) => $u->where('usuarios.id', $usuario->id));
        });
    }
}
