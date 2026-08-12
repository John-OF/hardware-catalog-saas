<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Fija la unicidad de correo por tenant y la coherencia del login (SEC-4).
 *
 * Antes, users.email era unico global mientras el registro de clientes validaba
 * solo dentro del tenant: el mismo correo en dos tiendas reventaba con un 500.
 *
 * La contrapartida es que el login del panel, que no recibe el slug de la tienda,
 * tiene que seguir resolviendo un admin de forma univoca; de ahi que el correo de
 * admin siga siendo unico entre admins y que Auth::attempt filtre por rol.
 */
class EmailPerTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Estos casos hacen varios registros seguidos y las rutas de auth
        // llevan throttle:5,1; aqui no estamos probando el rate limit.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenantA = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);
    }

    private function registerCustomer(Tenant $tenant, string $email, string $name = 'Cliente'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/public/{$tenant->slug}/auth/register", [
            'name'                  => $name,
            'email'                 => $email,
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }

    private function makeAdmin(Tenant $tenant, string $email): User
    {
        $user = new User([
            'name'      => 'Admin',
            'email'     => $email,
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    public function test_el_mismo_correo_puede_registrarse_como_cliente_en_dos_tiendas(): void
    {
        $this->registerCustomer($this->tenantA, 'comprador@correo.com')->assertStatus(201);
        $this->registerCustomer($this->tenantB, 'comprador@correo.com')->assertStatus(201);

        $this->assertSame(2, User::where('email', 'comprador@correo.com')->count());
    }

    public function test_el_mismo_correo_dos_veces_en_la_misma_tienda_es_rechazado(): void
    {
        $this->registerCustomer($this->tenantA, 'repetido@correo.com')->assertStatus(201);

        $segundo = $this->registerCustomer($this->tenantA, 'repetido@correo.com');

        $segundo->assertStatus(422);
        $segundo->assertJsonValidationErrors('email');
        $this->assertSame(1, User::where('email', 'repetido@correo.com')->count());
    }

    public function test_un_cliente_no_puede_usar_el_correo_del_admin_de_su_propia_tienda(): void
    {
        $this->makeAdmin($this->tenantA, 'dueño@tienda-a.com');

        $response = $this->registerCustomer($this->tenantA, 'dueño@tienda-a.com');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_una_tienda_nueva_puede_usar_un_correo_que_ya_existe_como_cliente(): void
    {
        $this->registerCustomer($this->tenantA, 'persona@correo.com')->assertStatus(201);

        // La misma persona abre su propia tienda con ese correo.
        $response = $this->postJson('/api/auth/register', [
            'store_name'            => 'Mi Tienda',
            'slug'                  => 'mi-tienda',
            'whatsapp'              => '51777777777',
            'name'                  => 'Persona',
            'email'                 => 'persona@correo.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
    }

    public function test_dos_tiendas_no_pueden_compartir_el_correo_de_admin(): void
    {
        $this->makeAdmin($this->tenantA, 'dueño@correo.com');

        $response = $this->postJson('/api/auth/register', [
            'store_name'            => 'Otra Tienda',
            'slug'                  => 'otra-tienda',
            'whatsapp'              => '51777777777',
            'name'                  => 'Otro',
            'email'                 => 'dueño@correo.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    /**
     * El caso de coherencia: si un cliente se registro ANTES con el mismo correo,
     * Auth::attempt sin filtro de rol resolveria a ese cliente y el fix de SEC-1
     * rechazaria el login del admin legitimo.
     */
    public function test_el_admin_entra_al_panel_aunque_exista_un_cliente_con_su_mismo_correo(): void
    {
        $this->registerCustomer($this->tenantB, 'mismo@correo.com')->assertStatus(201);
        $this->makeAdmin($this->tenantA, 'mismo@correo.com');

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'mismo@correo.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $this->assertSame('admin', $response->json('user.role'));
        // Se comprueba por id de usuario y no por tenant_id porque desde TEC-4 la
        // respuesta ya no publica columnas internas. El id identifica igual de
        // bien a QUE usuario resolvio el login, que es lo que este caso vigila.
        $this->assertSame(
            User::where('email', 'mismo@correo.com')->where('role', 'admin')->value('id'),
            $response->json('user.id')
        );
        $this->assertSame($this->tenantA->id, User::find($response->json('user.id'))->tenant_id);
    }

    public function test_el_login_de_cliente_resuelve_el_usuario_de_su_propia_tienda(): void
    {
        $this->registerCustomer($this->tenantA, 'duplicado@correo.com', 'Cliente de A')->assertStatus(201);
        $this->registerCustomer($this->tenantB, 'duplicado@correo.com', 'Cliente de B')->assertStatus(201);

        $response = $this->postJson("/api/public/{$this->tenantB->slug}/auth/login", [
            'email'    => 'duplicado@correo.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $this->assertSame('Cliente de B', $response->json('user.name'));
        $this->assertSame($this->tenantB->id, User::find($response->json('user.id'))->tenant_id);
    }

    /**
     * Un cliente sigue sin poder entrar al panel aunque comparta correo con un admin.
     */
    public function test_el_cliente_no_entra_al_panel_con_correo_compartido(): void
    {
        $this->registerCustomer($this->tenantB, 'compartido@correo.com')->assertStatus(201);

        // Sin admin con ese correo: el login del panel no debe encontrar al cliente.
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'compartido@correo.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }
}
