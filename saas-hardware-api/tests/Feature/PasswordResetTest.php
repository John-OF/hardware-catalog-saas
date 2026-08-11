<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Recuperacion de contrasenia del panel (SAAS-2 / 7.2).
 *
 * Lo que fija este test, mas alla de "el flujo funciona":
 *
 * 1. Solo admins ACTIVOS reciben el enlace. Desde SEC-4 el correo es unico por
 *    tienda, asi que el mismo correo puede ser admin de una tienda y cliente de
 *    otra; sin filtrar por rol el broker resolveria al primero que encuentre y
 *    el enlace acabaria en la cuenta equivocada.
 * 2. La respuesta de /forgot-password es identica exista o no el correo, para
 *    que el endpoint no sirva de detector de tiendas registradas.
 * 3. Al resetear se revocan los tokens de Sanctum abiertos: quien pide un reset
 *    normalmente perdio el control de la cuenta.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Varios casos encadenan peticiones a /api/auth/* (throttle:5,1) y aqui
        // no estamos probando el rate limit.
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
    }

    private function makeUser(Tenant $tenant, string $email, string $role = 'admin', bool $isActive = true): User
    {
        $user = new User([
            'name'      => $role === 'admin' ? 'Duenio' : 'Cliente',
            'email'     => $email,
            'password'  => 'password123',
            'role'      => $role,
            'is_active' => $isActive,
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    /** Pide el enlace y devuelve el token que viajo en el correo. */
    private function requestResetToken(User $admin): string
    {
        $this->postJson('/api/auth/forgot-password', ['email' => $admin->email])
            ->assertOk();

        $token = null;
        Notification::assertSentTo($admin, ResetPasswordNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->assertNotNull($token, 'No se capturo el token del correo de recuperacion.');

        return $token;
    }

    public function test_admin_activo_recibe_el_enlace_de_recuperacion(): void
    {
        $admin = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');

        $this->postJson('/api/auth/forgot-password', ['email' => 'duenio@tienda-a.com'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        Notification::assertSentTo($admin, ResetPasswordNotification::class);
    }

    public function test_correo_inexistente_responde_igual_y_no_envia_nada(): void
    {
        $admin = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');

        $existente = $this->postJson('/api/auth/forgot-password', ['email' => 'duenio@tienda-a.com']);
        $inexistente = $this->postJson('/api/auth/forgot-password', ['email' => 'nadie@ejemplo.com']);

        $inexistente->assertOk();
        // Mismo cuerpo exacto: si difiriera, seria un detector de correos registrados.
        $this->assertSame($existente->json('message'), $inexistente->json('message'));

        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
        Notification::assertSentTo($admin, ResetPasswordNotification::class);
    }

    public function test_un_cliente_no_puede_pedir_reset_del_panel(): void
    {
        $cliente = $this->makeUser($this->tenantB, 'comprador@ejemplo.com', role: 'customer');

        $this->postJson('/api/auth/forgot-password', ['email' => 'comprador@ejemplo.com'])
            ->assertOk();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_con_el_mismo_correo_en_dos_roles_el_enlace_va_al_admin(): void
    {
        // Escenario habilitado por SEC-4: correo unico por tienda, no global.
        $admin = $this->makeUser($this->tenantA, 'repetido@ejemplo.com');
        $cliente = $this->makeUser($this->tenantB, 'repetido@ejemplo.com', role: 'customer');

        $this->postJson('/api/auth/forgot-password', ['email' => 'repetido@ejemplo.com'])
            ->assertOk();

        Notification::assertSentTo($admin, ResetPasswordNotification::class);
        Notification::assertNotSentTo($cliente, ResetPasswordNotification::class);
    }

    public function test_admin_suspendido_no_recibe_enlace(): void
    {
        $this->makeUser($this->tenantA, 'suspendido@tienda-a.com', isActive: false);

        $this->postJson('/api/auth/forgot-password', ['email' => 'suspendido@tienda-a.com'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_el_enlace_permite_fijar_una_nueva_contrasenia(): void
    {
        $admin = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');
        $token = $this->requestResetToken($admin);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'duenio@tienda-a.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email'    => 'duenio@tienda-a.com',
            'password' => 'nueva-clave-1234',
        ])->assertOk()->assertJsonStructure(['token', 'user', 'tenant']);

        $this->postJson('/api/auth/login', [
            'email'    => 'duenio@tienda-a.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_token_invalido_es_rechazado(): void
    {
        $admin = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');
        $this->requestResetToken($admin);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => 'token-inventado',
            'email'                 => 'duenio@tienda-a.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/auth/login', [
            'email'    => 'duenio@tienda-a.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_el_token_de_un_admin_no_sirve_para_otro(): void
    {
        $adminA = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');
        $adminB = $this->makeUser($this->tenantB, 'duenio@tienda-b.com');

        $tokenDeA = $this->requestResetToken($adminA);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $tokenDeA,
            'email'                 => 'duenio@tienda-b.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ])->assertStatus(422);

        // La contrasenia de B sigue siendo la suya.
        $this->postJson('/api/auth/login', [
            'email'    => 'duenio@tienda-b.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_el_token_se_consume_en_el_primer_uso(): void
    {
        $admin = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');
        $token = $this->requestResetToken($admin);

        $payload = [
            'token'                 => $token,
            'email'                 => 'duenio@tienda-a.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);
    }

    public function test_resetear_cierra_las_sesiones_abiertas(): void
    {
        $admin = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');
        $admin->createToken('spa-token', ['admin']);
        $this->assertSame(1, $admin->tokens()->count());

        $token = $this->requestResetToken($admin);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'duenio@tienda-a.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ])->assertOk();

        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_la_contrasenia_nueva_exige_confirmacion_y_minimo(): void
    {
        $admin = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');
        $token = $this->requestResetToken($admin);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'duenio@tienda-a.com',
            'password'              => 'corta',
            'password_confirmation' => 'corta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'duenio@tienda-a.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'otra-cosa-distinta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_el_enlace_del_correo_apunta_al_frontend(): void
    {
        config(['app.frontend_url' => 'https://panel.ejemplo.com/']);

        $admin = $this->makeUser($this->tenantA, 'duenio@tienda-a.com');

        $this->postJson('/api/auth/forgot-password', ['email' => 'duenio@tienda-a.com'])->assertOk();

        Notification::assertSentTo($admin, ResetPasswordNotification::class, function ($notification) use ($admin) {
            $url = $notification->toMail($admin)->actionUrl;

            $this->assertStringStartsWith('https://panel.ejemplo.com/reset-password?', $url);
            $this->assertStringContainsString('token='.$notification->token, $url);
            $this->assertStringContainsString('email='.urlencode($admin->email), $url);

            return true;
        });
    }
}
