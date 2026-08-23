<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Un token de cliente vale en SU tienda y en ninguna otra (AUD-3).
 *
 * La raíz del problema es que en las rutas públicas la tienda viene del slug de
 * la URL, no del header `X-Tenant`, y `auth:sanctum` solo comprueba que el token
 * exista. Como el registro de clientes es abierto, conseguir un token de "algún
 * usuario" no cuesta nada: basta con darse de alta en la tienda propia.
 *
 * Lo grave era la reseña. Con ese token salían **publicadas directamente** en el
 * catálogo de la competencia, saltándose la moderación del dueño, que es la
 * única barrera contra el spam y la difamación. Turnstile no lo impide: al otro
 * lado hay un humano con cuenta de verdad.
 */
class CrossTenantCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tiendaA;

    private Tenant $tiendaB;

    private Product $productoDeB;

    private User $clienteDeA;

    private User $clienteDeB;

    protected function setUp(): void
    {
        parent::setUp();

        // Aquí no se prueba el rate limit; lo cubre PublicRateLimitTest.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tiendaA = $this->makeTenant('tienda-a');
        $this->tiendaB = $this->makeTenant('tienda-b');

        $this->productoDeB = $this->makeProduct($this->tiendaB, 'GPU de la tienda B');

        $this->clienteDeA = $this->makeUser($this->tiendaA, 'cliente-a@example.com', 'customer');
        $this->clienteDeB = $this->makeUser($this->tiendaB, 'cliente-b@example.com', 'customer');
    }

    // ---------------------------------------------------------------- reseñas

    public function test_una_resenia_con_token_de_otra_tienda_queda_pendiente(): void
    {
        $respuesta = $this->postReview($this->tokenDe($this->clienteDeA));

        // No se rechaza: una reseña anónima es legítima. Lo que no se le concede
        // es la confianza de un cliente de la casa.
        $respuesta->assertStatus(201);

        $review = Review::withoutGlobalScopes()->sole();

        $this->assertFalse((bool) $review->is_approved, 'La resenia de otra tienda no deberia auto-aprobarse.');

        // Y no queda colgada de un usuario de otra tienda: sería una fila de la
        // tienda B apuntando a un usuario de la A.
        $this->assertNull($review->user_id);
        $this->assertSame($this->tiendaB->id, $review->tenant_id);
    }

    public function test_una_resenia_del_cliente_de_la_tienda_si_se_auto_aprueba(): void
    {
        $this->postReview($this->tokenDe($this->clienteDeB))->assertStatus(201);

        $review = Review::withoutGlobalScopes()->sole();

        $this->assertTrue((bool) $review->is_approved);
        $this->assertSame($this->clienteDeB->id, $review->user_id);
    }

    /**
     * El rol también cuenta: el dueño reseñando sus propios productos con sello
     * de cliente es el patrón de reseña falsa. Puede publicar, pero pasando por
     * su propia moderación.
     */
    public function test_el_admin_de_la_tienda_no_auto_aprueba_su_resenia(): void
    {
        $admin = $this->makeUser($this->tiendaB, 'duenio@example.com', 'admin');

        $this->postReview($this->tokenDe($admin))->assertStatus(201);

        $this->assertFalse((bool) Review::withoutGlobalScopes()->sole()->is_approved);
    }

    public function test_una_resenia_anonima_sigue_quedando_pendiente(): void
    {
        $this->postReview(null)->assertStatus(201);

        $this->assertFalse((bool) Review::withoutGlobalScopes()->sole()->is_approved);
    }

    // --------------------------------------- rutas con sesión de cliente obligatoria

    /**
     * El hermano menor del hallazgo: favoritos no comprobaba nada y llegaba a
     * escribir en la pivote desde otra tienda.
     */
    public function test_favoritos_rechaza_el_token_de_otra_tienda(): void
    {
        $this->conToken($this->tokenDe($this->clienteDeA))
            ->postJson("/api/public/{$this->tiendaB->slug}/favorites/{$this->productoDeB->id}")
            ->assertStatus(401);

        $this->assertSame(0, DB::table('user_favorites')->count(), 'No deberia haber escrito en la pivote.');
    }

    public function test_el_resto_de_rutas_de_cliente_tambien_rechazan_el_token_ajeno(): void
    {
        $token = $this->tokenDe($this->clienteDeA);

        foreach (['favorites', 'my-orders', 'auth/me'] as $ruta) {
            $this->conToken($token)
                ->getJson("/api/public/{$this->tiendaB->slug}/{$ruta}")
                ->assertStatus(401, "La ruta {$ruta} deberia rechazar un token de otra tienda.");
        }
    }

    /**
     * Control: el cierre no puede haberse llevado por delante el uso legítimo.
     */
    public function test_el_cliente_de_la_tienda_sigue_entrando_con_normalidad(): void
    {
        $token = $this->tokenDe($this->clienteDeB);
        $base = "/api/public/{$this->tiendaB->slug}";

        $this->conToken($token)->getJson("{$base}/auth/me")->assertStatus(200);
        $this->conToken($token)->getJson("{$base}/my-orders")->assertStatus(200);
        $this->conToken($token)->postJson("{$base}/favorites/{$this->productoDeB->id}")
            ->assertStatus(200)
            ->assertJsonPath('favorited', true);

        $this->assertSame(1, DB::table('user_favorites')->count());
    }

    // ------------------------------------------------------------------ apoyo

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'slug'            => $slug,
            'name'            => ucfirst($slug),
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);
    }

    private function makeProduct(Tenant $tenant, string $name): Product
    {
        $product = new Product([
            'name'      => $name,
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    private function makeUser(Tenant $tenant, string $email, string $role): User
    {
        $user = new User([
            'name'      => $email,
            'email'     => $email,
            'password'  => 'password123',
            'role'      => $role,
            'is_active' => true,
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    private function tokenDe(User $user): string
    {
        return $user->createToken('customer-token', ['customer'])->plainTextToken;
    }

    private function conToken(string $token): self
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    /**
     * Publica una reseña en el producto de la tienda B, opcionalmente con token.
     */
    private function postReview(?string $token): TestResponse
    {
        // Turnstile se da por superado: lo que se prueba aquí es a quién se cree
        // DESPUES de pasarlo, que es justo lo que Turnstile no puede distinguir.
        config(['services.turnstile.secret' => 'clave-real']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $test = $token !== null ? $this->conToken($token) : $this;

        return $test->postJson(
            "/api/public/{$this->tiendaB->slug}/products/{$this->productoDeB->id}/reviews",
            [
                'customer_name'   => 'Quien sea',
                'rating'          => 1,
                'comment'         => 'La competencia es malisima',
                'turnstile_token' => 'token-ok',
            ]
        );
    }
}
