<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Llave del robot puente.
 *
 * Nunca se guarda el token: solo su hash. Quien lo pierda no lo recupera, se
 * genera otro. Es a proposito.
 */
class TokenServicio extends Model
{
    protected $table = 'tokens_servicio';

    protected $fillable = ['nombre', 'hash', 'prefijo', 'ips', 'activo', 'creado_por'];

    protected $casts = [
        'activo' => 'boolean',
        'ultimo_uso' => 'datetime',
        'revocado_en' => 'datetime',
    ];

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }

    /**
     * Genera un token y devuelve [modelo, tokenEnClaro].
     *
     * El token en claro se muestra una sola vez y no vuelve a existir en
     * ninguna parte.
     */
    public static function generar(string $nombre, ?int $creadoPor = null, ?string $ips = null): array
    {
        $claro = 'sgx_'.Str::random(48);

        $token = static::create([
            'nombre' => $nombre,
            'hash' => hash('sha256', $claro),
            'prefijo' => substr($claro, 0, 12),
            'ips' => $ips,
            'activo' => true,
            'creado_por' => $creadoPor,
        ]);

        return [$token, $claro];
    }

    /** Busca un token vigente por su valor en claro. */
    public static function porValor(string $claro): ?self
    {
        return static::where('hash', hash('sha256', $claro))
            ->where('activo', true)
            ->whereNull('revocado_en')
            ->first();
    }

    /** Si esta IP puede usar este token. Sin lista blanca, cualquiera. */
    public function aceptaIp(?string $ip): bool
    {
        $lista = array_filter(array_map('trim', explode(',', (string) $this->ips)));

        if ($lista === []) {
            return true;
        }

        return in_array($ip, $lista, true);
    }

    public function registrarUso(?string $ip): void
    {
        $this->forceFill(['ultimo_uso' => now(), 'ultima_ip' => $ip])->save();
    }
}
