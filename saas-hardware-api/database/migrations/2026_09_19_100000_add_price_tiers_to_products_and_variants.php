<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precio por cantidad — "precio por mayor" (MOD-15).
 *
 * Dos columnas y una sola idea: hasta aqui una pieza costaba lo mismo comprando
 * una que comprando veinte, asi que la tienda que vende al por mayor tenia que
 * cerrar ese trato por WhatsApp y a mano, fuera del catalogo y fuera de los
 * reportes.
 *
 * **Un tramo es un PRECIO, no un descuento**, y de esa decision sale todo lo
 * demas. Lo que se guarda en la linea del pedido (`order_items.unit_price`) es
 * el precio al que de verdad se vendio cada unidad, igual que ya pasaba con
 * `sale_price`; por eso el margen, la utilidad del pedido, los reportes y el
 * reparto proporcional del cupon (MOD-4) siguen funcionando **sin tocar una sola
 * consulta**. Modelarlo como descuento habria obligado a repartirlo entre las
 * unidades de la linea y a que cada una de esas cuentas aprendiera a hacerlo.
 *
 * JSON y no una tabla aparte, por el mismo motivo que `products.specs`: el
 * catalogo publico devuelve el modelo entero en media docena de sitios —listado,
 * ficha, relacionados, buscador, armador— y varios van por cache. Una columna
 * viaja sola a todos ellos; una tabla habria pedido un `with()` en cada uno, y
 * el que se olvidara enseñaria precios sin tramos sin dar ningun error. Nunca
 * hace falta consultar POR tramo, que es lo unico que el JSON no deja hacer.
 *
 * Forma: `[{"min": 10, "price": 90.00}, ...]`, ordenada y validada por
 * `App\Support\PreciosPorCantidad`. `null` es "sin tramos", que es lo normal.
 *
 * Va tambien en la variante porque con variantes el precio vive ahi (MOD-5): un
 * producto cuyo "16 GB" cuesta 180 y cuyo "32 GB" cuesta 340 no puede tener un
 * solo precio por mayor, por lo mismo que no tiene un solo costo (MOD-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('price_tiers')->nullable()->after('sale_price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->json('price_tiers')->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('price_tiers');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_tiers');
        });
    }
};
