<?php

namespace App\Models;

use App\Enums\Rol;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Un usuario de la web. La contrasena no existe aqui: la identidad la da
 * Microsoft Entra ID y el correo es la llave.
 */
class Usuario extends Authenticatable
{
    use HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['correo', 'nombre', 'rol', 'activo', 'entra_object_id', 'ultimo_acceso'];

    protected $casts = [
        'rol' => Rol::class,
        'activo' => 'boolean',
        'ultimo_acceso' => 'datetime',
    ];

    /** El correo siempre se guarda en minusculas: es la llave de identidad. */
    public function setCorreoAttribute(string $valor): void
    {
        $this->attributes['correo'] = strtolower(trim($valor));
    }

    public function canales(): BelongsToMany
    {
        return $this->belongsToMany(Canal::class, 'usuario_canal', 'usuario_id', 'canal_id')->withTimestamps();
    }

    public function asesores(): BelongsToMany
    {
        return $this->belongsToMany(AsesorSap::class, 'usuario_asesor', 'usuario_id', 'asesor_sap_id')->withTimestamps();
    }

    /** Clientes asignados directamente, sin pasar por una cartera de SAP. */
    public function clientesAsignados(): BelongsToMany
    {
        return $this->belongsToMany(Cliente::class, 'cliente_usuario', 'usuario_id', 'cliente_id')
            ->withPivot('asignado_por')
            ->withTimestamps();
    }

    public function permisos(): HasMany
    {
        return $this->hasMany(UsuarioPermiso::class, 'usuario_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'creado_por');
    }

    /**
     * Permiso vigente hoy. La vigencia permite designar un reemplazo temporal
     * que caduque solo, sin que nadie tenga que acordarse de quitarlo.
     */
    public function tienePermiso(string $permiso): bool
    {
        if (! $this->activo) {
            return false;
        }

        return $this->permisos()
            ->where('permiso', $permiso)
            ->where(fn ($q) => $q->whereNull('vigente_desde')->orWhereDate('vigente_desde', '<=', now()))
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', now()))
            ->exists();
    }

    /** El visto bueno sin el cual ningun pedido llega a SAP. */
    public function puedeLiberarASap(): bool
    {
        return $this->tienePermiso('LIBERAR_SAP');
    }

    public function esAdministrador(): bool
    {
        return $this->rol->administra();
    }

    public function puedeAprobar(): bool
    {
        return $this->activo && $this->rol->apruebaPedidos();
    }

    /**
     * Ids de las carteras de asesor SAP que ve este usuario.
     *
     * Un asesor puede no tener ninguna y trabajar igual: en ese caso sus
     * clientes son los que le asignaron directamente (ver clientesAsignados).
     *
     * Lo que no ocurre es lo de la app vieja, donde un usuario sin cartera
     * terminaba viendo los 641 clientes.
     */
    public function idsDeAsesores(): array
    {
        return $this->asesores()->pluck('asesores_sap.id')->all();
    }
}
