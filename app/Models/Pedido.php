<?php

namespace App\Models;

use App\Enums\EstadoPedido;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pedido extends Model
{
    use SoftDeletes;

    protected $table = 'pedidos';

    protected $fillable = [
        'cliente_id', 'codigo_cliente', 'nombre_cliente', 'direccion', 'ciudad',
        'direccion_2', 'ciudad_2', 'orden_compra', 'fecha_facturacion', 'observaciones',
        'estado', 'motivo_rechazo', 'subtotal', 'iva', 'total', 'creado_por',
    ];

    /**
     * Valores por defecto tambien en memoria, no solo en la base de datos: asi
     * leer $pedido->version o $pedido->estado justo despues de create() devuelve
     * el valor real y no null.
     */
    protected $attributes = [
        'estado' => 'BORRADOR',
        'version' => 1,
        'importado_sap' => false,
        'sap_intentos' => 0,
        'subtotal' => 0,
        'iva' => 0,
        'total' => 0,
    ];

    protected $casts = [
        'estado' => EstadoPedido::class,
        'fecha_facturacion' => 'date',
        'fecha_aprobacion' => 'datetime',
        'fecha_liberacion' => 'datetime',
        'fecha_importacion' => 'datetime',
        'plantillas_descargadas_en' => 'datetime',
        'bloqueado_hasta' => 'datetime',
        'importado_sap' => 'boolean',
        'subtotal' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
        'snapshot_aprobado' => 'array',
    ];

    public function lineas(): HasMany
    {
        return $this->hasMany(PedidoLinea::class, 'pedido_id')->orderBy('linea_num');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprobado_por');
    }

    public function liberador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'liberado_por');
    }

    /** Los avisos que salieron por este pedido. Hoy solo el de rechazo. */
    public function notificaciones(): HasMany
    {
        return $this->hasMany(Notificacion::class, 'pedido_id');
    }

    public function bloqueador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'bloqueado_por');
    }

    /** Misma regla de visibilidad que los clientes, aplicada en la consulta. */
    public function scopeVisiblePara(Builder $query, Usuario $usuario): Builder
    {
        if ($usuario->esAdministrador()) {
            return $query;
        }

        if ($usuario->rol->value === 'GERENTE_CANAL') {
            $canales = $usuario->canales()->pluck('canales.id');

            return $query->whereHas('cliente', fn (Builder $q) => $q->whereIn('canal_id', $canales));
        }

        // Sus pedidos, mas los de cualquier cliente que alcance a ver: por
        // cartera o por asignacion directa.
        return $query->where(function (Builder $q) use ($usuario) {
            $q->where('creado_por', $usuario->id)
                ->orWhereHas('cliente', fn (Builder $c) => $c->visiblePara($usuario));
        });
    }

    /** Los unicos que la integracion con SAP puede tocar. */
    /**
     * Lo que el robot puente puede leer.
     *
     * Solo LIBERADO: ese es el visto bueno sin el cual nada entra a SAP por su
     * cuenta. La carga manual con plantillas es otra cosa y tiene su propio
     * alcance, mas amplio, porque ahi hay una persona mirando cada pedido
     * antes de importarlo.
     */
    public function scopeListosParaSap(Builder $query): Builder
    {
        return $query->where('estado', EstadoPedido::LIBERADO)->where('importado_sap', false);
    }

    /**
     * Lo que falta por cargar a SAP a mano, con las plantillas de DTW.
     *
     * Basta con que este aprobado. El visto bueno existia para frenar al
     * robot, que carga solo; cuando la carga la hace una persona que ademas
     * revisa lo que descarga, ese segundo candado no agrega control, solo un
     * paso mas.
     */
    public function scopeParaPlantillas(Builder $query): Builder
    {
        return $query
            ->whereIn('estado', [EstadoPedido::APROBADO, EstadoPedido::LIBERADO])
            ->where('importado_sap', false);
    }

    /**
     * Si el pedido cambio despues de que sus plantillas se descargaron.
     *
     * Se compara la version y no la hora: dos cambios dentro del mismo segundo
     * tienen la misma marca de tiempo, pero la version sube con cada uno.
     */
    public function cambioDespuesDeDescargar(): bool
    {
        return $this->version_al_descargar !== null
            && (int) $this->version > (int) $this->version_al_descargar;
    }

    public function bloqueadoAhora(): bool
    {
        return $this->bloqueado_hasta !== null && $this->bloqueado_hasta->isFuture();
    }

    public function bloqueadoPorOtro(Usuario $usuario): bool
    {
        return $this->bloqueadoAhora() && $this->bloqueado_por !== $usuario->id;
    }

    /** El asesor edita mientras nadie haya aprobado. */
    public function puedeEditarlo(Usuario $usuario): bool
    {
        if (! $this->estado->editablePorAsesor()) {
            return false;
        }

        if ($this->bloqueadoPorOtro($usuario)) {
            return false;
        }

        return $usuario->esAdministrador() || $this->creado_por === $usuario->id;
    }
}
