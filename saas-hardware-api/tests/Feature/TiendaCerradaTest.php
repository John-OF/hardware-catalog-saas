<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El equipo de una tienda cerrada entra a una pantalla que lo explica (FUN-28).
 *
 * Antes, la contraseña daba token y luego cada ruta del panel respondía 404
 * («tienda no encontrada»): el panel lo pintaba como un fallo cualquiera, y ni
 * el logout funcionaba. Lo que fija este test:
 *
 * 1. El login responde con `cierre`: por qué está cerrada y desde cuándo.
 * 2. Con la tienda cerrada, solo `me` y `logout` funcionan; el resto del panel
 *    responde 403 con `motivo: tienda_cerrada`, que es lo que el panel escucha.
 * 3. A quien no es de su equipo, una tienda cerrada le sigue sin existir (404).
 */
class TiendaCerradaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;
    private User $duenio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Carbon::setTestNow('2026-11-10 15:00:00');

        $this->tienda = Tenant::create([
            'slug' => 'cerrada', 'name' => 'Tienda Cerrada',
            'whatsapp_number' => '593999999999', 'is_active' => false,
        ]);
        $this->tienda->forceFill(['trial_ends_at' => Carbon::parse('2026-11-08 12:00:00')])->save();

        $this->duenio = $this->usuario('duenio@cerrada.test', 'admin', $this->tienda);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function usuario(string $email, string $rol, Tenant $tienda): User
    {
        $usuario = new User([
            'name' => $rol, 'email' => $email, 'password' => 'password123',
            'role' => $rol, 'is_active' => true,
        ]);
        $usuario->tenant_id = $tienda->id;
        $usuario->save();

        return $usuario;
    }

    private function como(User $usuario, ?string $slug = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('t', [$usuario->role])->plainTextToken,
            'X-Tenant'      => $slug ?? $this->tienda->slug,
        ]);
    }

    public function test_el_login_entra_y_dice_por_que_esta_cerrada(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'duenio@cerrada.test', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token'])
            ->assertJsonPath('tenant.is_active', false)
            ->assertJsonPath('cierre.motivo', Tenant::CIERRE_PRUEBA)
            ->assertJsonPath('cierre.desde', '2026-11-08T12:00:00+00:00')
            ->assertJsonPath('cierre.plan', 'Básico');
    }

    public function test_me_responde_con_el_cierre_y_el_logout_cierra_la_sesion(): void
    {
        $token = $this->duenio->createToken('t', ['admin']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken, 'X-Tenant' => 'cerrada'])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'duenio@cerrada.test')
            ->assertJsonPath('cierre.motivo', Tenant::CIERRE_PRUEBA);

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken, 'X-Tenant' => 'cerrada'])
            ->postJson('/api/auth/logout')
            ->assertOk();

        // Antes el logout daba 404 y el token quedaba vivo.
        $this->assertSame(0, $this->duenio->tokens()->count());
    }

    public function test_el_resto_del_panel_responde_403_con_el_motivo(): void
    {
        foreach (['/api/dashboard/stats', '/api/products', '/api/tenant', '/api/plan'] as $ruta) {
            $this->como($this->duenio)->getJson($ruta)
                ->assertForbidden()
                ->assertJsonPath('motivo', 'tienda_cerrada')
                ->assertJsonPath('cierre.motivo', Tenant::CIERRE_PRUEBA);
        }

        $this->como($this->duenio)->postJson('/api/products', ['name' => 'X', 'price' => 1, 'stock' => 1])
            ->assertForbidden()
            ->assertJsonPath('motivo', 'tienda_cerrada');
    }

    public function test_el_motivo_sale_de_las_fechas(): void
    {
        $this->assertSame(Tenant::CIERRE_PRUEBA, $this->tienda->cierre()['motivo']);

        $this->tienda->forceFill(['trial_ends_at' => null, 'plan_ends_at' => now()->subDays(2), 'plan' => 'pro'])->save();
        // INF-24: con el plan vencido no se borra nada (para tener plan hubo que pagarlo).
        $this->assertSame(['motivo' => Tenant::CIERRE_PLAN, 'desde' => '2026-11-08T15:00:00+00:00', 'plan' => 'Pro', 'borrado_desde' => null, 'datos_borrados' => null], $this->tienda->fresh()->cierre());

        // Plan vigente y cerrada: la cerró el operador a mano.
        $this->tienda->forceFill(['plan_ends_at' => now()->addMonth()])->save();
        $this->assertSame(Tenant::CIERRE_SUSPENDIDA, $this->tienda->fresh()->cierre()['motivo']);
        $this->assertNull($this->tienda->fresh()->cierre()['desde']);

        // Abierta: no hay cierre.
        $this->tienda->update(['is_active' => true]);
        $this->assertNull($this->tienda->fresh()->cierre());
    }

    public function test_el_equipo_entero_ve_la_tienda_cerrada(): void
    {
        $staff = $this->usuario('staff@cerrada.test', 'staff', $this->tienda);

        $this->como($staff)->getJson('/api/auth/me')->assertOk()->assertJsonPath('cierre.motivo', Tenant::CIERRE_PRUEBA);
        $this->como($staff)->getJson('/api/orders')->assertForbidden()->assertJsonPath('motivo', 'tienda_cerrada');
    }

    public function test_a_quien_no_es_de_su_equipo_una_tienda_cerrada_no_le_existe(): void
    {
        $otra = Tenant::create(['slug' => 'otra', 'name' => 'Otra', 'whatsapp_number' => '1', 'is_active' => true]);
        $ajeno = $this->usuario('admin@otra.test', 'admin', $otra);

        $this->como($ajeno, 'cerrada')->getJson('/api/auth/me')->assertNotFound();
        $this->como($ajeno, 'cerrada')->getJson('/api/products')->assertNotFound();

        $cliente = $this->usuario('cliente@cerrada.test', 'customer', $this->tienda);
        $this->como($cliente)->getJson('/api/products')->assertNotFound();

        // Sin sesión, tampoco: el middleware de tienda va antes que el de sesión,
        // y una tienda cerrada responde lo mismo que antes de FUN-28.
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Tenant', 'cerrada')->getJson('/api/products')->assertNotFound();
    }

    public function test_con_la_tienda_abierta_no_hay_cierre(): void
    {
        $this->tienda->update(['is_active' => true]);

        $this->como($this->duenio)->getJson('/api/auth/me')->assertOk()->assertJsonPath('cierre', null);
        $this->como($this->duenio)->getJson('/api/products')->assertOk();
    }
}
