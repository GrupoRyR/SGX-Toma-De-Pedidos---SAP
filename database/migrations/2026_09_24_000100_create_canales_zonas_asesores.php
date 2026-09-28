<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canales, zonas y asesores SAP.
 *
 * El "asesor SAP" es el vendedor tal como viene en el campo asesor del cliente
 * en SAP (por ejemplo "14 MONICA RIVERA AREVALO"). No es un usuario de la web:
 * un usuario puede atender varias carteras, y una cartera puede quedar vacante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('zonas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->foreignId('canal_id')->constrained('canales')->restrictOnDelete();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['canal_id', 'nombre']);
        });

        Schema::create('asesores_sap', function (Blueprint $table) {
            $table->id();
            // Texto exacto que trae SAP en el campo asesor del cliente. Es la llave
            // real de cruce con CLIENTES_SAP, por eso es unico y no se normaliza.
            $table->string('codigo_texto', 200)->unique();
            $table->string('nombre', 200)->nullable();
            $table->foreignId('zona_id')->nullable()->constrained('zonas')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asesores_sap');
        Schema::dropIfExists('zonas');
        Schema::dropIfExists('canales');
    }
};
