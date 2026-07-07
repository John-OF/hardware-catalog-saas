<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('customer_name', 150);
            $table->string('customer_contact', 150); // teléfono o email donde avisar
            $table->timestamp('notified_at')->nullable(); // se marca al reponer stock
            $table->timestamps();

            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onDelete('cascade');

            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');

            // Evita que el mismo contacto se registre dos veces al mismo producto
            $table->unique(['product_id', 'customer_contact'], 'uniq_stock_notif_product_contact');
            $table->index('tenant_id', 'idx_stock_notif_tenant');
            $table->index(['product_id', 'notified_at'], 'idx_stock_notif_product_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_notifications');
    }
};
