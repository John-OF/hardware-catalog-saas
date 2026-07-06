<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_favorites', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('product_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            
            $table->unique(['user_id', 'product_id'], 'uq_user_favorites');
            $table->index('user_id', 'idx_favorites_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorites');
    }
};
