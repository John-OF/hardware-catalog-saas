<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Verificación del dominio propio (FUN-6).
 *
 * Hasta ahora `custom_domain` era texto libre con un `unique`: nada comprobaba
 * que el dominio fuera de verdad de quien lo escribía, y quien llegara primero
 * se lo quedaba para siempre, verificado o no. Aquí se fija lo contrario: un
 * dominio nuevo pide un token, no sirve para nada hasta demostrar el registro
 * TXT, y un dominio abandonado (pedido y nunca verificado) se puede liberar.
 */
class CustomDomainVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tienda = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
            'is_published'    => true,
            'plan'            => 'pro',
        ]);

        $this->admin = new User([
            'name' => 'Dueño', 'email' => 'duenio@tienda-a.com',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tienda->id;
        $this->admin->save();
    }

    private function asAdmin(): static
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tienda->slug,
        ]);
    }

    /** Sustituye el verificador real por uno de mentira que contesta lo que se le diga. */
    private function conVerificador(bool $tieneElRegistro): void
    {
        $this->mock(DomainVerifier::class, function ($mock) use ($tieneElRegistro) {
            $mock->shouldReceive('tieneRegistroTxt')->andReturn($tieneElRegistro);
        });
    }

    // ------------------------------------------------------------ pedirlo

    public function test_pedir_un_dominio_genera_token_y_no_lo_deja_verificado(): void
    {
        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com'])
            ->assertOk()
            ->assertJsonPath('custom_domain', 'midominio.com')
            ->assertJsonPath('custom_domain_verified_at', null);

        $this->tienda->refresh();
        $this->assertNotNull($this->tienda->custom_domain_token);
        $this->assertNotNull($this->tienda->custom_domain_requested_at);
        $this->assertNull($this->tienda->custom_domain_verified_at);
    }

    public function test_reenviar_el_mismo_dominio_no_reinicia_el_token(): void
    {
        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com'])->assertOk();
        $tokenOriginal = $this->tienda->fresh()->custom_domain_token;

        // Guardar otra cosa sin tocar el dominio: el progreso de verificación
        // no debe perderse.
        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com', 'name' => 'Tienda A (v2)'])
            ->assertOk();

        $this->assertSame($tokenOriginal, $this->tienda->fresh()->custom_domain_token);
    }

    public function test_vaciar_el_dominio_borra_tambien_su_verificacion(): void
    {
        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com'])->assertOk();

        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => null])->assertOk();

        $this->tienda->refresh();
        $this->assertNull($this->tienda->custom_domain);
        $this->assertNull($this->tienda->custom_domain_token);
        $this->assertNull($this->tienda->custom_domain_requested_at);
        $this->assertNull($this->tienda->custom_domain_verified_at);
    }

    // -------------------------------------------------------- verificarlo

    public function test_verificar_con_el_txt_puesto_marca_el_dominio_como_verificado(): void
    {
        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com'])->assertOk();
        $this->conVerificador(tieneElRegistro: true);

        $this->asAdmin()->postJson('/api/tenant/custom-domain/verify')
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->assertNotNull($this->tienda->fresh()->custom_domain_verified_at);
    }

    public function test_verificar_sin_el_txt_puesto_no_marca_nada(): void
    {
        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com'])->assertOk();
        $this->conVerificador(tieneElRegistro: false);

        $this->asAdmin()->postJson('/api/tenant/custom-domain/verify')
            ->assertStatus(422)
            ->assertJsonPath('verified', false);

        $this->assertNull($this->tienda->fresh()->custom_domain_verified_at);
    }

    public function test_no_se_puede_verificar_sin_haber_pedido_ningun_dominio(): void
    {
        $this->asAdmin()->postJson('/api/tenant/custom-domain/verify')->assertStatus(422);
    }

    // ------------------------------------------------------ ocupado / squatting

    public function test_no_se_puede_pedir_el_dominio_de_otra_tienda_recien_pedido(): void
    {
        $otra = Tenant::create([
            'slug' => 'tienda-b', 'name' => 'Tienda B',
            'whatsapp_number' => '51888888888', 'is_active' => true, 'plan' => 'pro',
        ]);
        $otra->forceFill(['custom_domain' => 'midominio.com', 'custom_domain_requested_at' => now()])->save();

        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_domain');

        $this->assertNull($this->tienda->fresh()->custom_domain);
    }

    public function test_un_dominio_abandonado_mas_de_24_horas_se_puede_reclamar(): void
    {
        $otra = Tenant::create([
            'slug' => 'tienda-b', 'name' => 'Tienda B',
            'whatsapp_number' => '51888888888', 'is_active' => true, 'plan' => 'pro',
        ]);
        $otra->forceFill([
            'custom_domain'              => 'midominio.com',
            'custom_domain_token'        => 'token-viejo',
            'custom_domain_requested_at' => now()->subHours(25),
        ])->save();

        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com'])
            ->assertOk()
            ->assertJsonPath('custom_domain', 'midominio.com');

        // A la tienda vieja se le suelta el dominio y su verificación, para no
        // chocar con el UNIQUE de la columna.
        $this->assertNull($otra->fresh()->custom_domain);
        $this->assertNull($otra->fresh()->custom_domain_token);
    }

    public function test_un_dominio_abandonado_pero_ya_verificado_no_se_puede_reclamar(): void
    {
        $otra = Tenant::create([
            'slug' => 'tienda-b', 'name' => 'Tienda B',
            'whatsapp_number' => '51888888888', 'is_active' => true, 'plan' => 'pro',
        ]);
        // Verificado hace mucho: el plazo de abandono sólo aplica a quien
        // nunca demostró nada, no a quien lo demostró y no volvió a tocarlo.
        $otra->forceFill([
            'custom_domain'              => 'midominio.com',
            'custom_domain_requested_at' => now()->subDays(90),
            'custom_domain_verified_at'  => now()->subDays(90),
        ])->save();

        $this->asAdmin()->putJson('/api/tenant', ['custom_domain' => 'midominio.com'])
            ->assertStatus(422);

        $this->assertSame('midominio.com', $otra->fresh()->custom_domain);
    }

    // ------------------------------------------------------ resolución pública

    public function test_el_catalogo_publico_no_resuelve_un_dominio_sin_verificar(): void
    {
        $this->tienda->forceFill([
            'custom_domain'              => 'midominio.com',
            'custom_domain_requested_at' => now(),
        ])->save();

        $this->getJson('/api/public/resolve-domain?domain=midominio.com')->assertStatus(404);
    }

    public function test_el_catalogo_publico_resuelve_un_dominio_ya_verificado(): void
    {
        $this->tienda->forceFill([
            'custom_domain'              => 'midominio.com',
            'custom_domain_requested_at' => now(),
            'custom_domain_verified_at'  => now(),
        ])->save();

        $this->getJson('/api/public/resolve-domain?domain=midominio.com')
            ->assertOk()
            ->assertJsonPath('slug', 'tienda-a');
    }
}
