<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un plan cobrado a mano tiene fecha de fin, y cada cobro queda apuntado (INF-23).
 *
 * Hasta aquí el operador cambiaba `tenants.plan` y ese plan duraba para siempre:
 * mientras Paddle (7.7b) no llegue, cobrar a mano es lo único posible, y nada
 * recordaba cuándo tocaba volver a cobrar.
 *
 * `plan_ends_at` es el «pagado hasta». Nula significa lo de antes de este cambio
 * —una tienda a la que se le puso un plan sin fecha—, no «sin pagar»: una tienda
 * que nunca pagó está en prueba (`trial_ends_at`) o cerrada.
 *
 * `tenant_payments` es el historial. Cada fila es un cobro y **no se edita ni se
 * borra**: es el registro de un ingreso. Si se apuntó mal, la fecha se corrige
 * aparte y la corrección queda en la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('plan_ends_at')->nullable()->after('trial_ends_at');
        });

        Schema::create('tenant_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `restrictOnDelete` a propósito: una tienda que pagó alguna vez no se
            // borra (`INF-24` lo pide así), y aquí lo garantiza la base y no una
            // regla que alguien tenga que recordar.
            $table->uuid('tenant_id');

            $table->string('plan', 30);
            $table->unsignedSmallInteger('months');

            // En dólares, la moneda en la que se anuncian los planes
            // (`price_usd`). Si alguien paga en otra, se apunta el equivalente.
            $table->decimal('amount_usd', 10, 2);
            $table->string('method', 30);
            $table->string('reference', 120)->nullable();
            $table->string('notes', 500)->nullable();

            // El día que llegó el dinero, que es el que cuenta para declararlo, y
            // el periodo que cubre: no tienen por qué coincidir.
            $table->date('paid_on');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            // Quién lo apuntó, con copia del correo por si esa cuenta se borra.
            $table->uuid('registered_by')->nullable();
            $table->string('registered_by_email', 150)->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('registered_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'created_at'], 'idx_payments_tenant_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payments');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('plan_ends_at');
        });
    }
};
