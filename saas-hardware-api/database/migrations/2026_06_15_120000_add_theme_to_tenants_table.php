<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Personalización visual de la tienda (título/subtítulo del hero,
            // banner, color de acento, modo claro/oscuro). JSON para poder
            // añadir nuevos ajustes sin migrar cada vez.
            $table->json('theme')->nullable()->after('primary_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
