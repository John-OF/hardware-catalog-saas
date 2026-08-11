<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Venta de mostrador desde el panel (OWN-3 / 7.5).
 *
 * Antes `OrderController` no tenia metodo `store`, aunque `apiResource`
 * registraba la ruta POST: invocarla daba un 500. Las ventas presenciales no
 * descontaban stock por ninguna via, asi que el inventario del sistema se iba
 * separando del real.
 *
 * Lo que fija este test:
 *
 * 1. El total lo calcula el servidor, con el precio de oferta cuando existe, e
 *    ignorando cualquier precio que venga en la peticion.
 * 2. Crear la venta como "atendida" descuenta stock en el acto; como
 *    "pendiente" no lo toca hasta que se marque atendida.
 * 3. No se puede vender un producto de otra tienda.
 */
class CounterSaleTest extends TestCase
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
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-a.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();

        $this->producto = $this->makeProduct($this->tenant, 'Kingston Fury 16GB', price: 45.50, stock: 10);
    }

    private function makeProduct(Tenant $tenant, string $name, float $price, int $stock, ?float $salePrice = null, bool $isActive = true): Product
    {
        $product = new Product([
            'name'       => $name,
            'price'      => $price,
            'sale_price' => $salePrice,
            'stock'      => $stock,
            'is_active'  => $isActive,
            'status'     => 'published',
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    private function asAdmin(): static
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ]);
    }

    public function test_registra_una_venta_de_mostrador_y_descuenta_stock(): void
    {
        $this->asAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente de mostrador',
            'status'        => 'attended',
            'items'         => [
                ['product_id' => $this->producto->id, 'quantity' => 3],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'attended')
            ->assertJsonPath('total', '136.50');

        $this->assertSame(7, $this->producto->fresh()->stock);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_una_venta_pendiente_no_toca_el_stock_hasta_atenderla(): void
    {
        $respuesta = $this->asAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente que reserva',
            'status'        => 'pending',
            'items'         => [
                ['product_id' => $this->producto->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $this->assertSame(10, $this->producto->fresh()->stock);

        $this->asAdmin()
            ->putJson('/api/orders/'.$respuesta->json('id'), ['status' => 'attended'])
            ->assertOk();

        $this->assertSame(8, $this->producto->fresh()->stock);
    }

    public function test_el_telefono_es_opcional_en_el_mostrador(): void
    {
        $this->asAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente sin telefono',
            'status'        => 'attended',
            'items'         => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseHas('orders', [
            'customer_name'  => 'Cliente sin telefono',
            'customer_phone' => null,
        ]);
    }

    public function test_el_total_lo_calcula_el_servidor_y_usa_el_precio_de_oferta(): void
    {
        $enOferta = $this->makeProduct($this->tenant, 'GPU rebajada', price: 500, stock: 5, salePrice: 399.99);

        $this->asAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente',
            'status'        => 'attended',
            // Precios inventados en la petición: se ignoran.
            'total'         => 1,
            'items'         => [
                ['product_id' => $enOferta->id, 'quantity' => 2, 'unit_price' => 1],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('total', '799.98');
    }

    public function test_permite_vender_un_producto_despublicado(): void
    {
        // A diferencia del catálogo público: el dueño vende lo que tiene
        // físicamente aunque ya no lo exhiba.
        $retirado = $this->makeProduct($this->tenant, 'Modelo descontinuado', price: 20, stock: 4, isActive: false);

        $this->asAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente',
            'status'        => 'attended',
            'items'         => [['product_id' => $retirado->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(3, $retirado->fresh()->stock);
    }

    public function test_no_se_puede_vender_un_producto_de_otra_tienda(): void
    {
        $otraTienda = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);
        $ajeno = $this->makeProduct($otraTienda, 'Producto ajeno', price: 99, stock: 9);

        $this->asAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente',
            'status'        => 'attended',
            'items'         => [['product_id' => $ajeno->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(9, $ajeno->fresh()->stock);
    }

    public function test_exige_cliente_y_al_menos_un_producto(): void
    {
        $this->asAdmin()->postJson('/api/orders', [
            'status' => 'attended',
            'items'  => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'items']);
    }

    public function test_no_se_puede_crear_una_venta_ya_cancelada(): void
    {
        $this->asAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente',
            'status'        => 'cancelled',
            'items'         => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_borrar_una_venta_atendida_devuelve_el_stock(): void
    {
        $respuesta = $this->asAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente arrepentido',
            'status'        => 'attended',
            'items'         => [['product_id' => $this->producto->id, 'quantity' => 4]],
        ])->assertCreated();

        $this->assertSame(6, $this->producto->fresh()->stock);

        $this->asAdmin()
            ->deleteJson('/api/orders/'.$respuesta->json('id'))
            ->assertNoContent();

        $this->assertSame(10, $this->producto->fresh()->stock);
    }
}
