<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo llegó el pedido a su destino, y cuánto costó (MOD-1).
 *
 * **`delivery_method` es nulo, no un tercer valor fijo, para lo que ya existe:**
 * los pedidos de antes de este cambio y la venta de mostrador (el cliente está
 * delante, no hay nada que elegir). `pickup`/`delivery` solo los pone el
 * checkout público, y solo cuando la tienda tiene el envío activado.
 *
 * **`delivery_cost` es un snapshot, como `unit_price` en `order_items`.** El
 * costo de envío de la tienda puede cambiar después; lo que cobró ESTE pedido
 * no. Se calcula en el servidor a partir de `tenants.delivery_cost` en el
 * momento de crear el pedido — nunca de lo que mande el navegador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_method', 20)->nullable()->after('customer_note');
            $table->decimal('delivery_cost', 12, 2)->default(0)->after('delivery_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_method', 'delivery_cost']);
        });
    }
};
