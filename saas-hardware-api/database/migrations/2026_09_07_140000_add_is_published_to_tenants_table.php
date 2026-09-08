<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tienda deja de ser pública hasta que su dueño verifica el correo (FUN-5).
 *
 * **Por qué una columna nueva y no `is_active`.** `is_active` ya significa una
 * cosa concreta: el interruptor de suspensión de la plataforma
 * (`PlatformController`, `AUD-*`). Meter aquí "recién registrada, sin verificar"
 * juntaría dos estados que se deciden desde sitios distintos y por motivos
 * distintos, y el choque sería real: al verificar el correo habría que poner
 * `is_active = true`, o sea que una tienda **suspendida a propósito** por la
 * plataforma volvería sola al aire en cuanto su dueño pinchara el enlace del
 * correo. Son dos preguntas ("¿la hemos suspendido?" y "¿han verificado?") y
 * necesitan dos respuestas.
 *
 * **Por qué el valor por defecto es `true`.** La regla de fondo es "una tienda
 * es pública salvo que algo diga lo contrario", y hoy lo único que dice lo
 * contrario es un registro sin verificar. Con `default(true)` las tiendas que ya
 * existen siguen al aire sin necesidad de un backfill aparte —nadie ha podido
 * verificar un correo que hasta hoy no se pedía— y quien cree una tienda por
 * código (un seeder, un test, el panel de plataforma) no tiene que acordarse de
 * publicarla. El único sitio que la crea sin publicar es
 * `AuthController::register`, que lo pone explícito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_published')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_published');
        });
    }
};
