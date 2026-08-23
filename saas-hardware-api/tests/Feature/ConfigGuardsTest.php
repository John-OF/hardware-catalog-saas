<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Guardas de configuracion y de cuenta (11.11 — AUD-12, AUD-13, AUD-17).
 *
 * Las tres son de la misma familia: cosas que hoy dependian de que el operador
 * se acordara —poner APP_DEBUG en false, no dejar el super-admin colgando de una
 * contrasenia, elegir una contrasenia que no este filtrada— y que ahora las
 * comprueba el propio sistema.
 */
class ConfigGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
    }

    /*
    |--------------------------------------------------------------------------
    | AUD-12 — APP_DEBUG
    |--------------------------------------------------------------------------
    */

    public function test_en_produccion_no_se_arranca_con_app_debug_activo(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_DEBUG/');

        (new \App\Providers\AppServiceProvider($this->app))->boot();
    }

    public function test_fuera_de_produccion_app_debug_no_estorba(): void
    {
        config(['app.debug' => true]);

        (new \App\Providers\AppServiceProvider($this->app))->boot();

        // Si hubiera saltado, el test no llegaria aqui.
        $this->assertFalse($this->app->isProduction());
    }

    /*
    |--------------------------------------------------------------------------
    | AUD-13 — cerca de IP del panel de plataforma
    |--------------------------------------------------------------------------
    */

    private function loginDePlataforma(string $ip): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/platform/login', [
                'email'    => 'operador@plataforma.com',
                'password' => 'password123',
            ]);
    }

    private function superAdmin(): User
    {
        $user = new User([
            'name'      => 'Operador',
            'email'     => 'operador@plataforma.com',
            'password'  => 'password123',
            'role'      => 'superadmin',
            'is_active' => true,
        ]);
        $user->tenant_id = null;
        $user->save();

        return $user;
    }

    public function test_el_login_de_plataforma_rechaza_una_ip_fuera_de_la_lista(): void
    {
        $this->superAdmin();
        config(['platform.allowed_ips' => ['203.0.113.10']]);

        // Credenciales BUENAS desde una IP mala: sigue siendo 403. Lo que se
        // comprueba es que la cerca va delante del login, que es donde alguien
        // probaria contrasenias.
        $this->loginDePlataforma('198.51.100.7')->assertStatus(403);
    }

    public function test_el_login_de_plataforma_deja_pasar_a_la_ip_autorizada(): void
    {
        $this->superAdmin();
        config(['platform.allowed_ips' => ['203.0.113.10']]);

        $this->loginDePlataforma('203.0.113.10')
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_la_lista_admite_rangos_cidr(): void
    {
        $this->superAdmin();
        config(['platform.allowed_ips' => ['203.0.113.0/24']]);

        $this->loginDePlataforma('203.0.113.200')->assertOk();
        $this->loginDePlataforma('198.51.100.7')->assertStatus(403);
    }

    public function test_las_rutas_autenticadas_de_plataforma_tambien_llevan_la_cerca(): void
    {
        $superAdmin = $this->superAdmin();
        config(['platform.allowed_ips' => ['203.0.113.10']]);

        $token = $superAdmin->createToken('test', ['superadmin'])->plainTextToken;

        // El token es valido; lo que falla es la IP.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/platform/tenants')
            ->assertStatus(403);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/platform/tenants')
            ->assertOk();
    }

    public function test_sin_lista_no_se_restringe_fuera_de_produccion(): void
    {
        $this->superAdmin();
        config(['platform.allowed_ips' => []]);

        $this->loginDePlataforma('198.51.100.7')->assertOk();
    }

    public function test_el_comodin_desactiva_la_restriccion_a_sabiendas(): void
    {
        $this->superAdmin();
        config(['platform.allowed_ips' => ['*']]);

        $this->loginDePlataforma('198.51.100.7')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | AUD-17 — politica de contrasenias
    |--------------------------------------------------------------------------
    */

    /** @param array<string, mixed> $extra */
    private function registrarTienda(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/register', array_merge([
            'store_name'            => 'Tienda Nueva',
            'slug'                  => 'tienda-nueva',
            'whatsapp'              => '51999999999',
            'name'                  => 'Duenio',
            'email'                 => 'duenio@tienda-nueva.com',
            'password'              => 'unaClaveLarga123',
            'password_confirmation' => 'unaClaveLarga123',
        ], $extra));
    }

    public function test_el_minimo_de_ocho_caracteres_sigue_en_pie(): void
    {
        $this->registrarTienda([
            'password'              => 'corta1',
            'password_confirmation' => 'corta1',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * El camino que AUD-17 pedia: una contrasenia que aparece en filtraciones se
     * rechaza. El verificador se sustituye por uno falso porque el de verdad
     * consulta Have I Been Pwned por HTTP, y un test no puede depender de que
     * haya internet ni de lo que hoy este en esa lista.
     */
    public function test_una_contrasenia_filtrada_se_rechaza_en_espaniol(): void
    {
        config(['auth.password_uncompromised' => true]);

        $this->app->bind(UncompromisedVerifier::class, fn () => new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return false;
            }
        });

        $respuesta = $this->registrarTienda([
            'password'              => 'password12345',
            'password_confirmation' => 'password12345',
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertStringContainsString(
            'filtraciones',
            $respuesta->json('errors.password.0'),
            'El mensaje de contrasenia filtrada llego sin traducir.'
        );
    }

    public function test_una_contrasenia_no_filtrada_pasa(): void
    {
        config(['auth.password_uncompromised' => true]);

        $this->app->bind(UncompromisedVerifier::class, fn () => new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return true;
            }
        });

        $this->registrarTienda()->assertCreated();
    }

    public function test_el_registro_de_cliente_usa_la_misma_politica(): void
    {
        $tenant = \App\Models\Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        config(['auth.password_uncompromised' => true]);

        $this->app->bind(UncompromisedVerifier::class, fn () => new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return false;
            }
        });

        $this->postJson("/api/public/{$tenant->slug}/auth/register", [
            'name'                  => 'Ana Compradora',
            'email'                 => 'ana@ejemplo.com',
            'password'              => 'password12345',
            'password_confirmation' => 'password12345',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
