<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('product_id');
            $table->string('visitor_id', 100)->nullable()->after('user_id');
            $table->boolean('verified_purchase')->default(false)->after('comment');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->index('visitor_id', 'idx_reviews_visitor');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'visitor_id', 'verified_purchase']);
        });
    }
};
