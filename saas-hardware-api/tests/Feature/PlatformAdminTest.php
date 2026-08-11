<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Panel de plataforma del operador del SaaS (SAAS-4 / 7.6).
 *
 * Antes no habia forma de gestionar morosos o abusos salvo editar la base a
 * mano. Lo que fija este test:
 *
 * 1. Solo un super-admin activo entra: ni el dueño de una tienda ni un cliente,
 *    ni con su token ni por el login de plataforma.
 * 2. Un super-admin NO entra al panel de tiendas, y un dueño NO entra a
 *    plataforma. Son dos puertas distintas.
 * 3. Suspender una tienda la deja realmente inaccesible: catalogo publico y
 *    panel del dueño incluidos.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $superAdmin;
    private User $duenioA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

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

        $this->duenioA = $this->makeUser('duenio@tienda-a.com', 'admin', $this->tenantA);
        $this->superAdmin = $this->makeUser('operador@plataforma.com', 'superadmin', null);
    }

    private function makeUser(string $email, string $role, ?Tenant $tenant, bool $isActive = true): User
    {
        $user = new User([
            'name'      => $role,
            'email'     => $email,
            'password'  => 'password123',
            'role'      => $role,
            'is_active' => $isActive,
        ]);
        // null para el super-admin: no pertenece a ninguna tienda.
        $user->tenant_id = $tenant?->id;
        $user->save();

        return $user;
    }

    private function asSuperAdmin(): static
    {
        $token = $this->superAdmin->createToken('test', ['superadmin'])->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    // --- Puerta de entrada -------------------------------------------------

    public function test_el_operador_entra_por_su_propio_login(): void
    {
        $this->postJson('/api/platform/login', [
            'email'    => 'operador@plataforma.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            // No hay tenant que devolver: el operador no pertenece a ninguna tienda.
            ->assertJsonMissingPath('tenant');
    }

    public function test_el_dueno_de_una_tienda_no_entra_por_el_login_de_plataforma(): void
    {
        $this->postJson('/api/platform/login', [
            'email'    => 'duenio@tienda-a.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_el_operador_no_entra_por_el_login_del_panel_de_tiendas(): void
    {
        $this->postJson('/api/auth/login', [
            'email'    => 'operador@plataforma.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_un_operador_suspendido_no_entra(): void
    {
        $this->superAdmin->update(['is_active' => false]);

        $this->postJson('/api/platform/login', [
            'email'    => 'operador@plataforma.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_el_token_de_un_dueno_no_sirve_en_plataforma(): void
    {
        $token = $this->duenioA->createToken('test', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/platform/tenants')
            ->assertStatus(403);
    }

    public function test_el_token_del_operador_no_sirve_en_el_panel_de_tiendas(): void
    {
        $this->asSuperAdmin()
            ->withHeader('X-Tenant', $this->tenantA->slug)
            ->getJson('/api/products')
            ->assertStatus(403);
    }

    public function test_sin_sesion_no_se_listan_tiendas(): void
    {
        $this->getJson('/api/platform/tenants')->assertStatus(401);
    }

    // --- Listado -----------------------------------------------------------

    public function test_lista_todas_las_tiendas_con_sus_contadores(): void
    {
        $producto = new Product([
            'name'      => 'Producto de A',
            'price'     => 10,
            'stock'     => 1,
            'is_active' => true,
            'status'    => 'published',
        ]);
        $producto->tenant_id = $this->tenantA->id;
        $producto->save();

        $respuesta = $this->asSuperAdmin()->getJson('/api/platform/tenants')->assertOk();

        $slugs = collect($respuesta->json('data'))->pluck('slug')->all();

        // Ve las dos tiendas: no hay scope de tenant que lo limite.
        $this->assertContains('tienda-a', $slugs);
        $this->assertContains('tienda-b', $slugs);

        $filaA = collect($respuesta->json('data'))->firstWhere('slug', 'tienda-a');
        $this->assertSame(1, $filaA['products_count']);
        $this->assertSame(1, $filaA['users_count']);
    }

    public function test_busca_por_nombre_o_slug(): void
    {
        $respuesta = $this->asSuperAdmin()->getJson('/api/platform/tenants?search=tienda-b')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('tienda-b', $respuesta->json('data.0.slug'));
    }

    public function test_filtra_por_estado(): void
    {
        $this->tenantB->update(['is_active' => false]);

        $suspendidas = $this->asSuperAdmin()->getJson('/api/platform/tenants?status=suspended')->assertOk();
        $this->assertCount(1, $suspendidas->json('data'));
        $this->assertSame('tienda-b', $suspendidas->json('data.0.slug'));

        $activas = $this->asSuperAdmin()->getJson('/api/platform/tenants?status=active')->assertOk();
        $this->assertCount(1, $activas->json('data'));
        $this->assertSame('tienda-a', $activas->json('data.0.slug'));
    }

    // --- Suspension --------------------------------------------------------

    public function test_suspender_una_tienda_la_deja_inaccesible_y_reactivarla_la_devuelve(): void
    {
        $this->asSuperAdmin()
            ->putJson("/api/platform/tenants/{$this->tenantA->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        // El catálogo público deja de existir para el comprador...
        $this->getJson("/api/public/{$this->tenantA->slug}")->assertStatus(404);

        // ...y el dueño tampoco puede entrar a su panel.
        $tokenDuenio = $this->duenioA->createToken('test', ['admin'])->plainTextToken;
        $this->withHeaders([
            'Authorization' => 'Bearer '.$tokenDuenio,
            'X-Tenant'      => $this->tenantA->slug,
        ])->getJson('/api/products')->assertStatus(404);

        $this->asSuperAdmin()
            ->putJson("/api/platform/tenants/{$this->tenantA->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);

        $this->getJson("/api/public/{$this->tenantA->slug}")->assertOk();
    }

    public function test_puede_cambiar_el_plan_pero_no_a_uno_inventado(): void
    {
        $this->asSuperAdmin()
            ->putJson("/api/platform/tenants/{$this->tenantA->id}", ['plan' => 'pro'])
            ->assertOk()
            ->assertJsonPath('plan', 'pro');

        $this->asSuperAdmin()
            ->putJson("/api/platform/tenants/{$this->tenantA->id}", ['plan' => 'infinito'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        $this->assertSame('pro', $this->tenantA->fresh()->plan);
    }

    // --- Rescate de un dueño ----------------------------------------------

    public function test_puede_mandarle_al_dueno_el_enlace_de_recuperacion(): void
    {
        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tenantA->id}/password-reset")
            ->assertOk();

        // El operador nunca ve ni fija la contraseña: solo dispara el correo de 7.2.
        Notification::assertSentTo($this->duenioA, ResetPasswordNotification::class);
    }

    public function test_avisa_si_la_tienda_no_tiene_admin_activo(): void
    {
        $this->duenioA->update(['is_active' => false]);

        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tenantA->id}/password-reset")
            ->assertStatus(422);

        Notification::assertNothingSent();
    }
}
