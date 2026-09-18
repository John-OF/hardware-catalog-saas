<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papelera para productos y pedidos (`MOD-8`).
 *
 * Sólo estas dos tablas, y no todo el panel, porque son las dos que nombra el
 * punto y donde el clic equivocado hace daño de verdad: borrar un producto se
 * llevaba además sus fotos del disco, y borrar un pedido lo sacaba del
 * historial de ventas. Categorías y páginas se quedan con el borrado
 * definitivo: las dos tienen un índice único por tienda
 * (`uq_category_tenant_name`, `uniq_tenant_page_slug`) y una fila en la
 * papelera seguiría bloqueando ese nombre sin que se vea por qué.
 *
 * `products` y `orders` no tienen ese problema: el SKU de un producto no es
 * único y el número de pedido se reparte por un contador que sólo sube, así
 * que un pedido en la papelera no le quita el número a nadie.
 *
 * El índice es sobre `(tenant_id, deleted_at)` y no sobre `deleted_at` solo:
 * toda consulta de estas dos tablas filtra primero por tienda, y la que va a
 * pedir la papelera es exactamente "los borrados de ESTA tienda".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['tenant_id', 'deleted_at'], 'idx_products_papelera');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['tenant_id', 'deleted_at'], 'idx_orders_papelera');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_papelera');
            $table->dropSoftDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_papelera');
            $table->dropSoftDeletes();
        });
    }
};
