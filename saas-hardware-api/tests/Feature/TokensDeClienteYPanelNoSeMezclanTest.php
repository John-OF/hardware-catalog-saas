<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Suplantacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `ACC-3`. El login de clientes buscaba por tienda y correo sin mirar el rol, asi
 * que un admin o staff que entraba por ahi se llevaba un `customer-token` de 30
 * dias que tambien valia en el panel -los middleware del panel leen el rol del
 * usuario, no el token- y que no cerraba su sesion del panel. Y al reves: las
 * rutas de cliente solo miraban la tienda, asi que el token de soporte (INF-2),
 * que es del admin, escribia en favoritos saltandose el solo-lectura.
 */
class TokensDeClienteYPanelNoSeMezclanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug' => 'tienda-tokens', 'name' => 'Tienda Tokens',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->admin = $this->usuario('duenio@tokens.test', 'admin');

        $this->producto = new Product(['name' => 'GPU', 'price' => 100, 'stock' => 5, 'status' => 'published', 'is_active' => true]);
        $this->producto->tenant_id = $this->tenant->id;
        $this->producto->save();
    }

    // ------------------------------------------ el login de clientes

    public function test_el_login_de_clientes_no_acepta_a_un_admin(): void
    {
        $this->loginDeCliente('duenio@tokens.test')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Las credenciales son incorrectas para esta tienda.');

        $this->assertSame(0, DB::table('personal_access_tokens')->count(), 'Emitio un token igual.');
    }

    public function test_el_login_de_clientes_no_acepta_a_un_colaborador(): void
    {
        $this->usuario('vendedor@tokens.test', 'staff');

        $this->loginDeCliente('vendedor@tokens.test')->assertStatus(422);

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    /** Control: el cliente sigue entrando. */
    public function test_el_cliente_sigue_entrando(): void
    {
        $this->usuario('cliente@tokens.test', 'customer');

        $this->loginDeCliente('cliente@tokens.test')->assertOk()->assertJsonStructure(['token']);
    }

    // ----------------------------------- un token de cliente en el panel

    /**
     * Los tokens que el login de clientes le dio a alguien del equipo antes del
     * arreglo siguen vivos hasta 30 dias. Esta es la barrera que los apaga.
     */
    public function test_un_token_de_cliente_de_un_admin_no_entra_al_panel(): void
    {
        $token = $this->admin->createToken('customer-token', ['customer'], now()->addDays(30))->plainTextToken;

        $this->alPanel($token)->assertForbidden();
    }

    /** Control: el token del panel del mismo admin sigue entrando. */
    public function test_el_token_del_panel_del_admin_sigue_entrando(): void
    {
        $token = $this->admin->createToken('spa-token', ['admin'], now()->addDays(7))->plainTextToken;

        $this->alPanel($token)->assertOk();
    }

    // ------------------------------------ el panel en las rutas de cliente

    /**
     * El token de soporte es del admin y es de solo lectura, pero ese bloqueo va
     * en el grupo del panel: en las rutas de cliente no llega. Antes pasaba
     * `customer` -que solo miraba la tienda- y escribia en favoritos.
     */
    public function test_el_token_de_soporte_no_escribe_en_favoritos(): void
    {
        $token = $this->admin->createToken('support-token', [Suplantacion::ABILITY], now()->addMinutes(15))->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/public/{$this->tenant->slug}/favorites/{$this->producto->id}")
            ->assertUnauthorized();

        $this->assertSame(0, DB::table('user_favorites')->count());
    }

    public function test_el_token_del_panel_tampoco_vale_en_las_rutas_de_cliente(): void
    {
        $token = $this->admin->createToken('spa-token', ['admin'], now()->addDays(7))->plainTextToken;

        foreach (['favorites', 'my-orders', 'auth/me'] as $ruta) {
            $this->app['auth']->forgetGuards();

            $this->withHeaders(['Authorization' => "Bearer {$token}"])
                ->getJson("/api/public/{$this->tenant->slug}/{$ruta}")
                ->assertUnauthorized();
        }
    }

    /**
     * La misma regla que ya aplicaban las resenas (`clienteDeLaTienda()`), que
     * el middleware no tenia: un cliente desactivado no sigue usando su token.
     */
    public function test_un_cliente_desactivado_no_sigue_usando_su_token(): void
    {
        $cliente = $this->usuario('cliente@tokens.test', 'customer');
        $token = $cliente->createToken('customer-token', ['customer'], now()->addDays(30))->plainTextToken;

        $cliente->update(['is_active' => false]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/public/{$this->tenant->slug}/my-orders")
            ->assertUnauthorized();
    }

    // ------------------------------------------------------------ apoyo

    private function usuario(string $correo, string $rol): User
    {
        $usuario = new User(['name' => $correo, 'email' => $correo, 'password' => 'password123', 'role' => $rol, 'is_active' => true]);
        $usuario->tenant_id = $this->tenant->id;
        $usuario->save();

        return $usuario;
    }

    private function loginDeCliente(string $correo): TestResponse
    {
        return $this->postJson("/api/public/{$this->tenant->slug}/auth/login", [
            'email' => $correo, 'password' => 'password123',
        ]);
    }

    private function alPanel(string $token): TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant' => $this->tenant->slug])
            ->getJson('/api/dashboard/stats');
    }
}
