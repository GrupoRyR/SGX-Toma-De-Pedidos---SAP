<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maestros que vienen de SAP: clientes, lista de precios e inventario.
 *
 * Clientes y precios se migran desde las listas de SharePoint actuales
 * (CLIENTES_SAP y LISTA_PRECIOS). El inventario es nuevo: lo publica el robot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_sn', 50)->unique();      // codigo SAP (CardCode)
            $table->string('nombre', 255);
            $table->string('direccion', 255)->nullable();
            $table->string('ciudad', 120)->nullable();
            $table->foreignId('canal_id')->nullable()->constrained('canales')->nullOnDelete();
            $table->foreignId('asesor_sap_id')->nullable()->constrained('asesores_sap')->nullOnDelete();
            // Descuento comercial del cliente, en porcentaje (0 a 100).
            $table->decimal('porcentaje_descuento', 5, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('asesor_sap_id');
            $table->index('canal_id');
            $table->index('nombre');
            $table->index(['activo', 'asesor_sap_id']);
        });

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();         // ItemCode de SAP
            $table->string('descripcion', 255);
            $table->string('familia', 120)->nullable();
            // Precio de lista en COP. Sin decimales en la practica, pero se deja
            // escala 2 por si SAP entrega centavos.
            $table->decimal('precio_lista', 14, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->string('imagen_url', 500)->nullable();
            $table->timestamps();

            $table->index('descripcion');
            $table->index('familia');
            $table->index(['activo', 'codigo']);
        });

        /**
         * Inventario por bodega. Es una foto, no el dato en vivo de SAP: por eso
         * se guarda la fecha del corte y la interfaz debe mostrarla siempre.
         */
        Schema::create('inventario', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_producto', 50);
            $table->string('bodega', 50)->default('GENERAL');
            $table->decimal('disponible', 14, 3)->default(0);
            $table->timestamp('fecha_corte')->nullable();
            $table->timestamps();

            $table->unique(['codigo_producto', 'bodega']);
            $table->index('codigo_producto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario');
        Schema::dropIfExists('productos');
        Schema::dropIfExists('clientes');
    }
};
