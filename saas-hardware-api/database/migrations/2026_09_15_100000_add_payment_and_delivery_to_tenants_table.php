<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo le pagan a la tienda y si reparte a domicilio (MOD-3, MOD-1).
 *
 * **`payment_methods` es JSON, no una tabla.** Son cuatro métodos fijos
 * (Yape, Plin, transferencia, efectivo) con dos o tres campos cada uno; una
 * tabla aparte pediría un modelo y un CRUD para cuatro filas que además nunca
 * varían de forma. El mismo criterio que ya usa `tenants.theme`.
 *
 * **No es una pasarela de pago.** El checkout sigue cerrándose por WhatsApp
 * (no hay `SAAS-3` aquí dentro): esto es solo lo que la tienda le enseña al
 * comprador para que sepa cómo pagarle, antes de coordinar el resto por chat.
 *
 * **El envío es opcional y de un solo precio.** `delivery_enabled` en `false`
 * dejaría a una tienda de sillón físico ofreciendo "delivery" sin querer;
 * `delivery_cost` nace en `0.00` para que activarlo sin poner precio no cobre
 * un monto al azar — la tienda decide el número antes de que nadie lo vea.
 * Nada de zonas ni de tarifas por distancia: eso es más adelante si hace
 * falta, no ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('payment_methods')->nullable()->after('currency');
            $table->boolean('delivery_enabled')->default(false)->after('payment_methods');
            $table->decimal('delivery_cost', 12, 2)->default(0)->after('delivery_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['payment_methods', 'delivery_enabled', 'delivery_cost']);
        });
    }
};
