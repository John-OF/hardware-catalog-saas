<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Verificacion del correo del alta de tienda (FUN-5).
 *
 * La columna `email_verified_at` existia desde la migracion inicial y **nadie la
 * escribia nunca**: el registro es abierto y autoservicio (SAAS-1), asi que se
 * podian dar de alta tiendas con correos inventados, y encima el aviso de pedido
 * nuevo (OWN-2) y la recuperacion de contrasenia (SAAS-2) apuntaban a esa
 * direccion sin haberla comprobado.
 *
 * Lo que fija este test, mas alla de "el flujo funciona":
 *
 * 1. **Que es lo que se cierra sin verificar.** El panel NO: se entra y se
 *    configura, porque cerrarlo dejaria al dueno mirando una pared si el correo
 *    tarda. Lo que se cierra es el catalogo publico, por sus DOS puertas -el slug
 *    y el dominio propio- y tambien la vista previa de los crawlers, que es la
 *    que se olvida.
 * 2. **Que verificar no reactiva una tienda suspendida.** `is_active` es el
 *    interruptor de la plataforma y `is_published` el de la verificacion; si se
 *    hubieran juntado en una sola columna, pinchar el enlace del correo
 *    devolveria al aire una tienda que la plataforma cerro a proposito.
 * 3. **Que el enlace se autentica solo.** Sin firma no vale, con el hash de otro
 *    correo tampoco, y usarlo dos veces no rompe nada.
 * 4. **Que a los clientes del catalogo no se les manda nada.** `MustVerifyEmail`
 *    esta en `User`, que es tambien el modelo de los compradores.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** Datos validos para POST /api/auth/register. */
    private function datosDeAlta(array $extra = []): array
    {
        return array_merge([
            'store_name'            => 'Tienda Nueva',
            'slug'                  => 'tienda-nueva',
            'whatsapp'              => '51999999999',
            'name'                  => 'Duenio',
            'email'                 => 'duenio@tienda-nueva.com',
            'password'              => 'Contrasenia-larga-1',
            'password_confirmation' => 'Contrasenia-larga-1',
        ], $extra);
    }

    /** Una tienda ya existente y publicada, con su admin verificado. */
    private function tiendaVerificada(string $slug = 'tienda-vieja'): array
    {
        $tenant = Tenant::create([
            'slug'            => $slug,
            'name'            => 'Tienda Vieja',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);

        $admin = new User([
            'name'      => 'Duenio Viejo',
            'email'     => "duenio@{$slug}.com",
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $admin->tenant_id = $tenant->id;
        $admin->email_verified_at = now();
        $admin->save();

        return [$tenant, $admin];
    }

    /** El enlace tal cual lo genera la notificacion. */
    private function enlaceDeVerificacion(User $user): string
    {
        return URL::temporarySignedRoute(
            'verificacion.correo',
            now()->addMinutes((int) config('auth.verification.expire', 1440)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );
    }

    private function altaDeTienda(): string
    {
        return $this->postJson('/api/auth/register', $this->datosDeAlta())
            ->assertCreated()
            ->json('token');
    }

    private function adminReciente(): User
    {
        return User::withoutTenant()->where('email', 'duenio@tienda-nueva.com')->firstOrFail();
    }

    private function tiendaReciente(): Tenant
    {
        return Tenant::where('slug', 'tienda-nueva')->firstOrFail();
    }

    public function test_el_alta_crea_la_tienda_sin_publicar_y_manda_el_correo(): void
    {
        Notification::fake();

        $this->altaDeTienda();

        $tenant = $this->tiendaReciente();
        $admin  = $this->adminReciente();

        // Activa -nadie la ha suspendido- pero todavia no publicada.
        $this->assertTrue($tenant->is_active);
        $this->assertFalse($tenant->is_published);
        $this->assertNull($admin->email_verified_at);

        Notification::assertSentTo($admin, VerifyEmailNotification::class);
    }

    public function test_el_catalogo_publico_de_una_tienda_sin_verificar_no_existe(): void
    {
        $this->altaDeTienda();

        $this->getJson('/api/public/tienda-nueva')->assertNotFound();
        $this->getJson('/api/public/tienda-nueva/products')->assertNotFound();
    }

    public function test_el_dominio_propio_de_una_tienda_sin_verificar_tampoco_resuelve(): void
    {
        $this->altaDeTienda();

        Tenant::where('slug', 'tienda-nueva')->update(['custom_domain' => 'mitienda.com']);

        // Es la OTRA puerta al mismo catalogo: si solo se cerrara el slug, la
        // tienda seguiria abierta por su dominio propio.
        $this->getJson('/api/public/resolve-domain?domain=mitienda.com')->assertNotFound();
    }

    public function test_la_vista_previa_al_compartir_tampoco_aparece_sin_verificar(): void
    {
        $this->altaDeTienda();

        // Es la que se olvida: las rutas de crawler viven en web.php, aparte del
        // middleware que cierra la API. FUN-7 ya enseno que van por su cuenta.
        $this->get('/tienda-nueva', ['User-Agent' => 'WhatsApp/2.0'])->assertNotFound();
        $this->get('/tienda-nueva/builder', ['User-Agent' => 'facebookexternalhit/1.1'])->assertNotFound();
    }

    public function test_el_panel_funciona_sin_verificar(): void
    {
        $token = $this->altaDeTienda();

        $panel = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant'      => 'tienda-nueva',
        ]);

        // Entrar y configurar SI se puede: cerrar tambien el panel dejaria al
        // dueno sin nada que hacer mientras espera un correo.
        $panel->postJson('/api/categories', ['name' => 'Procesadores'])->assertCreated();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant'      => 'tienda-nueva',
        ])->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email_verified', false);
    }

    public function test_el_enlace_firmado_verifica_y_publica_la_tienda(): void
    {
        $this->altaDeTienda();

        $admin = $this->adminReciente();

        $respuesta = $this->get($this->enlaceDeVerificacion($admin));
        $respuesta->assertRedirect();
        $this->assertStringContainsString('verificacion=ok', $respuesta->headers->get('Location'));

        $this->assertNotNull($admin->fresh()->email_verified_at);
        $this->assertTrue($this->tiendaReciente()->is_published);

        // Y ahora el catalogo si responde.
        $this->getJson('/api/public/tienda-nueva')->assertOk();
    }

    public function test_verificar_no_reactiva_una_tienda_suspendida_por_la_plataforma(): void
    {
        $this->altaDeTienda();

        $admin = $this->adminReciente();

        // La plataforma la suspende ANTES de que el dueno verifique.
        Tenant::where('slug', 'tienda-nueva')->update(['is_active' => false]);

        $this->get($this->enlaceDeVerificacion($admin))->assertRedirect();

        $tenant = $this->tiendaReciente();

        // El correo queda verificado y la tienda publicada, pero sigue suspendida:
        // son dos preguntas distintas, y el catalogo sigue cerrado por la otra.
        $this->assertNotNull($admin->fresh()->email_verified_at);
        $this->assertTrue($tenant->is_published);
        $this->assertFalse($tenant->is_active);
        $this->getJson('/api/public/tienda-nueva')->assertNotFound();
    }

    public function test_un_enlace_sin_firma_no_verifica_nada(): void
    {
        $this->altaDeTienda();

        $admin = $this->adminReciente();
        $hash  = sha1($admin->getEmailForVerification());

        $respuesta = $this->get("/api/auth/verify-email/{$admin->id}/{$hash}");

        // Sin firma valida no llega ni al controlador: lo para el middleware
        // `signed`, y el handler lo convierte en pantalla en vez de en JSON.
        $respuesta->assertRedirect();
        $this->assertStringContainsString('verificacion=caducada', $respuesta->headers->get('Location'));

        $this->assertNull($admin->fresh()->email_verified_at);
        $this->assertFalse($this->tiendaReciente()->is_published);
    }

    public function test_un_enlace_con_el_hash_de_otro_correo_no_verifica(): void
    {
        $this->altaDeTienda();

        $admin = $this->adminReciente();

        // Firma valida, hash del correo equivocado: es lo que pasa si el dueno
        // cambia de direccion despues de haber pedido el enlace.
        $enlace = URL::temporarySignedRoute(
            'verificacion.correo',
            now()->addMinutes(60),
            ['id' => $admin->getKey(), 'hash' => sha1('otro@correo.com')],
        );

        $respuesta = $this->get($enlace);
        $respuesta->assertRedirect();
        $this->assertStringContainsString('verificacion=invalida', $respuesta->headers->get('Location'));

        $this->assertNull($admin->fresh()->email_verified_at);
        $this->assertFalse($this->tiendaReciente()->is_published);
    }

    public function test_usar_el_enlace_dos_veces_no_rompe_nada(): void
    {
        $this->altaDeTienda();

        $admin  = $this->adminReciente();
        $enlace = $this->enlaceDeVerificacion($admin);

        $this->get($enlace)->assertRedirect();
        $verificadoEn = $admin->fresh()->email_verified_at;

        // El segundo clic -o el prefetch del cliente de correo- no es un error.
        $respuesta = $this->get($enlace);
        $respuesta->assertRedirect();
        $this->assertStringContainsString('verificacion=ok', $respuesta->headers->get('Location'));

        $this->assertEquals($verificadoEn, $admin->fresh()->email_verified_at);
    }

    public function test_el_panel_puede_reenviar_el_correo_de_verificacion(): void
    {
        Notification::fake();

        $token = $this->altaDeTienda();
        $admin = $this->adminReciente();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant'      => 'tienda-nueva',
        ])->postJson('/api/auth/email/resend')
            ->assertOk()
            ->assertJsonPath('verified', false);

        // Dos veces: la del alta y la del reenvio.
        Notification::assertSentToTimes($admin, VerifyEmailNotification::class, 2);
    }

    public function test_reenviar_con_el_correo_ya_verificado_no_manda_nada(): void
    {
        [$tenant, $admin] = $this->tiendaVerificada();

        Notification::fake();

        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant'      => $tenant->slug,
        ])->postJson('/api/auth/email/resend')
            ->assertOk()
            ->assertJsonPath('verified', true);

        Notification::assertNothingSent();
    }

    public function test_reenviar_no_esta_abierto_sin_sesion(): void
    {
        $this->postJson('/api/auth/email/resend')->assertUnauthorized();
    }

    public function test_a_los_clientes_del_catalogo_no_se_les_pide_verificar(): void
    {
        [$tenant] = $this->tiendaVerificada();

        Notification::fake();

        $this->postJson("/api/public/{$tenant->slug}/auth/register", [
            'name'                  => 'Compradora',
            'email'                 => 'compradora@correo.com',
            'password'              => 'Contrasenia-larga-1',
            'password_confirmation' => 'Contrasenia-larga-1',
        ])->assertCreated();

        // `MustVerifyEmail` esta en `User`, que es tambien el modelo del
        // comprador. La friccion de verificar en mitad de una compra se pagaria
        // en ventas, y el correo no le abre ninguna puerta que no tuviera ya.
        Notification::assertNothingSent();
    }

    public function test_las_tiendas_que_ya_existian_siguen_publicadas(): void
    {
        // El `default(true)` de la columna es lo que evita un backfill aparte:
        // nadie ha podido verificar un correo que hasta hoy no se pedia, asi que
        // exigirlo hacia atras habria apagado todas las tiendas de produccion.
        //
        // `refresh()` porque el valor lo pone la BASE, no el modelo: recien
        // creado en memoria el atributo viene a null, y comprobarlo asi es
        // justamente comprobar el default de la columna y no el del codigo.
        [$tenant] = $this->tiendaVerificada();

        $this->assertTrue($tenant->refresh()->is_published);
        $this->getJson("/api/public/{$tenant->slug}")->assertOk();
    }
}
