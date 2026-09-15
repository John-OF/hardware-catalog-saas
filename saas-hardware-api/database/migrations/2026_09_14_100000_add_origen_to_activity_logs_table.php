<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde sale cada línea de la bitácora (INF-3).
 *
 * La tabla nació con `INF-2` para lo que hace el operador de la plataforma y se
 * diseñó genérica para que el panel de tienda escribiera en ella. Al hacerlo, las
 * dos bitácoras tienen que poder leerse por separado: la del operador no puede
 * llenarse con cada precio que cambia un vendedor, y la de una tienda no debe
 * enseñar lo que el operador anotó para sí (su correo, su IP, sus notas).
 *
 * Una columna y no deducirlo del nombre de la acción ni del rol del actor: el
 * cierre automático de pruebas vencidas no tiene actor, y un prefijo de acción es
 * una convención que basta con olvidar una vez. Todo lo que ya había es del
 * operador, por eso el valor por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('origen', 20)->default('plataforma')->after('id');

            $table->index(['tenant_id', 'origen', 'created_at'], 'idx_activity_tenant_origen_created');
            $table->index(['origen', 'created_at'], 'idx_activity_origen_created');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('idx_activity_tenant_origen_created');
            $table->dropIndex('idx_activity_origen_created');
            $table->dropColumn('origen');
        });
    }
};
