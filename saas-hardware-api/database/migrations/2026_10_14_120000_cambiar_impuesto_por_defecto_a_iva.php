<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El impuesto de fábrica deja de ser el de Perú (IGV 18%) y pasa a ser el de
 * Ecuador (IVA 15%), que es donde está el dueño y de donde saldrán las primeras
 * tiendas. Sigue apagado por defecto (MOD-2): esto sólo cambia lo que ve quien lo
 * enciende sin tocar nada más.
 *
 * Tampoco toca lo que alguien eligió: sólo las tiendas que lo tienen apagado y
 * con los valores de fábrica de antes. Una tienda que escribió «IGV» y 18 a
 * propósito lo tiene encendido, o al menos distinto, y se queda como está. Los
 * pedidos no se tocan nunca: llevan su propia copia del impuesto con que se
 * vendieron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('tax_name', 20)->default('IVA')->change();
            $table->decimal('tax_rate', 5, 2)->default(15.00)->change();
        });

        DB::table('tenants')
            ->where('tax_enabled', false)
            ->where('tax_name', 'IGV')
            ->where('tax_rate', 18)
            ->update(['tax_name' => 'IVA', 'tax_rate' => 15.00]);
    }

    public function down(): void
    {
        // Sólo los valores de fábrica; lo que se reescribió arriba no se puede
        // distinguir de lo que ya era «IVA 15» por elección, así que no se vuelve.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('tax_name', 20)->default('IGV')->change();
            $table->decimal('tax_rate', 5, 2)->default(18.00)->change();
        });
    }
};
