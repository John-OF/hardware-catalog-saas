<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupones de descuento (`MOD-4`).
 *
 * De los cuatro mecanismos que junta el punto —código promocional, "lleva 3
 * paga 2", precio por mayor y campañas con caducidad— aquí entran el primero y
 * el cuarto, que son el mismo: un código con vigencia. Los otros dos son formas
 * de **fijar precio**, y para eso ya está `sale_price` y las variantes; el
 * código es lo único que sirve para **traer** gente, que es para lo que el dueño
 * lo pega en una historia de Instagram.
 *
 * **El código es único por tienda, no global.** Dos tiendas pueden tener las dos
 * su "VERANO25" sin enterarse la una de la otra, que es lo mínimo que espera
 * quien cree que su catálogo es suyo.
 *
 * **`used_count` vive aquí y no se cuenta con un `COUNT` sobre `orders`.** El
 * tope hay que comprobarlo y subirlo dentro de la misma transacción que crea el
 * pedido, con la fila bloqueada: con un `COUNT`, dos compradores simultáneos leen
 * 49 los dos y entran los dos en una campaña de 50. Y además un pedido borrado
 * (`MOD-8`) dejaría de contar y devolvería usos de una campaña ya cerrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Se guarda siempre en mayúsculas (ver `Coupon::setCodeAttribute`):
            // el comprador escribe "verano25" en el móvil, con la primera letra
            // en mayúscula o sin ella, y espera que funcione igual.
            $table->string('code', 40);

            // Dos tipos y no más: un porcentaje o un monto. "Envío gratis" es un
            // tercer mecanismo —toca `delivery_cost`, no el subtotal— y se deja
            // fuera a propósito.
            $table->enum('type', ['percent', 'fixed']);
            $table->decimal('value', 12, 2);

            // Los tres límites, todos opcionales. `null` es "sin límite" en los
            // tres, igual que en la matriz de planes.
            $table->decimal('min_purchase', 12, 2)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Apagarlo sin borrarlo: una campaña que se repite cada año se
            // enciende y se apaga, y borrarla perdería su contador de usos.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'code'], 'uq_coupons_tenant_code');
            // El listado del panel filtra por tienda y ordena por fecha.
            $table->index(['tenant_id', 'created_at'], 'idx_coupons_tenant');
        });

        Schema::table('orders', function (Blueprint $table) {
            // El subtotal de los PRODUCTOS antes de descontar nada. Se guarda
            // aunque parezca derivable de las líneas porque hace falta en SQL
            // para repartir el descuento al medir el margen, y sumarlo ahí con
            // una ventana por pedido sería una consulta mucho más cara. De paso,
            // el panel y el PDF pintan el desglose sin recalcular.
            $table->decimal('items_subtotal', 12, 2)->nullable()->after('total');

            // El cupón usado. `coupon_id` a null si el cupón se borra después;
            // `coupon_code` es el snapshot, como `product_name` en las líneas:
            // el historial tiene que seguir diciendo qué código se usó.
            $table->uuid('coupon_id')->nullable()->after('items_subtotal');
            $table->string('coupon_code', 40)->nullable()->after('coupon_id');
            $table->decimal('discount_amount', 12, 2)->nullable()->after('coupon_code');

            $table->foreign('coupon_id')->references('id')->on('coupons')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn(['items_subtotal', 'coupon_id', 'coupon_code', 'discount_amount']);
        });

        Schema::dropIfExists('coupons');
    }
};
