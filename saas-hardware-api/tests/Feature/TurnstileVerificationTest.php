<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fija la verificacion anti-bot de las resenias publicas (SEC-5).
 *
 * El fallo original: la clave se leia con env('TURNSTILE_SECRET_KEY', '1x0000...AA').
 * Con config:cache, env() devuelve null en runtime y se caia a ese segundo argumento,
 * que es la clave de PRUEBA de Cloudflare y aprueba cualquier token. Es decir, el
 * anti-bot quedaba desactivado en produccion sin que nada lo indicara.
 *
 * El entorno de tests es 'testing' (no 'local'), asi que por defecto estos casos
 * ejercen la ruta estricta, que es la de produccion.
 */
class TurnstileVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // La ruta de resenias lleva throttle; aqui no probamos el rate limit.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-turnstile',
            'name'            => 'Tienda Turnstile',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->product = new Product([
            'name'      => 'GPU',
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $this->product->tenant_id = $this->tenant->id;
        $this->product->save();
    }

    private function postReview(string $token = 'token-de-prueba'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            "/api/public/{$this->tenant->slug}/products/{$this->product->id}/reviews",
            [
                'customer_name'   => 'Comprador',
                'rating'          => 5,
                'comment'         => 'Excelente',
                'turnstile_token' => $token,
                'visitor_id'      => 'visitante-1',
            ]
        );
    }

    public function test_token_valido_publica_la_resenia(): void
    {
        config(['services.turnstile.secret' => 'clave-real']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->postReview()->assertStatus(201);

        $this->assertSame(1, Review::withoutGlobalScopes()->count());
    }

    public function test_token_invalido_es_rechazado(): void
    {
        config(['services.turnstile.secret' => 'clave-real']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $response = $this->postReview();

        $response->assertStatus(422);
        $this->assertSame(0, Review::withoutGlobalScopes()->count());
    }

    /**
     * El caso que reproduce el bug: sin clave configurada (lo que ocurria con
     * config:cache) NO se debe aprobar. Antes se caia a la clave de prueba.
     */
    public function test_sin_clave_configurada_fuera_de_local_se_rechaza(): void
    {
        config(['services.turnstile.secret' => null]);
        // Si algo intentara llamar a Cloudflare, que no cuele como aprobado.
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $response = $this->postReview();

        $response->assertStatus(502);
        $this->assertSame(0, Review::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_se_envia_la_clave_de_config_y_no_un_valor_por_defecto(): void
    {
        config(['services.turnstile.secret' => 'clave-de-config']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->postReview('token-abc')->assertStatus(201);

        Http::assertSent(function ($request) {
            return $request['secret'] === 'clave-de-config'
                && $request['response'] === 'token-abc'
                // La clave de prueba de Cloudflare no debe aparecer nunca.
                && $request['secret'] !== '1x0000000000000000000000000000000AA';
        });
    }

    public function test_error_de_red_fuera_de_local_se_rechaza(): void
    {
        config(['services.turnstile.secret' => 'clave-real']);
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('sin red');
        });

        $response = $this->postReview();

        $response->assertStatus(502);
        $this->assertSame(0, Review::withoutGlobalScopes()->count());
    }

    public function test_error_de_red_en_local_no_traba_las_pruebas(): void
    {
        $this->app['env'] = 'local';
        config(['services.turnstile.secret' => 'clave-real']);
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('sin red');
        });

        $this->postReview()->assertStatus(201);

        $this->assertSame(1, Review::withoutGlobalScopes()->count());
    }

    public function test_sin_clave_en_local_se_omite_la_verificacion(): void
    {
        $this->app['env'] = 'local';
        config(['services.turnstile.secret' => null]);
        Http::fake();

        $this->postReview()->assertStatus(201);

        Http::assertNothingSent();
    }
}
