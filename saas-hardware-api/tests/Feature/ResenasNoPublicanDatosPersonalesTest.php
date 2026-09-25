<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `ACC-1`. La ficha publica de producto (sin token) devolvia de cada resena
 * aprobada `customer_email`, `user_id` y `visitor_id`: recorriendo los ids del
 * catalogo salia el correo de todos los clientes que habian resenado. Y con un
 * `visitor_id` sacado de ahi, `user_review` entregaba la resena PENDIENTE de esa
 * persona en otro producto. `Review::$hidden` cierra las dos puertas; el panel
 * destapa solo el correo.
 */
class ResenasNoPublicanDatosPersonalesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Product $product;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-resenas',
            'name'            => 'Tienda Resenas',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->product = new Product([
            'name' => 'GPU', 'price' => 100, 'stock' => 5, 'status' => 'published', 'is_active' => true,
        ]);
        $this->product->tenant_id = $this->tenant->id;
        $this->product->save();

        $this->admin = new User([
            'name' => 'Duenio', 'email' => 'duenio@resenas.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    private function resena(bool $aprobada, string $visitor, string $correo): Review
    {
        $r = new Review([
            'product_id'     => $this->product->id,
            'visitor_id'     => $visitor,
            'customer_name'  => 'Ana',
            'customer_email' => $correo,
            'rating'         => 5,
            'comment'        => 'Muy buena',
            'is_approved'    => $aprobada,
        ]);
        $r->tenant_id = $this->tenant->id;
        $r->save();

        return $r;
    }

    private function fichaPublica(array $cabeceras = [])
    {
        return $this->withHeaders($cabeceras)
            ->getJson("/api/public/{$this->tenant->slug}/products/{$this->product->id}");
    }

    public function test_la_ficha_publica_no_devuelve_datos_personales_de_las_resenas(): void
    {
        $this->resena(true, 'visitante-ana', 'ana@privado.test');

        $respuesta = $this->fichaPublica()->assertOk();

        $this->assertSame('Ana', $respuesta->json('reviews.0.customer_name'), 'La resena aprobada si tiene que verse.');
        $this->assertStringNotContainsString('ana@privado.test', $respuesta->getContent());
        $this->assertStringNotContainsString('visitante-ana', $respuesta->getContent());

        foreach (['customer_email', 'visitor_id', 'user_id'] as $campo) {
            $this->assertArrayNotHasKey($campo, $respuesta->json('reviews.0'), "La ficha publica expone {$campo}.");
        }
    }

    public function test_la_propia_resena_pendiente_sigue_viendose_sin_datos_personales(): void
    {
        $this->resena(false, 'visitante-ana', 'ana@privado.test');

        $respuesta = $this->fichaPublica(['X-Visitor-Id' => 'visitante-ana'])->assertOk();

        $this->assertFalse($respuesta->json('user_review.is_approved'));
        $this->assertSame('Muy buena', $respuesta->json('user_review.comment'));
        $this->assertArrayNotHasKey('customer_email', $respuesta->json('user_review'));
        $this->assertArrayNotHasKey('visitor_id', $respuesta->json('user_review'));
    }

    public function test_publicar_una_resena_no_devuelve_el_correo_ni_el_visitor_id(): void
    {
        config(['services.turnstile.secret' => 'clave-real']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $respuesta = $this->postJson("/api/public/{$this->tenant->slug}/products/{$this->product->id}/reviews", [
            'customer_name'  => 'Luis',
            'customer_email' => 'luis@privado.test',
            'rating'         => 4,
            'visitor_id'     => 'visitante-luis',
            'turnstile_token' => 'token-de-prueba',
        ])->assertStatus(201);

        $this->assertStringNotContainsString('luis@privado.test', $respuesta->getContent());
        $this->assertStringNotContainsString('visitante-luis', $respuesta->getContent());
        $this->assertArrayNotHasKey('tenant', $respuesta->json('review'), 'La respuesta publica lleva la fila de la tienda.');

        $this->assertSame('luis@privado.test', Review::withoutGlobalScopes()->first()?->getAttributes()['customer_email'] ?? null,
            'El correo tiene que seguir guardandose: solo deja de publicarse.');
    }

    public function test_el_panel_sigue_viendo_el_correo(): void
    {
        $r = $this->resena(false, 'visitante-ana', 'ana@privado.test');

        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;
        $cabeceras = ['Authorization' => 'Bearer '.$token, 'X-Tenant' => $this->tenant->slug];

        $this->withHeaders($cabeceras)->getJson('/api/reviews')
            ->assertOk()
            ->assertJsonPath('data.0.customer_email', 'ana@privado.test');

        $this->app['auth']->forgetGuards();

        $this->withHeaders($cabeceras)->putJson("/api/reviews/{$r->id}", ['is_approved' => true])
            ->assertOk()
            ->assertJsonPath('customer_email', 'ana@privado.test');
    }
}
