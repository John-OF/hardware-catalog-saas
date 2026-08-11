<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telefono opcional en los pedidos (OWN-3).
 *
 * La columna nacio NOT NULL porque el unico origen de pedidos era el catalogo
 * publico, donde el telefono es obligatorio para poder cerrar por WhatsApp. La
 * venta de mostrador no tiene ese dato: el cliente entra, paga y se va.
 *
 * El checkout publico lo sigue exigiendo en su validacion, asi que para el
 * comprador no cambia nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_phone', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sin esto el volver atras revienta contra la restriccion NOT NULL en
        // cuanto exista una sola venta de mostrador sin telefono.
        DB::table('orders')->whereNull('customer_phone')->update(['customer_phone' => '']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_phone', 30)->nullable(false)->change();
        });
    }
};
