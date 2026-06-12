<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('category_id')->nullable();
            $table->string('name', 300);
            $table->string('brand', 100)->nullable();
            $table->decimal('price', 12, 2);
            $table->integer('stock')->default(0);
            $table->text('description')->nullable();
            $table->json('specs')->nullable();
            $table->text('image_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onDelete('cascade');

            $table->foreign('category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('set null');

            $table->index('tenant_id', 'idx_products_tenant');
            $table->index(['tenant_id', 'category_id'], 'idx_products_category');
            $table->index(['tenant_id', 'stock'], 'idx_products_stock');
            $table->index(['tenant_id', 'is_active'], 'idx_products_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
