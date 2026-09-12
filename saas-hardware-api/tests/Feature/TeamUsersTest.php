<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Una tienda puede tener más de una persona en el panel (FUN-4). Lo que puede
 * hacer cada rol en el resto del panel está en `StaffRoleTest`.
 *
 * Antes, el único usuario con acceso era el que nacía en el alta. El dueño con
 * un vendedor le pasaba su propia contraseña, y como el login **borra los tokens
 * anteriores** —una sesión activa por usuario— los dos se echaban mutuamente
 * todo el día.
 *
 * Lo que más se vigila aquí es el **aislamiento**: `User` es la excepción al
 * fallo en cerrado de `AUD-4` —sin tienda resuelta su global scope lo ve todo—,
 * así que los `where('tenant_id')` del controlador están escritos a mano aunque
 * en el panel el scope también filtre. Si alguno se cae, estos tests lo cazan.
 * Y al revés: la comprobación de "este correo ya es del panel de otra tienda"
 * (FUN-14) tiene que saltarse el scope a propósito, o no ve nada.
 */
class TeamUsersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;
    private Tenant $otraTienda;
    private User $duenio;

    /**
     * El guard por defecto ANTES de la primera petición.
     *
     * Se guarda aquí porque `auth:sanctum` lo sobreescribe en la configuración
     * al autenticar (`Auth::shouldUse('sanctum')`), así que leerlo más tarde
     * devuelve ya el corrompido y restaurarlo con ese valor no restaura nada.
     */
    private string $guardOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->guardOriginal = (string) config('auth.defaults.guard');

        $this->tienda = Tenant::create([
            'slug'            => 'tienda-equipo',
            'name'            => 'Tienda Equipo',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->otraTienda = Tenant::create([
            'slug'            => 'tienda-vecina',
            'name'            => 'Tienda Vecina',
            'whatsapp_number' => '51988888888',
            'is_active'       => true,
        ]);

        $this->duenio = $this->crearUsuario($this->tienda, 'duenio@equipo.test', 'admin');

        // Plan sin tope de usuarios salvo que un test diga lo contrario.
        config()->set('plans.default', 'test');
        config()->set('plans.plans', ['test' => ['label' => 'Test', 'limits' => ['users' => null]]]);
    }

    // ------------------------------------------------------------- ver equipo

    public function test_el_duenio_ve_a_su_equipo(): void
    {
        $this->crearUsuario($this->tienda, 'vendedor@equipo.test', 'admin');

        $correos = array_column(
            $this->comoDuenio()->getJson('/api/users')->assertOk()->json(),
            'email'
        );

        $this->assertEqualsCanonicalizing(['duenio@equipo.test', 'vendedor@equipo.test'], $correos);
    }

    /**
     * Los clientes del catálogo viven en la misma tabla `users`. Mezclarlos con
     * el equipo convertiría esta pantalla en un listado de compradores.
     */
    public function test_los_clientes_del_catalogo_no_son_equipo(): void
    {
        $this->crearUsuario($this->tienda, 'comprador@equipo.test', 'customer');

        $correos = array_column($this->comoDuenio()->getJson('/api/users')->assertOk()->json(), 'email');

        $this->assertSame(['duenio@equipo.test'], $correos);
    }

    public function test_no_se_ve_el_equipo_de_otra_tienda(): void
    {
        $this->crearUsuario($this->otraTienda, 'ajeno@vecina.test', 'admin');

        $correos = array_column($this->comoDuenio()->getJson('/api/users')->assertOk()->json(), 'email');

        $this->assertSame(['duenio@equipo.test'], $correos);
    }

    // -------------------------------------------------------------- invitar

    public function test_invitar_crea_al_usuario_y_le_manda_el_enlace(): void
    {
        $respuesta = $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Vendedor Nuevo',
            'email' => 'vendedor@equipo.test',
        ])->assertCreated();

        $this->assertTrue($respuesta->json('invitation_pending'));

        $invitado = User::where('email', 'vendedor@equipo.test')->firstOrFail();
        $this->assertSame($this->tienda->id, $invitado->tenant_id);
        // Sin decir rol, `staff`: dar poder de admin tiene que ser una decisión.
        $this->assertSame('staff', $invitado->role);

        Notification::assertSentTo(
            $invitado,
            TeamInvitationNotification::class,
            fn ($notificacion) => $notificacion->tienda === 'Tienda Equipo'
                && $notificacion->invitadoPor === $this->duenio->name
                && $notificacion->token !== ''
        );
    }

    /**
     * El invitado entra con la contraseña que elige él, no con una que le hayan
     * dictado por WhatsApp. Se comprueba el camino entero: el token del correo
     * abre el reset de `SAAS-2` y con esa contraseña ya se puede entrar.
     */
    public function test_el_invitado_elige_su_contrasenia_y_entra(): void
    {
        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Vendedor Nuevo',
            'email' => 'vendedor@equipo.test',
        ])->assertCreated();

        $invitado = User::where('email', 'vendedor@equipo.test')->firstOrFail();
        $token = $this->tokenDeLaInvitacion($invitado);

        // Este caso pasa por tres identidades distintas (el dueño, nadie, el
        // invitado), así que hay que soltar la anterior. Ver `desdeCero()`.
        $this->desdeCero();

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'vendedor@equipo.test',
            'password'              => 'ClaveDelVendedor#2026',
            'password_confirmation' => 'ClaveDelVendedor#2026',
        ])->assertOk();

        $this->desdeCero();

        $this->postJson('/api/auth/login', [
            'email'    => 'vendedor@equipo.test',
            'password' => 'ClaveDelVendedor#2026',
        ])->assertOk()->assertJsonPath('tenant.slug', 'tienda-equipo');
    }

    /**
     * Quien ya ha entrado deja de figurar como invitacion pendiente.
     *
     * Parece una tonteria y es el caso que destapo un fallo de meses: la columna
     * `last_login_at` **nunca se escribia**. Los dos logins hacian
     * `update(['last_login_at' => now()])` y `last_login_at` no esta en
     * `$fillable`, asi que la asignacion masiva lo descartaba en silencio. No se
     * noto porque nadie leia esa columna hasta esta pantalla.
     */
    public function test_quien_ya_ha_entrado_no_figura_como_invitacion_pendiente(): void
    {
        $this->duenio->forceFill(['password' => 'ClaveDelDuenio#2026'])->save();

        $this->postJson('/api/auth/login', [
            'email'    => $this->duenio->email,
            'password' => 'ClaveDelDuenio#2026',
        ])->assertOk();

        $this->assertNotNull($this->duenio->fresh()->last_login_at, 'El login no registro la entrada.');

        $this->desdeCero();

        $equipo = $this->comoDuenio()->getJson('/api/users')->assertOk()->json();

        $this->assertFalse($equipo[0]['invitation_pending']);
    }

    public function test_se_puede_invitar_como_admin_si_se_pide(): void
    {
        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Socia',
            'email' => 'socia@equipo.test',
            'role'  => 'admin',
        ])->assertCreated()->assertJsonPath('role', 'admin');

        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Nadie',
            'email' => 'nadie@equipo.test',
            'role'  => 'superadmin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_el_correo_no_se_repite_dentro_de_la_tienda(): void
    {
        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Alguien',
            'email' => 'repetido@ejemplo.test',
        ])->assertCreated();

        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Otro',
            'email' => 'repetido@ejemplo.test',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * FUN-14. Este test decía antes lo contrario —que se podía invitar a quien
     * ya es admin de otra tienda— y fijaba un fallo: el login, el reset y el
     * enlace de la propia invitación resuelven el correo sin saber la tienda y
     * se quedan con la cuenta más antigua. Aceptar la invitación le cambiaba la
     * contraseña de la OTRA tienda, y la cuenta de aquí no podía entrar nunca.
     */
    public function test_no_se_puede_invitar_a_quien_ya_es_del_panel_de_otra_tienda(): void
    {
        $ajeno = $this->crearUsuario($this->otraTienda, 'repetido@ejemplo.test', 'staff');

        $respuesta = $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Alguien',
            'email' => 'repetido@ejemplo.test',
        ])->assertStatus(422);

        $this->assertStringContainsString('otra tienda', $respuesta->json('errors.email.0'));
        $this->assertSame(1, User::where('email', 'repetido@ejemplo.test')->count());
        $this->assertSame('staff', $ajeno->fresh()->role);
    }

    /** Ser CLIENTE de otra tienda sí se permite: el login del panel no mira clientes. */
    public function test_se_puede_invitar_a_un_cliente_de_otra_tienda(): void
    {
        $this->crearUsuario($this->otraTienda, 'comprador@vecina.test', 'customer');

        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Vendedor',
            'email' => 'comprador@vecina.test',
        ])->assertCreated();
    }

    /**
     * Aceptar la invitación demuestra que el buzón es suyo, así que el correo
     * queda verificado. Sin esto al invitado le salía para siempre el aviso de
     * "confirma tu correo"… y si lo confirmaba, se publicaba la tienda.
     */
    public function test_aceptar_la_invitacion_verifica_el_correo_sin_publicar_la_tienda(): void
    {
        $this->tienda->update(['is_published' => false]);

        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Vendedor Nuevo',
            'email' => 'vendedor@equipo.test',
        ])->assertCreated();

        $invitado = User::where('email', 'vendedor@equipo.test')->firstOrFail();
        $token = $this->tokenDeLaInvitacion($invitado);

        $this->desdeCero();

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'vendedor@equipo.test',
            'password'              => 'ClaveDelVendedor#2026',
            'password_confirmation' => 'ClaveDelVendedor#2026',
        ])->assertOk();

        $this->assertTrue($invitado->fresh()->hasVerifiedEmail());
        // Staff verifica SU correo, no decide abrir la tienda.
        $this->assertFalse($this->tienda->fresh()->is_published);
    }

    // ------------------------------------------------------------ cambiar rol

    public function test_cambiar_el_rol_le_cierra_las_sesiones(): void
    {
        $vendedor = $this->crearUsuario($this->tienda, 'vendedor@equipo.test', 'staff');
        $vendedor->createToken('test', ['staff']);

        $this->comoDuenio()->putJson("/api/users/{$vendedor->id}", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('role', 'admin');

        $this->assertSame(0, $vendedor->tokens()->count());
    }

    public function test_nadie_puede_cambiarse_su_propio_rol(): void
    {
        $this->crearUsuario($this->tienda, 'socia@equipo.test', 'admin');

        $this->comoDuenio()->putJson("/api/users/{$this->duenio->id}", ['role' => 'staff'])
            ->assertStatus(422);

        $this->assertSame('admin', $this->duenio->fresh()->role);
    }

    /**
     * Un admin puede bajar a otro. Que la tienda no se quede sin admins lo
     * garantiza que nadie pueda bajarse a sí mismo: quien baja a otro sigue
     * siendo admin.
     */
    public function test_un_admin_puede_bajar_a_otro_a_staff(): void
    {
        $socia = $this->crearUsuario($this->tienda, 'socia@equipo.test', 'admin');

        $this->comoDuenio()->putJson("/api/users/{$socia->id}", ['role' => 'staff'])
            ->assertOk()->assertJsonPath('role', 'staff');

        $this->assertSame('staff', $socia->fresh()->role);
        $this->assertSame('admin', $this->duenio->fresh()->role);
    }

    /**
     * El mensaje dice POR QUE esta ocupado el correo.
     *
     * El caso salio probando a mano: el dueno eligio una direccion que el
     * navegador le sugeria, y el panel le dijo "ya hay alguien con ese correo"
     * mientras en su equipo no habia nadie. Era un CLIENTE de su catalogo, que
     * vive en la misma tabla.
     */
    public function test_dice_cuando_el_correo_lo_usa_un_cliente_del_catalogo(): void
    {
        $this->crearUsuario($this->tienda, 'comprador@equipo.test', 'customer');

        $respuesta = $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Vendedor',
            'email' => 'comprador@equipo.test',
        ])->assertStatus(422);

        $this->assertStringContainsString('cliente registrado', $respuesta->json('errors.email.0'));
    }

    public function test_el_tope_de_usuarios_del_plan_se_aplica(): void
    {
        config()->set('plans.plans.test.limits.users', 2);

        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Segundo',
            'email' => 'segundo@equipo.test',
        ])->assertCreated();

        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Tercero',
            'email' => 'tercero@equipo.test',
        ])->assertStatus(422)->assertJsonPath('limit_key', 'users');
    }

    /**
     * Los clientes del catálogo no gastan hueco del plan: están en la misma
     * tabla, pero una tienda con 500 compradores registrados no puede quedarse
     * sin poder invitar a su vendedor.
     */
    public function test_los_clientes_no_gastan_el_tope_de_usuarios(): void
    {
        config()->set('plans.plans.test.limits.users', 2);

        $this->crearUsuario($this->tienda, 'comprador1@equipo.test', 'customer');
        $this->crearUsuario($this->tienda, 'comprador2@equipo.test', 'customer');
        $this->crearUsuario($this->tienda, 'comprador3@equipo.test', 'customer');

        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Segundo',
            'email' => 'segundo@equipo.test',
        ])->assertCreated();
    }

    /** Y los de otra tienda tampoco, que es el fallo que `User` invita a cometer. */
    public function test_el_equipo_de_otra_tienda_no_gasta_el_tope(): void
    {
        config()->set('plans.plans.test.limits.users', 2);

        $this->crearUsuario($this->otraTienda, 'a@vecina.test', 'admin');
        $this->crearUsuario($this->otraTienda, 'b@vecina.test', 'admin');

        $this->comoDuenio()->postJson('/api/users', [
            'name'  => 'Segundo',
            'email' => 'segundo@equipo.test',
        ])->assertCreated();
    }

    // ------------------------------------------------------- quitar el acceso

    public function test_desactivar_le_cierra_la_puerta_y_las_sesiones(): void
    {
        $vendedor = $this->crearUsuario($this->tienda, 'vendedor@equipo.test', 'admin');
        $tokenDelVendedor = $vendedor->createToken('test', ['admin'])->plainTextToken;

        $this->comoDuenio()->putJson("/api/users/{$vendedor->id}", ['is_active' => false])->assertOk();

        $this->assertFalse($vendedor->fresh()->is_active);
        $this->assertSame(0, $vendedor->tokens()->count());

        // Y con su token de antes tampoco entra, aunque lo tuviera copiado.
        $this->desdeCero();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$tokenDelVendedor,
            'X-Tenant'      => $this->tienda->slug,
        ])->getJson('/api/products')->assertUnauthorized();
    }

    public function test_nadie_puede_quitarse_a_si_mismo_el_acceso(): void
    {
        $this->comoDuenio()->putJson("/api/users/{$this->duenio->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->assertTrue($this->duenio->fresh()->is_active);
    }

    /**
     * Dos personas desactivándose la una a la otra dejarían el panel cerrado
     * para todos, y recuperarlo exige al operador de la plataforma.
     */
    public function test_la_tienda_no_puede_quedarse_sin_ningun_admin_activo(): void
    {
        $vendedor = $this->crearUsuario($this->tienda, 'vendedor@equipo.test', 'admin');
        $this->duenio->update(['is_active' => false]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$vendedor->createToken('t', ['admin'])->plainTextToken,
            'X-Tenant'      => $this->tienda->slug,
        ])->deleteJson("/api/users/{$vendedor->id}")->assertStatus(422);

        $this->assertNotNull($vendedor->fresh());
    }

    public function test_no_se_puede_tocar_al_equipo_de_otra_tienda(): void
    {
        $ajeno = $this->crearUsuario($this->otraTienda, 'ajeno@vecina.test', 'admin');

        $this->comoDuenio()->putJson("/api/users/{$ajeno->id}", ['is_active' => false])->assertNotFound();
        $this->comoDuenio()->deleteJson("/api/users/{$ajeno->id}")->assertNotFound();

        $this->assertTrue($ajeno->fresh()->is_active);
    }

    // ------------------------------------------------------------ auxiliares

    /**
     * Olvida la sesión ya resuelta y las cabeceras de la petición anterior.
     *
     * Hace falta cada vez que un caso cambia de identidad, porque dentro de un
     * mismo test **la aplicación se reutiliza entre peticiones**: el guard
     * conserva el usuario que ya resolvió y `auth:sanctum` deja `sanctum` como
     * guard por defecto, así que un `Auth::attempt` posterior revienta. Es un
     * efecto del entorno de pruebas, no del producto — en producción cada
     * petición arranca la aplicación de cero.
     *
     * Sin esto, la segunda petición contesta como la primera y el test pasa en
     * verde comprobando algo que no es lo que cree.
     */
    private function desdeCero(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse($this->guardOriginal);
    }

    private function comoDuenio(): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->duenio->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant'      => $this->tienda->slug,
        ]);
    }

    private function crearUsuario(Tenant $tienda, string $email, string $rol): User
    {
        $usuario = new User([
            'name'      => 'Usuario '.$email,
            'email'     => $email,
            'password'  => 'secret1234',
            'role'      => $rol,
            'is_active' => true,
        ]);
        $usuario->tenant_id = $tienda->id;
        $usuario->save();

        return $usuario;
    }

    /** El token que viajó en el correo de invitación. */
    private function tokenDeLaInvitacion(User $invitado): string
    {
        $token = null;

        Notification::assertSentTo($invitado, TeamInvitationNotification::class, function ($notificacion) use (&$token) {
            $token = $notificacion->token;

            return true;
        });

        return (string) $token;
    }
}
