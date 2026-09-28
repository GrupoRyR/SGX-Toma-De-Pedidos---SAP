<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuando se descargaron por ultima vez las plantillas de este pedido.
 *
 * Existe para una sola cosa: si alguien ajusta el pedido despues de que las
 * plantillas salieron, quien va a importar tiene que enterarse de que el
 * archivo que tiene en la mano quedo viejo. Sin esta marca, la unica forma de
 * notarlo seria comparar a ojo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->timestamp('plantillas_descargadas_en')->nullable()->after('fecha_importacion');
            // La version que tenia el pedido cuando se descargo. Se compara
            // contra la actual y no contra `updated_at`: dos cambios dentro del
            // mismo segundo tienen la misma hora, pero nunca la misma version.
            $table->unsignedInteger('version_al_descargar')->nullable()->after('plantillas_descargadas_en');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('plantillas_descargadas_en');
        });
    }
};
