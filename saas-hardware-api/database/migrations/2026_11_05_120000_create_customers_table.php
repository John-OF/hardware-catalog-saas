<?php

use App\Support\Directorio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El directorio de clientes de cada tienda (MOD-24).
 *
 * Hasta ahora *Clientes* solo enseñaba las cuentas registradas, y el checkout no
 * pide cuenta: una tienda que vende por WhatsApp veía la lista casi vacía aunque
 * tuviera ventas. Aquí vive **cada persona que le compró o abrió cuenta**, se
 * llene sola con cada pedido, y lo que la tienda anota de ella (notas y
 * etiquetas).
 *
 * Una fila por persona, juntando por teléfono (sus últimos 8 dígitos, la misma
 * regla que las reseñas, `ACC-2`) o por correo; quién se junta con quién lo
 * decide `App\Support\Directorio`. **Sin índices únicos a propósito**: dos
 * cuentas pueden tener el mismo teléfono (una familia), y un único las
 * obligaría a fundirse o a fallar el pedido. Se busca la más antigua.
 *
 * `orders.customer_id` es a quién se le cuenta el pedido. `nullOnDelete` en los
 * dos sentidos: borrar una persona del directorio no puede borrar sus pedidos,
 * ni borrar una cuenta su historial en la tienda.
 *
 * Se llena con lo que ya hay (pedidos y cuentas de cliente) al migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // La cuenta del catálogo, si la tiene.
            $table->uuid('user_id')->nullable();
            $table->string('name', 200);
            $table->string('phone', 30)->nullable();
            // Los últimos 8 dígitos: "+51 987 654 321" y "987654321" son el mismo.
            $table->string('phone_key', 20)->nullable();
            $table->string('email', 200)->nullable();
            // En minúsculas: SQLite distingue mayúsculas al comparar y MySQL no.
            $table->string('email_key', 200)->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'phone_key'], 'idx_customers_phone');
            $table->index(['tenant_id', 'email_key'], 'idx_customers_email');
            $table->index(['tenant_id', 'user_id'], 'idx_customers_user');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable()->after('user_id');
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index('customer_id', 'idx_orders_customer');
        });

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            Directorio::reconstruir($tenantId);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex('idx_orders_customer');
            $table->dropColumn('customer_id');
        });

        Schema::dropIfExists('customers');
    }
};
