<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los mensajes de WhatsApp de cada tienda (MOD-25).
 *
 * Solo guarda lo que la tienda CAMBIO, como `{"pedido_listo": "..."}`. Los
 * textos de fabrica viven en `config/plantillas_whatsapp.php` y no se copian
 * aqui: asi una mejora del texto de fabrica le llega a toda tienda que no lo
 * toco, y "restaurar" es quitar la clave. `null` es "todas de fabrica", que es
 * como nace toda tienda, asi que no hace falta rellenar nada de las que ya hay.
 *
 * JSON y no una tabla por el mismo motivo que `payment_methods`: diez textos
 * fijos por tienda, que nunca se consultan uno a uno desde SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('whatsapp_templates')->nullable()->after('tax_included');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('whatsapp_templates');
        });
    }
};
