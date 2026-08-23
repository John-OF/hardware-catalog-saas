<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Una acción masiva se ve en el catálogo al instante (AUD-6).
 *
 * `bulkAction` ocultaba productos con un `$query->update()`, y una actualización
 * masiva **no dispara los eventos del modelo**. La invalidación de la caché
 * pública vive justo ahí, en el hook `saved` de `Product`, así que el dueño
 * ocultaba 30 productos para una liquidación y seguían a la venta hasta 5
 * minutos. Lo peor no era la espera sino la incoherencia: ocultar uno suelto sí
 * se veía al momento, y desde el panel no había forma de entender por qué.
 */
class BulkActionCacheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-lotes',
            'name'            => 'Tienda Lotes',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->producto = new Product([
            'name'      => 'GPU en liquidacion',
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $this->producto->tenant_id = $this->tenant->id;
        $this->producto->save();

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    public function test_ocultar_en_lote_se_ve_en_el_catalogo_al_instante(): void
    {
        // Primero se deja el catálogo cacheado, que es la condición del fallo:
        // sin caché caliente no hay nada que invalidar y el test no probaría nada.
        $this->assertCount(1, $this->catalogo());

        $this->bulk('deactivate')->assertOk();

        $this->assertCount(0, $this->catalogo(), 'El producto oculto seguia sirviendose desde cache.');
    }

    public function test_publicar_en_lote_tambien_se_ve_al_instante(): void
    {
        $this->bulk('deactivate')->assertOk();
        $this->assertCount(0, $this->catalogo());

        $this->bulk('activate')->assertOk();

        $this->assertCount(1, $this->catalogo());
    }

    /**
     * Control: las ramas que guardan producto a producto ya funcionaban, porque
     * pasan por el modelo y disparan el hook. Si esto se rompe es que alguien ha
     * cambiado `adjust_price` a un `update()` masivo sin invalidar.
     */
    public function test_ajustar_precios_en_lote_tambien_se_refleja(): void
    {
        $this->assertSame('100.00', $this->catalogo()[0]['price']);

        $this->bulk('adjust_price', ['price_adjustment' => 10])->assertOk();

        $this->assertSame('110.00', $this->catalogo()[0]['price']);
    }

    // ------------------------------------------------------------------ apoyo

    /** @return array<int, array<string, mixed>> */
    private function catalogo(): array
    {
        return $this->getJson("/api/public/{$this->tenant->slug}/products")
            ->assertStatus(200)
            ->json('data');
    }

    /** @param array<string, mixed> $extra */
    private function bulk(string $accion, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant'      => $this->tenant->slug,
        ])->postJson('/api/products/bulk', array_merge([
            'product_ids' => [$this->producto->id],
            'bulk_action' => $accion,
        ], $extra));
    }
}
