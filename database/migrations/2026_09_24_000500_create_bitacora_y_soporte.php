<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora (el log de registros), configuracion, envios de correo e
 * importaciones masivas.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Log de registros. Es solo de lectura: la aplicacion no ofrece editar ni
         * borrar filas, ni siquiera a TI. Retencion minima de 2 anos.
         */
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('usuario_correo', 190)->nullable();  // por si el usuario se borra
            $table->timestamp('fecha_hora')->useCurrent();
            $table->string('accion', 60);          // CREAR, EDITAR, ENVIAR, APROBAR, ...
            $table->string('entidad', 60);         // pedido, usuario, cliente, ...
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('detalle')->nullable();   // valor anterior y valor nuevo
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['entidad', 'entidad_id']);
            $table->index('usuario_id');
            $table->index('accion');
            $table->index('fecha_hora');
        });

        // Configuracion editable sin tocar codigo: IVA, minutos de bloqueo,
        // remitente de correos, textos de avisos.
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 80)->unique();
            $table->text('valor')->nullable();
            $table->string('tipo', 20)->default('texto');   // texto, numero, booleano, json
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();
        });

        // Registro de correos enviados, para poder responder "a mi nunca me llego".
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->string('evento', 60);
            $table->string('canal', 20)->default('correo');
            $table->string('destinatario', 190);
            $table->unsignedBigInteger('pedido_id')->nullable();
            $table->string('resultado', 20)->default('PENDIENTE');  // ENVIADO, ERROR
            $table->text('error')->nullable();
            $table->timestamp('enviado_en')->nullable();
            $table->timestamps();

            $table->index(['evento', 'resultado']);
            $table->index('pedido_id');
        });

        // Cargas masivas de clientes y precios: quien, cuando, que archivo y que
        // cambio. Permite explicar por que un precio aparecio distinto.
        Schema::create('importaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('tipo', 40);            // CLIENTES, PRECIOS, INVENTARIO
            $table->string('archivo', 255)->nullable();
            $table->unsignedInteger('filas_leidas')->default(0);
            $table->unsignedInteger('creados')->default(0);
            $table->unsignedInteger('actualizados')->default(0);
            $table->unsignedInteger('sin_cambios')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->json('detalle_errores')->nullable();
            $table->string('estado', 20)->default('EN_PROCESO');
            $table->timestamps();

            $table->index(['tipo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones');
        Schema::dropIfExists('notificaciones');
        Schema::dropIfExists('configuraciones');
        Schema::dropIfExists('bitacora');
    }
};
