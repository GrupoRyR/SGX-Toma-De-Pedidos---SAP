<?php

namespace App\Services;

use App\Models\AsesorSap;
use App\Models\Usuario;
use RuntimeException;

/**
 * Altas y cambios de carteras SAP desde Administracion.
 *
 * El numero es la llave con SAP: se pone al crear y no se cambia nunca.
 * Renombrar solo cambia el texto; clientes y asesores estan ligados por id,
 * asi que nadie pierde de vista a sus clientes.
 *
 * No hay borrado: una cartera que ya no va se desactiva, y desactivarla no
 * cambia quien ve que. Solo la saca de las listas para asignar.
 */
class ServicioCarteras
{
    private const CAMPOS = ['numero', 'codigo_texto', 'nombre', 'activo'];

    public function __construct(private Bitacorero $bitacora) {}

    public function crear(int $numero, string $nombre, Usuario $actor): AsesorSap
    {
        if ($numero <= 0) {
            throw new RuntimeException('El numero de la cartera tiene que ser mayor que cero.');
        }

        $existente = AsesorSap::where('numero', $numero)->first();
        if ($existente) {
            throw new RuntimeException("Ya existe la cartera {$numero}: \"{$existente->codigo_texto}\".");
        }

        $nombre = $this->nombre($nombre);
        $texto = $this->exigirTextoLibre("{$numero} {$nombre}", null);

        $cartera = AsesorSap::create([
            'numero' => $numero,
            'codigo_texto' => $texto,
            'nombre' => $nombre,
            'activo' => true,
        ]);

        $this->bitacora->registrar('CREAR_CARTERA', 'asesor_sap', $cartera->id, [
            'numero' => $numero,
            'codigo_texto' => $texto,
        ]);

        return $cartera;
    }

    public function renombrar(AsesorSap $cartera, string $nombre, Usuario $actor): AsesorSap
    {
        $antes = $cartera->only(self::CAMPOS);

        $nombre = $this->nombre($nombre);
        $texto = $this->exigirTextoLibre("{$cartera->numero} {$nombre}", $cartera->id);

        $cartera->fill(['codigo_texto' => $texto, 'nombre' => $nombre]);

        if (! $cartera->isDirty()) {
            return $cartera;
        }

        $cartera->save();

        $this->bitacora->registrarCambio('RENOMBRAR_CARTERA', 'asesor_sap', $cartera->id,
            $antes, $cartera->only(self::CAMPOS));

        return $cartera;
    }

    public function cambiarActivo(AsesorSap $cartera, bool $activo, Usuario $actor): AsesorSap
    {
        if ($cartera->activo === $activo) {
            return $cartera;
        }

        $cartera->update(['activo' => $activo]);

        $this->bitacora->registrar($activo ? 'ACTIVAR_CARTERA' : 'DESACTIVAR_CARTERA', 'asesor_sap', $cartera->id, [
            'codigo_texto' => $cartera->codigo_texto,
        ]);

        return $cartera;
    }

    /**
     * En mayusculas y con los espacios colapsados, como lo escribe SAP: asi
     * "ana  maria" y "ANA MARIA" no quedan como dos nombres distintos.
     */
    private function nombre(string $nombre): string
    {
        $limpio = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $nombre)), 'UTF-8');

        if ($limpio === '') {
            throw new RuntimeException('El nombre de la cartera es obligatorio.');
        }

        // El texto completo ("14 " + nombre) tiene que caber en codigo_texto.
        if (mb_strlen($limpio) > 180) {
            throw new RuntimeException('El nombre de la cartera no puede pasar de 180 caracteres.');
        }

        return $limpio;
    }

    /** codigo_texto es unico: dos carteras no pueden llamarse igual. */
    private function exigirTextoLibre(string $texto, ?int $excepto): string
    {
        $repetida = AsesorSap::where('codigo_texto', $texto)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->exists();

        if ($repetida) {
            throw new RuntimeException("Ya existe una cartera llamada \"{$texto}\".");
        }

        return $texto;
    }
}
