<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lo que le faltaba al panel de plataforma (INF-2).
 *
 * `PlatformAdminTest` cubre la puerta de entrada y las dos acciones que ya
 * existían (suspender y cambiar de plan). Aquí va lo añadido: el resumen del
 * negocio, la ficha de una tienda, la bitácora y —lo delicado— entrar en una
 * tienda ajena como soporte.
 *
 * Lo que de verdad fija este fichero es que la llave prestada esté bien atada:
 *
 * 1. Deja mirar y NO deja escribir, en ninguna ruta del panel.
 * 2. No echa al dueño de su propia sesión.
 * 3. Caduca sola.
 * 4. No se puede usar sin dejar rastro.
 *
 * Y una regresión que importa tanto como lo anterior: que el cerrojo de solo
 * lectura no le haya quitado la escritura a un admin normal.
 */
class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;
    private User $superAdmin;
    private User $duenio;

    /**
     * El guard por defecto ANTES de que ninguna peticion lo toque.
     *
     * Se guarda aqui porque `auth:sanctum` lo sobreescribe al autenticar, asi
     * que leerlo mas tarde devuelve ya el corrompido (mismo motivo y misma
     * solucion que en TeamUsersTest).
     */
    private string $guardOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->guardOriginal = (string) config('auth.defaults.guard');

        $this->tienda = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
            'plan'            => 'free',
        ]);

        $this->duenio     = $this->makeUser('duenio@tienda-a.com', 'admin', $this->tienda);
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
        $user->tenant_id = $tenant?->id;
        $user->save();

        return $user;
    }

    /**
     * Olvidar la peticion anterior antes de hacer otra con OTRO usuario.
     *
     * En pruebas la aplicacion no arranca de cero en cada peticion: el guard
     * conserva el usuario que ya resolvio, asi que la segunda peticion
     * contestaria como la primera. Aqui se nota especialmente —cada test alterna
     * operador, soporte y dueño— y sin esto los tres se responderian entre
     * ellos. Es un efecto del entorno de pruebas, no del producto; el mismo que
     * documenta TeamUsersTest.
     */
    private function desdeCero(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse($this->guardOriginal);
    }

    private function asSuperAdmin(): static
    {
        $this->desdeCero();

        $token = $this->superAdmin->createToken('test', ['superadmin'])->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** Cabeceras de una sesión del panel de la tienda con el token que se le pase. */
    private function comoPanel(string $token): static
    {
        $this->desdeCero();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tienda->slug,
        ]);
    }

    private function crearProducto(Tenant $tenant, string $nombre = 'Producto'): Product
    {
        $producto = new Product([
            'name'      => $nombre,
            'price'     => 10,
            'stock'     => 1,
            'is_active' => true,
            'status'    => 'published',
        ]);
        $producto->tenant_id = $tenant->id;
        $producto->save();

        return $producto;
    }

    // --- Resumen -----------------------------------------------------------

    public function test_el_resumen_cuenta_por_encima_de_todas_las_tiendas(): void
    {
        $otra = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => false,
            'plan'            => 'pro',
        ]);

        $this->crearProducto($this->tienda);
        $this->crearProducto($otra);

        $respuesta = $this->asSuperAdmin()->getJson('/api/platform/stats')->assertOk();

        // Sin `withoutTenant()` en el controlador esto saldría a cero: en las
        // rutas de plataforma no hay tienda actual y el scope falla en cerrado.
        $this->assertSame(2, $respuesta->json('tiendas.total'));
        $this->assertSame(1, $respuesta->json('tiendas.activas'));
        $this->assertSame(1, $respuesta->json('tiendas.suspendidas'));
        $this->assertSame(2, $respuesta->json('catalogo.productos'));
        $this->assertSame(1, $respuesta->json('planes.free'));
        $this->assertSame(1, $respuesta->json('planes.pro'));
    }

    public function test_el_resumen_señala_las_altas_que_no_han_arrancado(): void
    {
        // La tienda del setUp no tiene productos: es una que se registró y no
        // llegó a empezar, que es el número que dice si el problema está en
        // atraer gente o en el primer día de uso.
        $this->asSuperAdmin()->getJson('/api/platform/stats')
            ->assertOk()
            ->assertJsonPath('tiendas.sin_arrancar', 1);

        $this->crearProducto($this->tienda);

        $this->asSuperAdmin()->getJson('/api/platform/stats')
            ->assertOk()
            ->assertJsonPath('tiendas.sin_arrancar', 0);
    }

    public function test_el_resumen_no_cuenta_a_los_clientes_como_equipo(): void
    {
        $this->makeUser('comprador@correo.com', 'customer', $this->tienda);

        // Dos usuarios del panel (dueño y operador) y un cliente que no cuenta:
        // un catálogo con 500 compradores registrados no es un SaaS con 500
        // personas trabajando dentro.
        $this->asSuperAdmin()->getJson('/api/platform/stats')
            ->assertOk()
            ->assertJsonPath('catalogo.equipo', 1);
    }

    // --- Ficha de una tienda ------------------------------------------------

    public function test_la_ficha_trae_el_plan_su_consumo_y_el_equipo(): void
    {
        $this->crearProducto($this->tienda, 'Uno');
        $this->crearProducto($this->tienda, 'Dos');
        $this->makeUser('vendedor@tienda-a.com', 'staff', $this->tienda);
        $this->makeUser('comprador@correo.com', 'customer', $this->tienda);

        $respuesta = $this->asSuperAdmin()
            ->getJson("/api/platform/tenants/{$this->tienda->id}")
            ->assertOk();

        $this->assertSame('free', $respuesta->json('plan.clave'));
        $this->assertSame(20, $respuesta->json('plan.limites.products'));
        $this->assertSame(2, $respuesta->json('plan.uso.products'));

        // El tope de `users` es del equipo, no de los compradores: aquí son dos
        // (dueño y vendedor) aunque en la tabla `users` de esa tienda haya tres.
        $this->assertSame(2, $respuesta->json('plan.uso.users'));
        $this->assertCount(2, $respuesta->json('equipo'));
        $this->assertSame(1, $respuesta->json('clientes'));
    }

    public function test_la_ficha_de_un_plan_inventado_cae_al_plan_por_defecto(): void
    {
        // Un `plan` escrito a mano en la base o sobrante de otra versión no
        // puede significar "sin límites": eso sería barra libre por un typo.
        $this->tienda->forceFill(['plan' => 'platino'])->save();

        $this->asSuperAdmin()
            ->getJson("/api/platform/tenants/{$this->tienda->id}")
            ->assertOk()
            ->assertJsonPath('plan.clave', 'free')
            ->assertJsonPath('plan.limites.products', 20);
    }

    public function test_la_ficha_solo_la_ve_el_operador(): void
    {
        $token = $this->duenio->createToken('test', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/platform/tenants/{$this->tienda->id}")
            ->assertStatus(403);
    }

    // --- Entrar como soporte -------------------------------------------------

    public function test_el_soporte_puede_mirar_el_panel_de_la_tienda(): void
    {
        $this->crearProducto($this->tienda);

        $respuesta = $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('user.email', 'duenio@tienda-a.com')
            ->assertJsonPath('expira_en', 15);

        $this->comoPanel($respuesta->json('token'))
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_el_soporte_no_puede_escribir_nada(): void
    {
        $producto = $this->crearProducto($this->tienda);

        $token = $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->json('token');

        // Una por verbo y por tipo de ruta: el cerrojo va en el grupo entero,
        // así que lo que se comprueba es que no dependa de la ruta concreta.
        $this->comoPanel($token)->postJson('/api/products', ['name' => 'Nuevo'])->assertStatus(403);
        $this->comoPanel($token)->putJson("/api/products/{$producto->id}", ['name' => 'Otro'])->assertStatus(403);
        $this->comoPanel($token)->deleteJson("/api/products/{$producto->id}")->assertStatus(403);
        $this->comoPanel($token)->putJson('/api/tenant', ['name' => 'Secuestrada'])->assertStatus(403);
        $this->comoPanel($token)->postJson('/api/users', ['email' => 'cuela@ejemplo.com'])->assertStatus(403);

        // Y nada de eso llegó a pasar.
        $this->assertDatabaseHas('products', ['id' => $producto->id, 'name' => 'Producto']);
        $this->assertSame('Tienda A', $this->tienda->fresh()->name);
    }

    public function test_el_soporte_recibe_un_403_aunque_el_recurso_no_exista(): void
    {
        $token = $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->json('token');

        // Si la negativa llegara DESPUÉS de resolver la URL, la respuesta sería
        // 404 para un id inexistente y 403 para uno real: el propio error diría
        // qué filas hay en la tienda.
        $this->comoPanel($token)
            ->deleteJson('/api/products/00000000-0000-0000-0000-000000000000')
            ->assertStatus(403);
    }

    public function test_el_panel_le_dice_al_operador_que_esta_en_casa_ajena(): void
    {
        $token = $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->json('token');

        // La marca sale del token, no de lo que recuerde el navegador: es lo que
        // permite que el aviso sobreviva a un refresco y no se pueda esconder.
        $this->comoPanel($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('soporte', true);

        $tokenDuenio = $this->duenio->createToken('test', ['admin'])->plainTextToken;
        $this->comoPanel($tokenDuenio)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('soporte', false);
    }

    public function test_entrar_como_soporte_no_echa_al_dueno(): void
    {
        $tokenDuenio = $this->duenio->createToken('test', ['admin'])->plainTextToken;

        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->assertOk();

        // Los dos logins del proyecto borran los tokens anteriores; este NO, y es
        // deliberado: el dueño suele estar dentro del panel justo cuando llama
        // pidiendo ayuda.
        $this->comoPanel($tokenDuenio)->getJson('/api/products')->assertOk();

        // Y sigue pudiendo escribir: el cerrojo es del token de soporte, no de
        // la tienda ni de la persona.
        $this->comoPanel($tokenDuenio)
            ->postJson('/api/categories', ['name' => 'Placas'])
            ->assertCreated();
    }

    public function test_el_token_de_soporte_caduca_solo(): void
    {
        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->assertOk();

        $token = $this->duenio->tokens()->where('name', 'support-token')->firstOrFail();

        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($token->expires_at), 1);
    }

    public function test_no_se_entra_como_soporte_en_una_tienda_suspendida(): void
    {
        // Su panel está cerrado por middleware, así que el token entraría para
        // toparse con un 403 en la primera pantalla.
        $this->tienda->update(['is_active' => false]);

        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->assertStatus(422);

        $this->assertSame(0, $this->duenio->tokens()->count());
    }

    public function test_no_se_entra_como_soporte_si_no_hay_admin_activo(): void
    {
        $this->duenio->update(['is_active' => false]);

        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->assertStatus(422);
    }

    public function test_el_dueno_de_una_tienda_no_puede_entrar_en_otra(): void
    {
        $token = $this->duenio->createToken('test', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->assertStatus(403);
    }

    // --- Bitácora -------------------------------------------------------------

    public function test_entrar_como_soporte_queda_anotado(): void
    {
        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate")
            ->assertOk();

        $linea = ActivityLog::where('action', ActivityLog::TIENDA_SOPORTE)->firstOrFail();

        $this->assertSame($this->tienda->id, $linea->tenant_id);
        $this->assertSame('operador@plataforma.com', $linea->actor_email);
        $this->assertSame('duenio@tienda-a.com', $linea->context['como']);
        // El snapshot: la línea tiene que seguir diciendo de qué tienda habla
        // aunque esa tienda se borre.
        $this->assertSame('Tienda A', $linea->context['tienda']);
    }

    public function test_suspender_y_cambiar_de_plan_quedan_anotados(): void
    {
        $this->asSuperAdmin()
            ->putJson("/api/platform/tenants/{$this->tienda->id}", ['is_active' => false, 'plan' => 'pro'])
            ->assertOk();

        // Dos líneas y no una: suspender y cambiar el plan son dos decisiones
        // distintas aunque viajen en el mismo PUT.
        $this->assertDatabaseCount('activity_logs', 2);

        $plan = ActivityLog::where('action', ActivityLog::TIENDA_PLAN)->firstOrFail();
        $this->assertSame('free', $plan->context['antes']);
        $this->assertSame('pro', $plan->context['despues']);

        $this->assertDatabaseHas('activity_logs', ['action' => ActivityLog::TIENDA_SUSPENDIDA]);
    }

    public function test_un_put_que_no_cambia_nada_no_ensucia_la_bitacora(): void
    {
        // La tienda ya está activa y ya es `free`.
        $this->asSuperAdmin()
            ->putJson("/api/platform/tenants/{$this->tienda->id}", ['is_active' => true, 'plan' => 'free'])
            ->assertOk();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_el_rescate_de_acceso_queda_anotado(): void
    {
        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/password-reset")
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action'      => ActivityLog::TIENDA_RESET,
            'actor_email' => 'operador@plataforma.com',
        ]);
    }

    public function test_la_bitacora_se_lista_y_se_filtra_por_accion(): void
    {
        $this->asSuperAdmin()->putJson("/api/platform/tenants/{$this->tienda->id}", ['plan' => 'pro']);
        $this->asSuperAdmin()->postJson("/api/platform/tenants/{$this->tienda->id}/impersonate");

        $this->asSuperAdmin()->getJson('/api/platform/logs')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $filtrada = $this->asSuperAdmin()
            ->getJson('/api/platform/logs?action='.ActivityLog::TIENDA_PLAN)
            ->assertOk();

        $this->assertSame(1, $filtrada->json('total'));
        $this->assertSame(ActivityLog::TIENDA_PLAN, $filtrada->json('data.0.action'));
    }

    public function test_la_bitacora_no_se_vuelca_entera_de_una_vez(): void
    {
        $this->asSuperAdmin()->getJson('/api/platform/logs?per_page=100000')
            ->assertOk()
            ->assertJsonPath('per_page', 100);

        // Y un valor sin sentido no rompe la paginación.
        $this->asSuperAdmin()->getJson('/api/platform/logs?per_page=0')
            ->assertOk()
            ->assertJsonPath('per_page', 1);
    }

    public function test_la_bitacora_no_la_ve_el_dueno_de_una_tienda(): void
    {
        $token = $this->duenio->createToken('test', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/platform/logs')
            ->assertStatus(403);
    }

    public function test_la_ficha_ensena_lo_que_se_le_ha_hecho_a_esa_tienda(): void
    {
        $otra = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);

        $this->asSuperAdmin()->putJson("/api/platform/tenants/{$this->tienda->id}", ['plan' => 'pro']);
        $this->asSuperAdmin()->putJson("/api/platform/tenants/{$otra->id}", ['plan' => 'pro']);

        // La bitácora de la ficha es la de ESA tienda: `ActivityLog` no lleva el
        // global scope, así que el filtro es explícito y esto lo vigila.
        $respuesta = $this->asSuperAdmin()
            ->getJson("/api/platform/tenants/{$this->tienda->id}")
            ->assertOk();

        $this->assertCount(1, $respuesta->json('bitacora'));
        $this->assertSame($this->tienda->id, $respuesta->json('bitacora.0.tenant_id'));
    }

    // --- Rescate de admin ----------------------------------------------------

    public function test_rescata_una_tienda_sin_ningun_admin_activo(): void
    {
        // Sin admin activo: el desactivado es el escenario más simple del
        // mismo problema que cierra la carrera de UserController — aquí no
        // importa cómo se llegó a cero, solo que se pueda salir.
        $this->duenio->update(['is_active' => false]);
        $staff = $this->makeUser('staff@tienda-a.com', 'staff', $this->tienda);

        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/rescue-admin", ['user_id' => $staff->id])
            ->assertOk()
            ->assertJsonPath('role', 'admin');

        $this->assertSame('admin', $staff->fresh()->role);

        // Le cierra la sesión, igual que cualquier cambio de rol: el panel que
        // tuviera abierto se pintó como staff.
        $this->assertSame(0, $staff->tokens()->count());

        $this->assertDatabaseHas('activity_logs', [
            'action'      => ActivityLog::TIENDA_RESCATE_ADMIN,
            'tenant_id'   => $this->tienda->id,
            'actor_email' => 'operador@plataforma.com',
        ]);
    }

    public function test_el_rescate_falla_si_ya_hay_un_admin_activo(): void
    {
        // $this->duenio sigue activo: no es una puerta para repartir roles en
        // una tienda que ya se gestiona sola.
        $staff = $this->makeUser('staff@tienda-a.com', 'staff', $this->tienda);

        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/rescue-admin", ['user_id' => $staff->id])
            ->assertStatus(422);

        $this->assertSame('staff', $staff->fresh()->role);
    }

    public function test_el_rescate_no_acepta_a_un_cliente_ni_a_un_colaborador_de_otra_tienda(): void
    {
        $this->duenio->update(['is_active' => false]);

        $cliente = $this->makeUser('cliente@tienda-a.com', 'customer', $this->tienda);

        $otra          = Tenant::create([
            'slug' => 'tienda-b', 'name' => 'Tienda B',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);
        $staffDeOtra = $this->makeUser('staff@tienda-b.com', 'staff', $otra);

        // Ninguno de los dos es "un colaborador de ESTA tienda": el cliente
        // porque no es del panel, el de la otra tienda porque el filtro por
        // `tenant_id` lo excluye — el mismo IDOR que vigila `TenantIsolationTest`
        // en el resto del panel.
        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/rescue-admin", ['user_id' => $cliente->id])
            ->assertStatus(422);

        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/rescue-admin", ['user_id' => $staffDeOtra->id])
            ->assertStatus(422);

        $this->assertSame('customer', $cliente->fresh()->role);
        $this->assertSame('staff', $staffDeOtra->fresh()->role);
    }

    public function test_el_rescate_no_acepta_a_un_colaborador_desactivado(): void
    {
        $this->duenio->update(['is_active' => false]);
        $staffInactivo = $this->makeUser('staff@tienda-a.com', 'staff', $this->tienda, isActive: false);

        $this->asSuperAdmin()
            ->postJson("/api/platform/tenants/{$this->tienda->id}/rescue-admin", ['user_id' => $staffInactivo->id])
            ->assertStatus(422);

        $this->assertSame('staff', $staffInactivo->fresh()->role);
    }

    // --- Regresión ------------------------------------------------------------

    public function test_un_admin_normal_sigue_pudiendo_escribir(): void
    {
        // El cerrojo de solo lectura va en el grupo ENTERO del panel: si mirara
        // mal las abilities, dejaría a todas las tiendas sin poder guardar nada.
        $token = $this->duenio->createToken('spa-token', ['admin'])->plainTextToken;

        $this->comoPanel($token)
            ->postJson('/api/categories', ['name' => 'Procesadores'])
            ->assertCreated();
    }

    public function test_los_pedidos_de_la_ficha_son_los_de_esa_tienda(): void
    {
        $otra = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);

        $mio = new Order(['customer_name' => 'Cliente A', 'status' => 'pending', 'total' => 100]);
        $mio->tenant_id = $this->tienda->id;
        $mio->save();

        $ajeno = new Order(['customer_name' => 'Cliente B', 'status' => 'pending', 'total' => 200]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        $respuesta = $this->asSuperAdmin()
            ->getJson("/api/platform/tenants/{$this->tienda->id}")
            ->assertOk();

        $this->assertCount(1, $respuesta->json('ultimos_pedidos'));
        $this->assertSame('Cliente A', $respuesta->json('ultimos_pedidos.0.customer_name'));
    }
}
