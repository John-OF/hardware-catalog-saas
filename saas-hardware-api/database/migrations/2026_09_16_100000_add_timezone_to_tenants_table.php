<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La zona horaria de cada tienda (MOD-13).
 *
 * Todo se guarda en UTC y se seguira guardando en UTC: esta columna **no
 * cambia lo que hay en la base**, solo como se lee. Convertir los timestamps
 * guardados seria destruir el dato: un `created_at` sin zona deja de poder
 * compararse con el de otra tienda, y una tienda que se mude de pais no podria
 * volver atras.
 *
 * Salio de `MOD-9`: agrupando las ventas por dia, una venta de las 8 de la
 * noche en Peru (UTC-5) contaba como del dia siguiente. En el total del mes da
 * igual; en una grafica por dia, no.
 *
 * **Por defecto `UTC` y no `America/Lima`**, aunque el mercado al que apunta
 * esto sea ese. Una tienda que ya existe tiene que seguir viendo exactamente
 * los mismos numeros que veia ayer: elegir zona es una decision del dueño, y
 * ponersela nosotros le moveria sin avisar los reportes que ya habia mirado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // 64 caracteres: el identificador IANA mas largo que existe hoy
            // ("America/Argentina/ComodRivadavia") no llega a 33, y el doble
            // deja sitio de sobra sin que la columna pese.
            $table->string('timezone', 64)->default('UTC')->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
