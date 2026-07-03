<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Datos de contacto del cliente (el catálogo es público, sin login)
            $table->string('customer_name', 200);
            $table->string('customer_phone', 30);
            $table->text('customer_note')->nullable();

            $table->enum('status', ['pending', 'attended', 'cancelled'])->default('pending');
            $table->decimal('total', 12, 2)->default(0);

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('tenant_id', 'idx_orders_tenant');
            $table->index(['tenant_id', 'status'], 'idx_orders_status');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            // El producto puede cambiar o borrarse luego: guardamos snapshot.
            $table->uuid('product_id')->nullable();
            $table->string('product_name', 300);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 12, 2);

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->index('order_id', 'idx_order_items_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
