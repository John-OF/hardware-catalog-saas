<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fija el aislamiento multi-tenant y el control de rol del panel (SEC-1 / TEC-1).
 *
 * - Un cliente (role 'customer') NO puede usar las rutas del panel de administración.
 * - Un cliente NO puede loguearse por el endpoint de admin.
 * - Un administrador SÍ opera con normalidad.
 * - El catálogo público de una tienda no expone datos de otra.
 *
 * Estos casos deben fallar si se revierte 6.1 (middleware EnsureAdmin + login discriminado).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private User $customerA;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'slug' => 'tienda-a',
            'name' => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug' => 'tienda-b',
            'name' => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active' => true,
        ]);

        $this->adminA = $this->makeUser($this->tenantA, 'admin@tienda-a.com', 'admin');
        $this->customerA = $this->makeUser($this->tenantA, 'cliente@tienda-a.com', 'customer');

        $this->productA = $this->makeProduct($this->tenantA, 'Producto de A');
        $this->productB = $this->makeProduct($this->tenantB, 'Producto de B');
    }

    private function makeUser(Tenant $tenant, string $email, string $role): User
    {
        $user = new User([
            'name'      => "Usuario {$role}",
            'email'     => $email,
            'password'  => 'password123',
            'role'      => $role,
            'is_active' => true,
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    private function makeProduct(Tenant $tenant, string $name): Product
    {
        // tenant_id está en $guarded (lo asigna el trait desde currentTenant);
        // aquí lo fijamos explícitamente porque hay dos tenants en juego.
        $product = new Product([
            'name'      => $name,
            'price'     => 100,
            'stock'     => 10,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    private function tokenFor(User $user, string $name, array $abilities): string
    {
        return $user->createToken($name, $abilities)->plainTextToken;
    }

    /**
     * @return array<int, array{0: string, 1: string}> pares [método, uri]
     */
    public static function adminEndpoints(): array
    {
        return [
            'listar productos'   => ['getJson', '/api/products'],
            'crear producto'     => ['postJson', '/api/products'],
            'ver tenant'         => ['getJson', '/api/tenant'],
            'editar tenant'      => ['putJson', '/api/tenant'],
            'stats dashboard'    => ['getJson', '/api/dashboard/stats'],
            'listar pedidos'     => ['getJson', '/api/orders'],
            'listar categorias'  => ['getJson', '/api/categories'],
            'listar resenas'     => ['getJson', '/api/reviews'],
        ];
    }

    #[DataProvider('adminEndpoints')]
    public function test_cliente_no_puede_usar_rutas_del_panel(string $method, string $uri): void
    {
        $token = $this->tokenFor($this->customerA, 'customer-token', ['customer']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Tenant'      => $this->tenantA->slug,
        ])->{$method}($uri);

        $response->assertStatus(403);
    }

    public function test_cliente_no_puede_editar_ni_borrar_productos_del_panel(): void
    {
        $token = $this->tokenFor($this->customerA, 'customer-token', ['customer']);
        $headers = [
            'Authorization' => "Bearer $token",
            'X-Tenant'      => $this->tenantA->slug,
        ];

        $this->withHeaders($headers)
            ->putJson("/api/products/{$this->productA->id}", ['name' => 'Hackeado'])
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->deleteJson("/api/products/{$this->productA->id}")
            ->assertStatus(403);

        // El producto no fue alterado ni eliminado
        $this->assertDatabaseHas('products', [
            'id'   => $this->productA->id,
            'name' => 'Producto de A',
        ]);
    }

    public function test_cliente_no_puede_loguearse_en_el_panel_admin(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => $this->customerA->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_admin_activo_puede_operar_el_panel(): void
    {
        $token = $this->tokenFor($this->adminA, 'spa-token', ['admin']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Tenant'      => $this->tenantA->slug,
        ])->getJson('/api/products');

        $response->assertStatus(200);
    }

    public function test_admin_puede_loguearse_en_el_panel(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => $this->adminA->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user', 'tenant']);
    }

    public function test_admin_inactivo_no_puede_loguearse(): void
    {
        $this->adminA->update(['is_active' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $this->adminA->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_admin_no_puede_editar_producto_de_otro_tenant(): void
    {
        $token = $this->tokenFor($this->adminA, 'spa-token', ['admin']);

        // Admin de A, con su propio X-Tenant, intenta tocar un recurso de B por id.
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Tenant'      => $this->tenantA->slug,
        ])->putJson("/api/products/{$this->productB->id}", [
            'name'   => 'Secuestrado',
            'price'  => 1,
            'stock'  => 0,
        ]);

        $response->assertStatus(404);

        $this->assertDatabaseHas('products', [
            'id'   => $this->productB->id,
            'name' => 'Producto de B',
        ]);
    }

    public function test_admin_no_puede_borrar_producto_de_otro_tenant(): void
    {
        $token = $this->tokenFor($this->adminA, 'spa-token', ['admin']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Tenant'      => $this->tenantA->slug,
        ])->deleteJson("/api/products/{$this->productB->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('products', ['id' => $this->productB->id]);
    }

    public function test_admin_no_puede_borrar_categoria_de_otro_tenant(): void
    {
        $categoriaB = new Category(['name' => 'Categoria de B']);
        $categoriaB->tenant_id = $this->tenantB->id;
        $categoriaB->save();

        $token = $this->tokenFor($this->adminA, 'spa-token', ['admin']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Tenant'      => $this->tenantA->slug,
        ])->deleteJson("/api/categories/{$categoriaB->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('categories', [
            'id'   => $categoriaB->id,
            'name' => 'Categoria de B',
        ]);
    }

    public function test_catalogo_publico_de_a_no_expone_productos_de_b(): void
    {
        $response = $this->getJson("/api/public/{$this->tenantA->slug}/products");

        $response->assertStatus(200);

        $names = collect($response->json('data') ?? $response->json())
            ->pluck('name')
            ->all();

        $this->assertContains('Producto de A', $names);
        $this->assertNotContains('Producto de B', $names);
    }
}
