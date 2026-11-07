<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la cotización en PDF necesitaba para poder mandarse (FUN-26).
 *
 * `quote_validity_days`: cuántos días vale una cotización desde que se emite;
 * se imprime como fecha. `null` es «sin fecha», que es lo que había: ninguna
 * tienda pasa a prometer un plazo que no eligió.
 *
 * `quote_terms`: las condiciones de la tienda, en texto libre (anticipo, plazo
 * de entrega, garantía), impresas debajo del total.
 *
 * La identificación y la dirección fiscal no van aquí: ya existían en el tema
 * (`footer_tax_id`, `footer_address`, PERS-7) y se escriben en Configuración;
 * la cotización las lee de ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('quote_validity_days')->nullable()->after('tax_included');
            $table->text('quote_terms')->nullable()->after('quote_validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['quote_validity_days', 'quote_terms']);
        });
    }
};
