<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos y sus lineas.
 *
 * Los pedidos historicos de Power Apps NO se migran: la web arranca desde cero
 * (decision de SEGUREX). Pero el numerador tampoco puede empezar en 1, porque en
 * SAP ya existen ordenes cuyo campo U_SGX_IdPedidoApp guarda numeros bajos
 * emitidos por la app vieja. Si la web repitiera esos numeros, dos pedidos
 * distintos apuntarian al mismo identificador. Por eso el AUTO_INCREMENT arranca
 * en PEDIDOS_NUMERO_INICIAL (5000 por defecto), muy por encima del ultimo
 * numero emitido por Power Apps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();

            // Datos del cliente, copiados al crear el pedido. Se guardan planos
            // ademas de la llave foranea porque el pedido debe poder leerse tal
            // como se hizo, aunque despues cambien los datos del cliente.
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('codigo_cliente', 50);
            $table->string('nombre_cliente', 255);
            $table->string('direccion', 255)->nullable();
            $table->string('ciudad', 120)->nullable();

            // Direccion alterna de despacho. Si hay direccion_2, ciudad_2 es
            // obligatoria (se valida en la aplicacion).
            $table->string('direccion_2', 255)->nullable();
            $table->string('ciudad_2', 120)->nullable();

            $table->string('orden_compra', 100)->nullable();
            $table->date('fecha_facturacion')->nullable();
            $table->text('observaciones')->nullable();

            $table->enum('estado', [
                'BORRADOR', 'PENDIENTE', 'APROBADO', 'RECHAZADO', 'LIBERADO', 'IMPORTADO',
            ])->default('BORRADOR');
            $table->text('motivo_rechazo')->nullable();

            // Totales. Siempre calculados en el servidor a partir de numeros,
            // nunca leidos de texto formateado.
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('iva', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Quien hizo que.
            $table->foreignId('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('aprobado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('fecha_aprobacion')->nullable();
            $table->foreignId('liberado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('fecha_liberacion')->nullable();

            // Resultado en SAP.
            $table->boolean('importado_sap')->default(false);
            $table->timestamp('fecha_importacion')->nullable();
            $table->string('sap_docentry', 50)->nullable();
            $table->string('sap_docnum', 50)->nullable();
            $table->text('sap_error')->nullable();
            $table->unsignedTinyInteger('sap_intentos')->default(0);

            /**
             * Bloqueo de edicion. Reemplaza el estado EDITANDO de la app vieja,
             * que dejaba pedidos atascados cuando el asesor cerraba sin guardar.
             * Se libera al guardar o cancelar, y expira solo.
             */
            $table->foreignId('bloqueado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('bloqueado_hasta')->nullable();

            /**
             * Control de concurrencia. El asesor puede editar su pedido mientras
             * no este aprobado, incluso estando PENDIENTE. Sin esta version, un
             * aprobador podria aprobar algo distinto de lo que leyo: al aprobar se
             * compara contra la version que tenia en pantalla.
             */
            $table->unsignedInteger('version')->default(1);

            // Copia congelada del pedido tal como se aprobo (propuesta 12).
            $table->json('snapshot_aprobado')->nullable();

            $table->softDeletes();   // papelera de 30 dias
            $table->timestamps();

            $table->index('cliente_id');
            $table->index('estado');
            $table->index('creado_por');
            $table->index(['estado', 'importado_sap']);
            $table->index(['estado', 'created_at']);
        });

        Schema::create('pedido_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();

            // Numeracion 0..n-1 exigida por SAP. Se renumera al guardar y al
            // enviar: en la app vieja llegaba vacia y rompia la importacion.
            $table->unsignedInteger('linea_num');

            $table->string('codigo_producto', 50);
            $table->string('descripcion', 255);
            $table->decimal('cantidad', 14, 3);

            // ATP (descuento atipico) y precio manual son excluyentes: si uno
            // tiene valor, el otro debe ser null. No tienen tope: el control es
            // humano, en la aprobacion y en el visto bueno.
            $table->decimal('atp_descuento_pct', 5, 2)->nullable();
            $table->decimal('precio_manual', 14, 2)->nullable();

            $table->decimal('precio_lista', 14, 2)->default(0);
            $table->decimal('precio_con_descuento', 14, 2)->default(0);
            $table->decimal('precio_unitario', 14, 2)->default(0);
            $table->decimal('subtotal_linea', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['pedido_id', 'linea_num']);
            $table->index('pedido_id');
            $table->index('codigo_producto');
        });

        $this->arrancarNumeradorEn((int) env('PEDIDOS_NUMERO_INICIAL', 5000));
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_lineas');
        Schema::dropIfExists('pedidos');
    }

    /**
     * Deja el numerador listo para que el primer pedido salga con $inicial.
     *
     * La sintaxis cambia por motor. En produccion siempre sera MySQL; SQLite se
     * contempla solo para las pruebas automatizadas.
     */
    private function arrancarNumeradorEn(int $inicial): void
    {
        if ($inicial <= 1) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement("ALTER TABLE pedidos AUTO_INCREMENT = {$inicial}"),
            'pgsql' => DB::statement("ALTER SEQUENCE pedidos_id_seq RESTART WITH {$inicial}"),
            'sqlite' => DB::table('sqlite_sequence')->insertOrIgnore([
                'name' => 'pedidos',
                'seq' => $inicial - 1,
            ]),
            default => null,
        };
    }
};
