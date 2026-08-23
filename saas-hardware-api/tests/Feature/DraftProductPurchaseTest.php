<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Un producto despublicado no se puede comprar desde el catálogo (AUD-5).
 *
 * El carrito **persiste en el navegador**, así que el escenario no es raro: el
 * dueño pasa un producto a borrador para dejar de venderlo, y un comprador que
 * lo tenía guardado de ayer envía el pedido. Entraba igual, al precio viejo, y
 * el dueño recibía un pedido de algo que había retirado.
 *
 * `OrderPricing` miraba `is_active` pero no `status`, mientras que el catálogo
 * público exige los dos para mostrar un producto: la puerta de salida era más
 * ancha que el escaparate.
 */
class DraftProductPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $borrador;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-borradores',
            'name'            => 'Tienda Borradores',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->borrador = $this->makeProduct('GPU retirada', 'draft');

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

    public function test_el_checkout_publico_rechaza_un_producto_en_borrador(): void
    {
        $respuesta = $this->postJson("/api/public/{$this->tenant->slug}/orders", [
            'customer_name'  => 'Comprador con carrito viejo',
            'customer_phone' => '123456789',
            'items'          => [
                ['product_id' => $this->borrador->id, 'quantity' => 1],
            ],
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonValidationErrors('items');

        $this->assertSame(0, \App\Models\Order::withoutTenant()->count());
    }

    public function test_un_producto_publicado_se_sigue_comprando(): void
    {
        $publicado = $this->makeProduct('GPU a la venta', 'published');

        $this->postJson("/api/public/{$this->tenant->slug}/orders", [
            'customer_name'  => 'Comprador',
            'customer_phone' => '123456789',
            'items'          => [
                ['product_id' => $publicado->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);
    }

    /**
     * La otra mitad, que es la que hace que el arreglo sea correcto y no solo
     * restrictivo: el dueño SÍ puede vender de mostrador algo despublicado,
     * porque lo tiene físicamente delante. Para eso existe `soloVisibles`, y si
     * este test empieza a fallar es que el filtro se ha puesto donde no era.
     */
    public function test_el_dueno_si_puede_venderlo_de_mostrador(): void
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant'      => $this->tenant->slug,
        ])->postJson('/api/orders', [
            'customer_name' => 'Cliente de mostrador',
            'status'        => 'attended',
            'items'         => [
                ['product_id' => $this->borrador->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);
    }

    private function makeProduct(string $name, string $status): Product
    {
        $product = new Product([
            'name'      => $name,
            'price'     => 100,
            'stock'     => 5,
            'status'    => $status,
            'is_active' => true,
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        return $product;
    }
}
