<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Roles del equipo con permisos por acción (MOD-21).
 *
 * Hasta aquí un usuario del panel era `admin` o `staff`, y lo que podía `staff`
 * estaba fijo en `routes/api.php`. Ahora `staff` es "del equipo, con un rol", y
 * el rol (`team_roles`) dice qué puede: una lista de claves de
 * `config/permisos.php`. `admin` sigue igual y no tiene rol: lo puede todo.
 *
 * Cada tienda que ya existe recibe un rol «Colaborador» con lo mismo que podía
 * `staff`, y su staff pasa a tenerlo: nadie gana ni pierde nada al migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 40);
            $table->string('description', 200)->nullable();
            // Claves de config/permisos.php. Se normalizan al guardar
            // (App\Support\Permisos::normalizar) y se vuelven a filtrar al leer.
            $table->json('permissions');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            // El nombre repetido lo rechaza antes el controlador, comparando en
            // PHP sin mayúsculas ni tildes (SQLite y MySQL no comparan igual);
            // esto es la red de debajo.
            $table->unique(['tenant_id', 'name'], 'uq_team_roles_tenant_name');
        });

        Schema::table('users', function (Blueprint $table) {
            // Null en admins y clientes. Un staff sin rol no puede nada (falla
            // en cerrado): borrar un rol con gente se impide en el controlador,
            // y `nullOnDelete` es solo para que borrar una tienda no tropiece
            // con el orden de las cascadas.
            $table->uuid('team_role_id')->nullable()->after('role');
            $table->foreign('team_role_id')->references('id')->on('team_roles')->nullOnDelete();
        });

        $permisos = json_encode(config('permisos.colaborador'));
        $ahora = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $rolId = (string) Str::uuid7();

            DB::table('team_roles')->insert([
                'id'          => $rolId,
                'tenant_id'   => $tenantId,
                'name'        => 'Colaborador',
                'description' => 'El día a día: pedidos, productos, reseñas y lista de espera.',
                'permissions' => $permisos,
                'created_at'  => $ahora,
                'updated_at'  => $ahora,
            ]);

            DB::table('users')
                ->where('tenant_id', $tenantId)
                ->where('role', 'staff')
                ->update(['team_role_id' => $rolId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['team_role_id']);
            $table->dropColumn('team_role_id');
        });

        Schema::dropIfExists('team_roles');
    }
};
