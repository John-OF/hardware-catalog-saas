<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Si la tienda lleva la cuenta de las unidades de un producto (MOD-22).
 *
 * Nace en `true` para todo lo que ya existe: hasta hoy todo producto tenia
 * stock, y apagarlo sin que nadie lo pida haria que lo agotado se pudiera
 * pedir. Con `false` el producto se pide siempre (un servicio, un encargo, algo
 * digital), no se enseñan las unidades, no hay lista de espera y atender un
 * pedido no descuenta nada.
 *
 * Va en la ficha y no en cada variante: lo que decide es la naturaleza del
 * producto, y las variantes son opciones del mismo. El porque, en
 * `auditoria.md` (MOD-22).
 *
 * El numero de `stock` se queda como estaba al apagarlo: no se usa, pero si se
 * vuelve a encender, el dueño encuentra el que tenia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_stock')->default(true)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('track_stock');
        });
    }
};
