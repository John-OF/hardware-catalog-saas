<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CustomerResetPasswordNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Recuperación de contraseña del CLIENTE del catálogo (FUN-11).
 *
 * Hermano de `PasswordResetTest` (la del panel), y con el mismo riesgo
 * multiplicado: el correo de un admin es único en TODA la plataforma
 * (`FUN-14`), pero el de un cliente sólo es único DENTRO de su tienda
 * (`SEC-4`). El caso que de verdad importa aquí es el que `PasswordResetTest`
 * ya cubría para admin/cliente y que aquí se repite entre dos TIENDAS: el
 * mismo correo como cliente de A y de B, y que el reset de una no toque la
 * otra.
 */
class CustomerPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        Notification::fake();

        $this->tenantA = Tenant::create([
            'slug' => 'tienda-a', 'name' => 'Tienda A',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug' => 'tienda-b', 'name' => 'Tienda B',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);
    }

    private function makeCliente(Tenant $tenant, string $email, bool $isActive = true): User
    {
        $user = new User([
            'name' => 'Cliente', 'email' => $email,
            'password' => 'password123', 'role' => 'customer', 'is_active' => $isActive,
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    private function makeAdmin(Tenant $tenant, string $email): User
    {
        $user = new User([
            'name' => 'Dueño', 'email' => $email,
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    /** Pide el enlace en la tienda dada y devuelve el token del correo. */
    private function requestResetToken(string $slug, User $cliente): string
    {
        $this->postJson("/api/public/{$slug}/auth/forgot-password", ['email' => $cliente->email])
            ->assertOk();

        $token = null;
        Notification::assertSentTo($cliente, CustomerResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->assertNotNull($token, 'No se capturó el token del correo de recuperación.');

        return $token;
    }

    public function test_un_cliente_activo_recibe_el_enlace(): void
    {
        $cliente = $this->makeCliente($this->tenantA, 'comprador@ejemplo.com');

        $this->postJson('/api/public/tienda-a/auth/forgot-password', ['email' => 'comprador@ejemplo.com'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        Notification::assertSentTo($cliente, CustomerResetPasswordNotification::class);
    }

    public function test_correo_inexistente_responde_igual_y_no_envia_nada(): void
    {
        $cliente = $this->makeCliente($this->tenantA, 'comprador@ejemplo.com');

        $existente   = $this->postJson('/api/public/tienda-a/auth/forgot-password', ['email' => 'comprador@ejemplo.com']);
        $inexistente = $this->postJson('/api/public/tienda-a/auth/forgot-password', ['email' => 'nadie@ejemplo.com']);

        $inexistente->assertOk();
        $this->assertSame($existente->json('message'), $inexistente->json('message'));

        Notification::assertSentTimes(CustomerResetPasswordNotification::class, 1);
    }

    /**
     * El caso que de verdad hace falta cerrar: el mismo correo es cliente de
     * DOS tiendas distintas. Sin `tenant_id` entre las credenciales del
     * broker, el correo de A podría acabar resolviendo a la cuenta de B (o al
     * revés, según el orden de la tabla).
     */
    public function test_con_el_mismo_correo_en_dos_tiendas_el_enlace_va_a_la_tienda_correcta(): void
    {
        $clienteA = $this->makeCliente($this->tenantA, 'repetido@ejemplo.com');
        $clienteB = $this->makeCliente($this->tenantB, 'repetido@ejemplo.com');

        $this->postJson('/api/public/tienda-a/auth/forgot-password', ['email' => 'repetido@ejemplo.com'])
            ->assertOk();

        Notification::assertSentTo($clienteA, CustomerResetPasswordNotification::class);
        Notification::assertNotSentTo($clienteB, CustomerResetPasswordNotification::class);
    }

    /**
     * Y el otro cruce posible: el mismo correo es ADMIN de una tienda y
     * CLIENTE de otra (SEC-4 lo permite explícitamente). Pedir el reset desde
     * el catálogo de la tienda donde es cliente no debe tocar la cuenta de
     * admin de la otra.
     */
    public function test_con_el_mismo_correo_como_admin_en_otra_tienda_el_enlace_va_al_cliente(): void
    {
        $admin   = $this->makeAdmin($this->tenantB, 'repetido@ejemplo.com');
        $cliente = $this->makeCliente($this->tenantA, 'repetido@ejemplo.com');

        $this->postJson('/api/public/tienda-a/auth/forgot-password', ['email' => 'repetido@ejemplo.com'])
            ->assertOk();

        Notification::assertSentTo($cliente, CustomerResetPasswordNotification::class);
        Notification::assertNotSentTo($admin, CustomerResetPasswordNotification::class);
        Notification::assertNotSentTo($admin, ResetPasswordNotification::class);
    }

    public function test_cliente_desactivado_no_recibe_enlace(): void
    {
        $this->makeCliente($this->tenantA, 'suspendido@ejemplo.com', isActive: false);

        $this->postJson('/api/public/tienda-a/auth/forgot-password', ['email' => 'suspendido@ejemplo.com'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_el_enlace_permite_fijar_una_nueva_contrasenia(): void
    {
        $cliente = $this->makeCliente($this->tenantA, 'comprador@ejemplo.com');
        $token = $this->requestResetToken('tienda-a', $cliente);

        $this->postJson('/api/public/tienda-a/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'comprador@ejemplo.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ])->assertOk();

        $this->postJson('/api/public/tienda-a/auth/login', [
            'email'    => 'comprador@ejemplo.com',
            'password' => 'nueva-clave-1234',
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $this->postJson('/api/public/tienda-a/auth/login', [
            'email'    => 'comprador@ejemplo.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    /**
     * Límite conocido y aceptado, no un fallo: `password_reset_tokens` guarda
     * el token por CORREO, no por cuenta — es la tabla nativa de Laravel, y
     * reusarla es justo lo que pide `FUN-11` ("el mismo broker"). Con el mismo
     * correo en dos tiendas, un token pedido desde A también vale para B: quien
     * lo tiene ya controla el buzón que recibe los dos enlaces, así que no se
     * salta ningún control que no pudiera saltarse pidiendo el reset de B
     * directamente. Lo que SÍ hace falta es que reinicie la cuenta correcta
     * -la de la tienda del endpoint que se llama, no la de quien lo emitió-, y
     * eso es lo que fija este test.
     */
    public function test_el_token_del_mismo_correo_reinicia_la_cuenta_de_la_tienda_del_endpoint(): void
    {
        $clienteA = $this->makeCliente($this->tenantA, 'repetido@ejemplo.com');
        $clienteB = $this->makeCliente($this->tenantB, 'repetido@ejemplo.com');
        $clienteB->createToken('customer-token', ['customer']);

        $tokenDeA = $this->requestResetToken('tienda-a', $clienteA);

        $this->postJson('/api/public/tienda-b/auth/reset-password', [
            'token'                 => $tokenDeA,
            'email'                 => 'repetido@ejemplo.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ])->assertOk();

        // La sesión abierta de B se cerró: el reset operó sobre SU cuenta.
        $this->assertSame(0, $clienteB->fresh()->tokens()->count());

        // Cambió la cuenta de B (la del endpoint), no la de A.
        $this->postJson('/api/public/tienda-b/auth/login', [
            'email' => 'repetido@ejemplo.com', 'password' => 'nueva-clave-1234',
        ])->assertOk();

        $this->postJson('/api/public/tienda-a/auth/login', [
            'email' => 'repetido@ejemplo.com', 'password' => 'password123',
        ])->assertOk();
    }

    public function test_token_invalido_es_rechazado(): void
    {
        $cliente = $this->makeCliente($this->tenantA, 'comprador@ejemplo.com');
        $this->requestResetToken('tienda-a', $cliente);

        $this->postJson('/api/public/tienda-a/auth/reset-password', [
            'token'                 => 'token-inventado',
            'email'                 => 'comprador@ejemplo.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_el_token_se_consume_en_el_primer_uso(): void
    {
        $cliente = $this->makeCliente($this->tenantA, 'comprador@ejemplo.com');
        $token = $this->requestResetToken('tienda-a', $cliente);

        $payload = [
            'token'                 => $token,
            'email'                 => 'comprador@ejemplo.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ];

        $this->postJson('/api/public/tienda-a/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/public/tienda-a/auth/reset-password', $payload)->assertStatus(422);
    }

    public function test_resetear_cierra_las_sesiones_abiertas(): void
    {
        $cliente = $this->makeCliente($this->tenantA, 'comprador@ejemplo.com');
        $cliente->createToken('customer-token', ['customer']);
        $this->assertSame(1, $cliente->tokens()->count());

        $token = $this->requestResetToken('tienda-a', $cliente);

        $this->postJson('/api/public/tienda-a/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'comprador@ejemplo.com',
            'password'              => 'nueva-clave-1234',
            'password_confirmation' => 'nueva-clave-1234',
        ])->assertOk();

        $this->assertSame(0, $cliente->tokens()->count());
    }

    public function test_el_enlace_del_correo_apunta_al_catalogo_y_no_al_panel(): void
    {
        config(['app.frontend_url' => 'https://tienda.ejemplo.com']);

        $cliente = $this->makeCliente($this->tenantA, 'comprador@ejemplo.com');

        $this->postJson('/api/public/tienda-a/auth/forgot-password', ['email' => 'comprador@ejemplo.com'])->assertOk();

        Notification::assertSentTo($cliente, CustomerResetPasswordNotification::class, function ($n) use ($cliente) {
            $url = $n->toMail($cliente)->actionUrl;

            // La URL con slug, NO la del panel (`/reset-password` a secas).
            $this->assertStringStartsWith('https://tienda.ejemplo.com/tienda-a?', $url);
            $this->assertStringContainsString('reset_token='.$n->token, $url);
            $this->assertStringContainsString('reset_email='.urlencode($cliente->email), $url);

            return true;
        });
    }
}
