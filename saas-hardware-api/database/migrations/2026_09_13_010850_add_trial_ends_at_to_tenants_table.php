<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Período de prueba con cierre automático (FUN-16).
 *
 * Hasta aquí toda tienda nueva nacía en el plan `free` para siempre —el único
 * plan que el registro self-service podía dar sin pasarela de cobro (`SAAS-3`,
 * sin resolver)—. El dueño decidió dejar de ofrecer un plan gratis permanente:
 * el más barato pasa a costar de verdad, y quien se registra prueba el
 * producto por un tiempo antes de tener que elegir uno.
 *
 * Una sola columna basta: `trial_ends_at` nula significa "esta tienda no está
 * en prueba" (ni la creó el registro self-service, ni ya se le asignó un plan
 * de verdad). No hace falta guardar cuándo empezó: lo único que importa para
 * cerrar es CUÁNDO TERMINA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
