<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impuesto por tienda y su foto en cada pedido (`MOD-2`).
 *
 * **Apagado por defecto, y eso es lo importante de esta migración.** Una tienda
 * que ya existe no puede ver cambiar ni un número de sus pedidos ni de sus
 * reportes por desplegar esto: `tax_enabled` entra en `false` y los pedidos que
 * ya hay se quedan con `tax_rate` a `null`, que significa "esta venta se hizo
 * sin impuesto", no "con impuesto del 0%".
 *
 * **En `orders` es un snapshot, igual que `delivery_cost` (MOD-1) y que
 * `unit_cost` (MOD-6).** Si el dueño cambia el porcentaje o deja de cobrarlo, lo
 * vendido ayer tiene que seguir desglosándose como se vendió: el PDF de una
 * cotización de hace seis meses no puede reescribirse solo porque hoy el IGV
 * esté en otro sitio.
 *
 * `tax_included` decide qué significa el porcentaje, y se guarda por pedido
 * porque cambia la aritmética entera:
 *
 * - `true`  — los precios ya lo llevan dentro. `total` no cambia y el impuesto
 *             se saca hacia atrás: `total * tasa / (100 + tasa)`.
 * - `false` — se suma al final. `total = base + impuesto`.
 *
 * En los dos casos vale el mismo invariante, que es lo que deja que el PDF y el
 * panel no tengan dos caminos: **`total` es siempre lo que paga el cliente, y
 * `tax_amount` es cuánto de eso es impuesto.**
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('tax_enabled')->default(false)->after('delivery_cost');
            // El nombre lo pone el dueño ("IGV", "IVA", "ITBMS"): la plataforma
            // no sabe en qué país está y llamarlo "IGV" a todo el mundo sería
            // escribir Perú en la interfaz de un ecuatoriano.
            $table->string('tax_name', 20)->default('IGV')->after('tax_enabled');
            // 5,2: hasta 999,99%. De sobra, y con dos decimales porque hay tasas
            // que no son enteras.
            $table->decimal('tax_rate', 5, 2)->default(18.00)->after('tax_name');
            $table->boolean('tax_included')->default(true)->after('tax_rate');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Nullable a propósito: `null` es "se vendió sin impuesto" y es
            // distinto de `0.00`, que sería "se vendió con impuesto del 0%".
            // Mismo criterio que `unit_cost` en MOD-6.
            $table->string('tax_name', 20)->nullable()->after('delivery_cost');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_name');
            $table->boolean('tax_included')->nullable()->after('tax_rate');
            $table->decimal('tax_amount', 12, 2)->nullable()->after('tax_included');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['tax_enabled', 'tax_name', 'tax_rate', 'tax_included']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tax_name', 'tax_rate', 'tax_included', 'tax_amount']);
        });
    }
};
