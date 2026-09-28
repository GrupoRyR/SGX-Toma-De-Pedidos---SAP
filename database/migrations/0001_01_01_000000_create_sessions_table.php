<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones en base de datos.
 *
 * Reemplaza la migracion por defecto de Laravel, que creaba `users` y
 * `password_reset_tokens`. Aqui no hay contrasenas ni tabla `users`: la
 * identidad la da Microsoft Entra ID y los usuarios viven en `usuarios`.
 *
 * Las sesiones van en base de datos porque el hosting compartido no tiene Redis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
