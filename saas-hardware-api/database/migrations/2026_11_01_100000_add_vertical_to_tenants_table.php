<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El rubro de cada tienda (MOD-19).
 *
 * Nace en `hardware` para todas las que ya existen: hasta hoy no habia otra
 * cosa, y cambiarle a alguien lo que ve sin que lo pida no es una migracion. El
 * valor por defecto coincide con `config/verticales.php` ('default').
 *
 * Decide lo que la tienda ENSEÑA (el armador, el tipo de componente, la ficha
 * tecnica), nunca lo que guarda: cambiar de vertical no borra ni convierte
 * nada, igual que bajar de plan. Lo resuelve `App\Support\Verticales`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('vertical', 30)->default('hardware')->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('vertical');
        });
    }
};
