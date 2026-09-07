<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Número de pedido correlativo por tienda (FUN-3).
 *
 * Hasta ahora un pedido solo se identificaba por su UUID, y las tres pantallas
 * que lo enseñaban se inventaban un trozo distinto: el panel y el carrito
 * cortaban los últimos 8 caracteres y la cuenta del cliente los 8 primeros, así
 * que el mismo pedido tenía dos "números" según dónde lo mirases. Nada de eso se
 * puede dictar por teléfono, que es como se cierran las ventas aquí.
 *
 * **El correlativo es por tienda, no global.** Un autoincremento global sería más
 * simple, pero el número es visible para el comprador: con uno global, cualquier
 * cliente puede pedir dos veces con días de diferencia y deducir cuántos pedidos
 * mueve TODA la plataforma. Es la misma fuga que `AUD-9` tapó en las respuestas
 * públicas del tenant, pero por la puerta de al lado.
 *
 * **El contador vive en `tenants` y no se deduce con `MAX(number) + 1`.** Con MAX,
 * borrar el último pedido reasigna su número al siguiente —dos pedidos distintos
 * con el mismo número en el historial de WhatsApp del dueño—, y dos pedidos
 * simultáneos leen el mismo máximo. El contador nunca retrocede aunque se borren
 * pedidos, que es lo que se espera de un correlativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Arranca en 1: el dueño quiere saber que ese es su pedido nº 47, no
            // un número inflado. Si se prefiere que la tienda no parezca recién
            // abierta ante su primer cliente, se cambia este default.
            $table->unsignedInteger('next_order_number')->default(1)->after('plan');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Nullable en el esquema porque la columna se añade a una tabla que
            // puede tener filas; el relleno de abajo no deja ninguna sin número y
            // el modelo lo asigna siempre al crear.
            $table->unsignedInteger('number')->nullable()->after('tenant_id');

            // Es lo que convierte al correlativo en una garantía y no en una
            // costumbre: si dos peticiones simultáneas se llevaran el mismo
            // número, la segunda falla en vez de duplicarlo en silencio.
            $table->unique(['tenant_id', 'number'], 'uniq_orders_tenant_number');
        });

        // Relleno de lo que ya existe: se numera por orden de creación, tienda a
        // tienda, para que el historial quede en el mismo orden en que ocurrió.
        // Fila a fila y no con una sentencia de ventana porque esto tiene que
        // correr igual en MySQL y en el SQLite de los tests, y son pocos pedidos.
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            $siguiente = 1;

            $pedidos = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            foreach ($pedidos as $orderId) {
                DB::table('orders')->where('id', $orderId)->update(['number' => $siguiente]);
                $siguiente++;
            }

            DB::table('tenants')->where('id', $tenantId)->update([
                'next_order_number' => $siguiente,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('uniq_orders_tenant_number');
            $table->dropColumn('number');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('next_order_number');
        });
    }
};
