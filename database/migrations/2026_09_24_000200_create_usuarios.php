<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios, sus canales, sus carteras y sus permisos.
 *
 * No hay contrasenas: la autenticacion es contra Microsoft Entra ID y el correo
 * es la llave. Un correo que no este aqui, o que este inactivo, no entra.
 *
 * Ningun correo va escrito en el codigo de la aplicacion; todo se administra
 * desde estas tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('correo', 190)->unique();   // siempre en minusculas
            $table->string('nombre', 200);
            $table->enum('rol', ['ASESOR', 'GERENTE_CANAL', 'ADMIN_VENTAS', 'TI']);
            $table->boolean('activo')->default(true);
            $table->string('entra_object_id', 100)->nullable()->unique();
            $table->timestamp('ultimo_acceso')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['activo', 'rol']);
        });

        // Un gerente puede tener mas de un canal.
        Schema::create('usuario_canal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('canal_id')->constrained('canales')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['usuario_id', 'canal_id']);
        });

        // Que carteras de asesor SAP ve cada usuario. Hay usuarios con 2 a 4.
        Schema::create('usuario_asesor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('asesor_sap_id')->constrained('asesores_sap')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['usuario_id', 'asesor_sap_id']);
        });

        /**
         * Permisos que no son roles. Hoy existe uno solo: LIBERAR_SAP, el visto
         * bueno sin el cual ningun pedido llega a SAP.
         *
         * Soporta vigencia (desde/hasta) para poder designar un reemplazo temporal
         * que caduque solo, sin que nadie tenga que acordarse de quitarlo. Por
         * decision de SEGUREX hoy lo tienen unicamente Cesar Garzon y Marly Ossa,
         * sin fecha de fin, y no se crea ningun reemplazo.
         */
        Schema::create('usuario_permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('permiso', 50);
            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();
            $table->foreignId('otorgado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();

            $table->unique(['usuario_id', 'permiso']);
            $table->index('permiso');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_permisos');
        Schema::dropIfExists('usuario_asesor');
        Schema::dropIfExists('usuario_canal');
        Schema::dropIfExists('usuarios');
    }
};
