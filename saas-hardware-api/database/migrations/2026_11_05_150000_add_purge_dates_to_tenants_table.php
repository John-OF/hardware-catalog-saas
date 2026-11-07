<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las fechas de la purga de las pruebas abandonadas (INF-24).
 *
 * Una prueba que vence sin que nadie pague se cierra (`FUN-16`), pero sus
 * productos, fotos, pedidos y clientes se quedaban para siempre. Ahora, a los 90
 * días de cerrarse y tras dos avisos por correo, se borran. Lo decide
 * `App\Support\PruebasAbandonadas`; aquí solo se guarda dónde va cada una:
 *
 * - `purge_scheduled_at`: la fecha prometida en el primer aviso. Se guarda, y no
 *   se recalcula, para que los dos correos y la pantalla de tienda cerrada digan
 *   la misma fecha, y para que nunca se borre antes de lo que se dijo.
 * - `purge_reminded_at`: cuándo salió el segundo aviso.
 * - `data_purged_at`: cuándo se borraron sus datos.
 *
 * Las tres son del sistema y se vacían solas al reabrirse la tienda o al
 * cambiar su prueba (`Tenant::booted`): un cierre nuevo empieza la cuenta de
 * cero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('purge_scheduled_at')->nullable()->after('plan_ends_at');
            $table->timestamp('purge_reminded_at')->nullable()->after('purge_scheduled_at');
            $table->timestamp('data_purged_at')->nullable()->after('purge_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['purge_scheduled_at', 'purge_reminded_at', 'data_purged_at']);
        });
    }
};
