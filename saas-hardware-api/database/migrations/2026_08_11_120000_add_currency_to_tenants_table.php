<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moneda de la tienda (OWN-1).
 *
 * Hasta ahora el simbolo "$" estaba incrustado en el frontend, asi que una
 * tienda que vende en soles o pesos no podia usar el producto.
 *
 * El default es USD porque es lo que las tiendas ya existentes venian mostrando:
 * asi la migracion no cambia lo que ve nadie hasta que el dueño elija otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
