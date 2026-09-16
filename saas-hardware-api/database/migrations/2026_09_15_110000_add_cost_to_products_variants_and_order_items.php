<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo de compra y utilidad por venta (MOD-6).
 *
 * Tres columnas y una sola idea: hasta aqui el sistema sabia a cuanto VENDE la
 * tienda pero no a cuanto COMPRA, asi que no podia decir cuanto gana con nada.
 *
 * - `products.cost` y `product_variants.cost`: lo que le cuesta la pieza al
 *   dueño. Va tambien en la variante porque con variantes el precio vive ahi
 *   (MOD-5): un producto cuyo "16 GB" cuesta 180 y cuyo "32 GB" cuesta 340 no
 *   tiene un costo unico, y calcular el margen con el de la ficha lo inventaria.
 * - `order_items.unit_cost`: el costo COPIADO en el momento de la venta, igual
 *   que ya se copia `unit_price`. Sin el, subir el costo del proveedor mañana
 *   reescribiria la utilidad de todas las ventas de ayer.
 *
 * Las tres son `nullable` a proposito, y null no es cero: es "no lo se". Los
 * productos que ya existen no tienen costo, y las ventas anteriores a este
 * cambio tampoco; pintarlas con utilidad del 100% seria mentir. Quien lea estos
 * datos tiene que distinguir los dos casos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->nullable()->after('price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->nullable()->after('price');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('cost');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
