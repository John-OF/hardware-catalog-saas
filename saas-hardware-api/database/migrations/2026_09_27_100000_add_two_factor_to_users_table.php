<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación en dos pasos del operador de la plataforma (SEC-9).
 *
 * En `users` y no en una tabla aparte porque es un dato más de la cuenta, como
 * la contraseña, y hoy solo lo usa una cuenta. Las cuatro columnas nacen vacías:
 * nadie queda con el segundo paso puesto por migrar, lo activa cada operador
 * desde su panel.
 *
 * - `two_factor_secret`: la semilla de los códigos, CIFRADA con la APP_KEY (cast
 *   `encrypted`). Con valor pero sin `confirmed_at` es una activación a medias.
 * - `two_factor_recovery_codes`: los códigos de recuperación, cifrados y además
 *   guardados como hash: ni con la base y la clave a la vez se leen.
 * - `two_factor_confirmed_at`: desde cuándo está activa. Es lo que decide si el
 *   login pide el código.
 * - `two_factor_last_step`: el paso de tiempo (30 s) del último código aceptado.
 *   Sin él, un código visto por encima del hombro serviría otra vez durante el
 *   minuto y medio que sigue siendo válido.
 *
 * Los nombres son los de Laravel Fortify, que resuelve lo mismo; no se usa el
 * paquete porque su flujo es de sesión web y aquí el operador entra por token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
            ]);
        });
    }
};
