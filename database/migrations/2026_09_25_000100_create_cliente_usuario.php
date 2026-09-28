<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignacion directa de clientes a un usuario.
 *
 * Complementa a las carteras de asesor SAP, no las reemplaza. Existe porque un
 * asesor tiene que poder trabajar aunque no tenga una cartera de SAP asignada:
 * se le asignan clientes sueltos desde administracion y ve esos.
 *
 * La visibilidad final de un asesor es la union de las dos cosas:
 *   clientes de sus carteras  +  clientes asignados directamente.
 *
 * Lo que NO pasa es que la falta de asignacion abra la puerta: un usuario sin
 * carteras y sin asignaciones no ve nada. En la app vieja era al reves, y quien
 * no estuviera en la tabla de permisos veia los 641 clientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('asignado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cliente_id', 'usuario_id']);
            $table->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_usuario');
    }
};
