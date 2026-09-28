<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens del robot puente.
 *
 * El robot corre dentro de SEGUREX, detras de la VPN, y sale hacia la web. No
 * es una persona: no tiene correo ni pasa por Microsoft. Se identifica con un
 * token que solo se ve una vez, cuando se crea.
 *
 * Se guarda el hash, nunca el token. Si alguien entra a la base de datos, no
 * se lleva una llave utilizable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens_servicio', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);                 // "Robot puente SAP"
            $table->string('hash', 64)->unique();          // sha256 del token
            $table->string('prefijo', 12);                 // para reconocerlo en pantalla
            // Lista blanca de IP de salida de SEGUREX, separadas por coma. Si
            // esta vacia, no se filtra por IP.
            $table->string('ips', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('ultimo_uso')->nullable();
            $table->string('ultima_ip', 45)->nullable();
            $table->timestamp('revocado_en')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();

            $table->index(['activo', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens_servicio');
    }
};
