<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seguimiento del pedido sin cuenta y comprobante de pago (MOD-26).
 *
 * `tracking_token` es la llave del enlace que recibe el comprador
 * (`/{slug}/pedido/{token}`): quien lo tiene ve su pedido y puede mandar el
 * comprobante, sin cuenta. 40 caracteres al azar, porque el enlace es lo único
 * que lo protege. Se le da uno a cada pedido que ya existe.
 *
 * El pago va **aparte del estado del pedido** (`status`: pendiente, en proceso,
 * atendido, cancelado), que es lo que mueve el stock: `payment_status` es
 * `null` (nadie dijo nada del pago), `en_revision` (el comprador mandó su
 * comprobante), `confirmado` o `rechazado` (con su motivo). Mezclarlos en una
 * sola columna habría obligado a rehacer cuándo se descuenta el stock.
 *
 * `payment_proof_path` es la ruta del comprobante en el disco **privado**
 * (`local`): lo sube un desconocido y lleva datos bancarios, así que nunca va al
 * disco público. Lo borra `comprobantes:purgar`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tracking_token', 40)->nullable()->unique()->after('number');
            $table->string('payment_status', 20)->nullable()->after('status');
            $table->string('payment_proof_path')->nullable()->after('payment_status');
            $table->timestamp('payment_proof_uploaded_at')->nullable()->after('payment_proof_path');
            $table->timestamp('payment_reviewed_at')->nullable()->after('payment_proof_uploaded_at');
            $table->string('payment_rejection_reason', 300)->nullable()->after('payment_reviewed_at');
        });

        DB::table('orders')->whereNull('tracking_token')->orderBy('id')->pluck('id')->each(
            fn ($id) => DB::table('orders')->where('id', $id)->update(['tracking_token' => Str::random(40)]),
        );
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['tracking_token']);
            $table->dropColumn([
                'tracking_token', 'payment_status', 'payment_proof_path',
                'payment_proof_uploaded_at', 'payment_reviewed_at', 'payment_rejection_reason',
            ]);
        });
    }
};
