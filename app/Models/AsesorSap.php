<?php

namespace App\Models;

use App\Support\FormatoMaestros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cartera de asesor tal como la nombra SAP, por ejemplo
 * "14 MONICA RIVERA AREVALO". No es un usuario: es el valor del campo asesor
 * del cliente, y es lo que decide quien ve que.
 *
 * La llave con SAP es el numero (14), no el texto: el numero es fijo y el
 * nombre cambia cuando cambia el asesor. Clientes y asesores se ligan por id,
 * asi que renombrar no mueve nada.
 */
class AsesorSap extends Model
{
    protected $table = 'asesores_sap';

    protected $fillable = ['numero', 'codigo_texto', 'nombre', 'zona_id', 'activo'];

    protected $casts = ['numero' => 'integer', 'activo' => 'boolean'];

    protected static function booted(): void
    {
        // Quien crea la cartera solo con el texto de SAP (la carga, las pruebas)
        // no tiene que acordarse de llenar el numero aparte.
        static::creating(function (AsesorSap $cartera) {
            $cartera->numero ??= self::numeroDelTexto((string) $cartera->codigo_texto);
        });
    }

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

    /** El numero con el que empieza el texto de SAP: "14 MONICA..." devuelve 14. */
    public static function numeroDelTexto(string $texto): ?int
    {
        return preg_match('/^\s*(\d+)/', $texto, $m) ? (int) $m[1] : null;
    }

    /**
     * La cartera que corresponde a un texto de SAP, buscada por su numero.
     *
     * Si el numero ya existe se usa esa cartera tal como esta, aunque el texto
     * traiga otro nombre: una carga nunca renombra (un error de digitacion en
     * el archivo no debe cambiarle el nombre a nadie). Si no existe, se crea
     * con el texto tal cual. Lo usan la carga web y el comando de consola.
     *
     * @throws \RuntimeException si el texto no empieza con el numero
     */
    public static function resolverDesdeTexto(string $texto): self
    {
        $texto = trim($texto);
        $numero = self::numeroDelTexto($texto);

        if ($numero === null) {
            throw new \RuntimeException("La cartera \"{$texto}\" no empieza con su numero (por ejemplo \"14 MONICA RIVERA AREVALO\").");
        }

        return self::firstOrCreate(
            ['numero' => $numero],
            ['codigo_texto' => $texto, 'nombre' => FormatoMaestros::nombreDelAsesor($texto), 'activo' => true],
        );
    }
}
