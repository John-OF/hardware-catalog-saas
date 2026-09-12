<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de acciones (INF-2, y la mitad de INF-3).
 *
 * Nace por el panel de plataforma: suspender una tienda, cambiarle el plan o
 * entrar en ella como soporte son cosas que no pueden pasar sin dejar rastro.
 * Pero la tabla se diseña genérica a propósito —`tenant_id` nullable y un actor
 * cualquiera— para que la bitácora del panel de tienda (`INF-3`) sea la misma
 * tabla y no una segunda copia con otro nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable y `nullOnDelete`: la tienda afectada puede borrarse y el
            // rastro de lo que se le hizo tiene que sobrevivirla. Para eso está
            // el snapshot de `context`, que no depende de ninguna fila viva.
            $table->uuid('tenant_id')->nullable();
            $table->uuid('actor_id')->nullable();

            // Copia del actor en el momento del hecho. Una bitácora que dice
            // "usuario borrado" no sirve para auditar nada.
            $table->string('actor_email', 150)->nullable();
            $table->string('actor_role', 30)->nullable();

            // Verbo estable para filtrar (`tenant.suspendida`), y la frase ya
            // redactada para leer. Se guarda escrita y no se compone al pintar:
            // si mañana cambia el texto, lo que pasó aquel día no cambia.
            $table->string('action', 60);
            $table->string('description', 255);

            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            // El listado por defecto es "lo último, de todas las tiendas".
            $table->index('created_at', 'idx_activity_created');
            $table->index(['tenant_id', 'created_at'], 'idx_activity_tenant_created');
            $table->index(['action', 'created_at'], 'idx_activity_action_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
