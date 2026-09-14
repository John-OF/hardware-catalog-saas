<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variantes de producto (MOD-5).
 *
 * Opcionales: un producto sin filas aqui sigue funcionando exactamente como
 * antes, con su precio y su stock en `products`. Uno con variantes guarda precio
 * y stock en cada una, y `products` pasa a ser el resumen (precio de la mas
 * barata, stock total) que mantiene `Product::sincronizarResumenDeVariantes()`.
 * Por eso esta migracion no toca ningun dato existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');

            // Lista de pares {name, value} y no un objeto {name: value}: MySQL
            // reordena las claves de un objeto JSON al guardarlo, y el orden en
            // que el dueño escribe "Capacidad / Color" es el que se enseña.
            $table->json('options');

            $table->string('sku', 100)->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->integer('low_stock_threshold')->default(5);
            $table->text('image_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->index(['product_id', 'sort_order'], 'idx_variants_product');
            $table->index('tenant_id', 'idx_variants_tenant');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Mismo trato que `product_id`: si la variante se borra, la linea se
            // queda con el nombre guardado y el id a null.
            $table->uuid('variant_id')->nullable()->after('product_id');
            $table->string('variant_name', 300)->nullable()->after('product_name');

            $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('set null');
        });

        Schema::table('stock_notifications', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
            $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('cascade');

            // El unico era (producto, contacto): con variantes, el mismo cliente
            // puede esperar el 16 GB y el 32 GB del mismo producto. La
            // idempotencia la sigue garantizando el `firstOrCreate` del
            // controlador, que ahora incluye la variante; un unico con
            // `variant_id` nullable no la garantizaria, porque dos NULL no chocan.
            $table->dropUnique('uniq_stock_notif_product_contact');
        });
    }

    public function down(): void
    {
        Schema::table('stock_notifications', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
            $table->unique(['product_id', 'customer_contact'], 'uniq_stock_notif_product_contact');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn(['variant_id', 'variant_name']);
        });

        Schema::dropIfExists('product_variants');
    }
};
