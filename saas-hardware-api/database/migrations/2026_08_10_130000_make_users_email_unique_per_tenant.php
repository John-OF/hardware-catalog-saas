<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.email pasa de unico global a unico por tenant (SEC-4).
 *
 * El registro de clientes ya validaba la unicidad solo dentro del tenant, pero la
 * base tenia un unico global: el primer comprador que se registraba con el mismo
 * correo en dos tiendas distintas provocaba un 500.
 *
 * El correo del ADMIN sigue siendo unico entre admins, y eso se valida en
 * AuthController::register, no aqui: el login del panel no recibe el slug de la
 * tienda, asi que necesita poder resolver un admin por email de forma univoca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
        });
    }

    /**
     * Ojo: revertir falla si para entonces existen correos repetidos entre
     * tiendas, que es justamente lo que esta migracion viene a permitir.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_tenant_email_unique');
            $table->unique('email');
        });
    }
};
