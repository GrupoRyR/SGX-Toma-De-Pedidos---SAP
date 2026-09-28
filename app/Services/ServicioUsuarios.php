<?php

namespace App\Services;

use App\Enums\Rol;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Altas y cambios de usuarios.
 *
 * Todo lo que reparte acceso pasa por aqui, por una razon: cada movimiento
 * queda en el log de registros con quien lo hizo. En la app vieja los permisos
 * se cambiaban a mano en la lista de SharePoint y no quedaba rastro de nada.
 */
class ServicioUsuarios
{
    public function __construct(private Bitacorero $bitacora) {}

    public function crear(string $correo, string $nombre, Rol $rol, Usuario $actor): Usuario
    {
        $correo = strtolower(trim($correo));
        $dominio = (string) config('auth.dominio_permitido');

        if ($dominio !== '' && ! str_ends_with($correo, '@'.$dominio)) {
            throw new RuntimeException("El correo tiene que ser del dominio {$dominio}.");
        }

        if (Usuario::where('correo', $correo)->exists()) {
            throw new RuntimeException('Ya hay un usuario con ese correo.');
        }

        $usuario = Usuario::create([
            'correo' => $correo,
            'nombre' => trim($nombre) ?: $correo,
            'rol' => $rol,
            'activo' => true,
        ]);

        $this->bitacora->registrar('CREAR_USUARIO', 'usuario', $usuario->id, [
            'correo' => $usuario->correo,
            'rol' => $rol->value,
        ]);

        return $usuario;
    }

    public function cambiarRol(Usuario $objetivo, Rol $nuevo, Usuario $actor): Usuario
    {
        $anterior = $objetivo->rol;

        if ($anterior === $nuevo) {
            return $objetivo;
        }

        $objetivo->update(['rol' => $nuevo]);

        $this->bitacora->registrarCambio('CAMBIAR_ROL', 'usuario', $objetivo->id,
            ['rol' => $anterior->value], ['rol' => $nuevo->value]);

        return $objetivo->refresh();
    }

    /**
     * Activa o desactiva.
     *
     * Desactivar es la forma de sacar a alguien: no se borran usuarios, porque
     * sus pedidos tienen que seguir diciendo quien los hizo.
     */
    public function cambiarActivo(Usuario $objetivo, bool $activo, Usuario $actor): Usuario
    {
        if (! $activo && $objetivo->id === $actor->id) {
            throw new RuntimeException('No puedes desactivarte a ti mismo.');
        }

        $objetivo->update(['activo' => $activo]);

        $this->bitacora->registrar($activo ? 'ACTIVAR_USUARIO' : 'DESACTIVAR_USUARIO',
            'usuario', $objetivo->id, ['correo' => $objetivo->correo]);

        return $objetivo->refresh();
    }

    /**
     * Otorga un permiso, con vigencia opcional.
     *
     * La vigencia es para el reemplazo temporal que pidio SEGUREX: se designa a
     * alguien mientras Cesar o Marly no esten, y el permiso caduca solo sin que
     * nadie tenga que acordarse de quitarlo.
     */
    public function otorgarPermiso(Usuario $objetivo, string $permiso, Usuario $actor, ?string $hasta = null): UsuarioPermiso
    {
        if (! $objetivo->activo) {
            throw new RuntimeException('Primero activa al usuario, despues dale el permiso.');
        }

        $vigenteHasta = $hasta ?: null;

        if ($vigenteHasta && strtotime($vigenteHasta) < strtotime(now()->toDateString())) {
            throw new RuntimeException('La fecha de vencimiento ya paso.');
        }

        $fila = UsuarioPermiso::create([
            'usuario_id' => $objetivo->id,
            'permiso' => $permiso,
            'vigente_desde' => now()->toDateString(),
            'vigente_hasta' => $vigenteHasta,
            'otorgado_por' => $actor->id,
        ]);

        $this->bitacora->registrar('OTORGAR_PERMISO', 'usuario', $objetivo->id, [
            'permiso' => $permiso,
            'correo' => $objetivo->correo,
            'vigente_hasta' => $vigenteHasta,
        ]);

        return $fila;
    }

    public function revocarPermiso(Usuario $objetivo, string $permiso, Usuario $actor): void
    {
        $cuantos = UsuarioPermiso::where('usuario_id', $objetivo->id)
            ->where('permiso', $permiso)
            ->delete();

        if ($cuantos === 0) {
            return;
        }

        $this->bitacora->registrar('REVOCAR_PERMISO', 'usuario', $objetivo->id, [
            'permiso' => $permiso,
            'correo' => $objetivo->correo,
        ]);
    }

    /** Carteras de asesor SAP que ve esta persona. */
    public function sincronizarCarteras(Usuario $objetivo, array $ids, Usuario $actor): void
    {
        $antes = $objetivo->idsDeAsesores();

        DB::transaction(fn () => $objetivo->asesores()->sync($ids));

        $despues = $objetivo->refresh()->idsDeAsesores();

        if ($antes === $despues) {
            return;
        }

        $this->bitacora->registrarCambio('CAMBIAR_CARTERAS', 'usuario', $objetivo->id,
            ['carteras' => $antes], ['carteras' => $despues]);
    }

    /** Canales que ve un gerente. */
    public function sincronizarCanales(Usuario $objetivo, array $ids, Usuario $actor): void
    {
        $antes = $objetivo->canales()->pluck('canales.id')->all();

        DB::transaction(fn () => $objetivo->canales()->sync($ids));

        $despues = $objetivo->refresh()->canales()->pluck('canales.id')->all();

        if ($antes === $despues) {
            return;
        }

        $this->bitacora->registrarCambio('CAMBIAR_CANALES', 'usuario', $objetivo->id,
            ['canales' => $antes], ['canales' => $despues]);
    }
}
