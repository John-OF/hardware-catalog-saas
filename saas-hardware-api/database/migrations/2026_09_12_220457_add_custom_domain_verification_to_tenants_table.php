<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación del dominio propio (FUN-6).
 *
 * Hasta aquí `custom_domain` era una columna `unique` y nada más: quien la
 * escribiera primero se quedaba el dominio, sin comprobar que fuera suyo. Tres
 * columnas nuevas, las tres system-only —fuera de `$fillable`, las pone el
 * controlador con `forceFill()`, nunca una petición—:
 *
 * - `custom_domain_token`: el valor que hay que poner en un registro TXT para
 *   demostrar que el dominio es tuyo. Se genera al pedir el dominio (o al
 *   cambiarlo), no al crear la tienda: no hace falta hasta que hay algo que
 *   verificar.
 * - `custom_domain_requested_at`: cuándo se pidió ESTE dominio. Es lo que deja
 *   liberar un dominio ocupado por alguien que nunca lo verificó, pasado un
 *   plazo — sin esto, escribir el dominio de otro lo dejaba ocupado para
 *   siempre aunque quien lo escribió no fuera su dueño ni fuera a demostrarlo.
 * - `custom_domain_verified_at`: cuándo se demostró. Mientras sea `null`, el
 *   dominio NO sirve para resolver la tienda en el catálogo público ni en el
 *   panel — tenerlo escrito no es tenerlo activo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('custom_domain_token', 40)->nullable()->after('custom_domain');
            $table->timestamp('custom_domain_requested_at')->nullable()->after('custom_domain_token');
            $table->timestamp('custom_domain_verified_at')->nullable()->after('custom_domain_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['custom_domain_token', 'custom_domain_requested_at', 'custom_domain_verified_at']);
        });
    }
};
