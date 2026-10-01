<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numero de la cartera como llave con SAP.
 *
 * En SAP el numero de la cartera es fijo y no se repite; lo que cambia cuando
 * cambia el asesor es el nombre ("14 MONICA RIVERA AREVALO" pasa a ser
 * "14 OTRA PERSONA"). Cruzar por el texto completo creaba carteras duplicadas
 * sin asesor; cruzar por el numero no.
 */
return new class extends Migration
{
    public function up(): void
    {
        $carteras = DB::table('asesores_sap')->select('id', 'codigo_texto')->orderBy('id')->get();

        $numeros = [];
        foreach ($carteras as $cartera) {
            $numeros[$cartera->id] = preg_match('/^\s*(\d+)/', $cartera->codigo_texto, $m) ? (int) $m[1] : null;
        }

        // Se revisa ANTES de alterar la tabla: en MySQL un ALTER no se deshace
        // con la transaccion, y una migracion a medias es peor que una detenida.
        $this->exigirNumerosValidos($carteras, $numeros);

        Schema::table('asesores_sap', function (Blueprint $table) {
            $table->unsignedInteger('numero')->nullable()->after('id');
        });

        foreach ($numeros as $id => $numero) {
            DB::table('asesores_sap')->where('id', $id)->update(['numero' => $numero]);
        }

        Schema::table('asesores_sap', function (Blueprint $table) {
            $table->unique('numero');
        });
    }

    public function down(): void
    {
        Schema::table('asesores_sap', function (Blueprint $table) {
            $table->dropUnique(['numero']);
        });

        Schema::table('asesores_sap', function (Blueprint $table) {
            $table->dropColumn('numero');
        });
    }

    /** @param  array<int, int|null>  $numeros  id => numero */
    private function exigirNumerosValidos($carteras, array $numeros): void
    {
        $textos = $carteras->pluck('codigo_texto', 'id');
        $problemas = [];

        foreach ($numeros as $id => $numero) {
            if ($numero === null) {
                $problemas[] = "sin numero: \"{$textos[$id]}\"";
            }
        }

        $porNumero = [];
        foreach ($numeros as $id => $numero) {
            if ($numero !== null) {
                $porNumero[$numero][] = $textos[$id];
            }
        }

        foreach ($porNumero as $numero => $repetidas) {
            if (count($repetidas) > 1) {
                $problemas[] = "numero {$numero} repetido: \"".implode('", "', $repetidas).'"';
            }
        }

        if ($problemas) {
            throw new RuntimeException(
                "No se puede agregar el numero a las carteras. Corrige estas antes de migrar:\n  - "
                .implode("\n  - ", $problemas)
            );
        }
    }
};
