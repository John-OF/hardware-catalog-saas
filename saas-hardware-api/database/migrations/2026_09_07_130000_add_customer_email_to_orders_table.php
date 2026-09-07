<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correo del comprador en el pedido (FUN-2).
 *
 * El pedido solo guardaba nombre, teléfono y nota, así que el único aviso que
 * salía del sistema iba al dueño: el comprador no recibía confirmación de nada y
 * todo el seguimiento dependía de que el dueño escribiera a mano por WhatsApp,
 * que es justo el trabajo manual que la fase 1 vino a quitar.
 *
 * **Opcional a propósito.** Obligarlo garantizaría que la confirmación llegue
 * siempre, pero añade fricción en el único paso donde de verdad se pierden
 * ventas. Quien lo deja recibe confirmación y avisos; quien no, se queda como
 * hasta ahora. Por eso todo el código que envía comprueba antes que haya correo,
 * en vez de darlo por supuesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email', 200)->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_email');
        });
    }
};
