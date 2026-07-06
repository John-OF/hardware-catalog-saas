<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'tiendapresencial',
            'name' => 'Tienda Presencial',
            'logo_url' => 'https://example.com/logo.png',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
        ]);

        app()->instance('currentTenant', $this->tenant);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Teclado Mecanico RGB',
            'price' => 79.99,
            'stock' => 15,
            'status' => 'published',
            'is_active' => true,
        ]);
    }

    public function test_customer_can_register(): void
    {
        $response = $this->postJson("/api/public/tiendapresencial/auth/register", [
            'name' => 'Comprador Uno',
            'email' => 'comprador@gmail.com',
            'phone' => '+5491122334455',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['token', 'user']);
        $this->assertDatabaseHas('users', [
            'email' => 'comprador@gmail.com',
            'phone' => '+5491122334455',
            'role' => 'customer',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_customer_registration_duplicate_email_fails_within_same_tenant(): void
    {
        // Crear un usuario previo
        $user = new User([
            'name' => 'Comprador Existente',
            'email' => 'duplicate@gmail.com',
            'password' => 'password123',
            'role' => 'customer',
        ]);
        $user->tenant_id = $this->tenant->id;
        $user->save();

        $response = $this->postJson("/api/public/tiendapresencial/auth/register", [
            'name' => 'Otro Nombre',
            'email' => 'duplicate@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_customer_can_login_with_valid_credentials(): void
    {
        $user = new User([
            'name' => 'Comprador Login',
            'email' => 'login@gmail.com',
            'password' => 'secret123',
            'role' => 'customer',
        ]);
        $user->tenant_id = $this->tenant->id;
        $user->save();

        $response = $this->postJson("/api/public/tiendapresencial/auth/login", [
            'email' => 'login@gmail.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user']);
    }

    public function test_customer_cannot_login_with_invalid_credentials(): void
    {
        $response = $this->postJson("/api/public/tiendapresencial/auth/login", [
            'email' => 'wrong@gmail.com',
            'password' => 'wrongpass',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_authenticated_customer_can_retrieve_profile_and_logout(): void
    {
        $user = new User([
            'name' => 'Comprador Perfil',
            'email' => 'perfil@gmail.com',
            'password' => 'secret123',
            'role' => 'customer',
        ]);
        $user->tenant_id = $this->tenant->id;
        $user->save();

        $token = $user->createToken('customer-token')->plainTextToken;

        // Validar ruta me
        $responseMe = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/public/tiendapresencial/auth/me");

        $responseMe->assertStatus(200);
        $responseMe->assertJsonPath('user.email', 'perfil@gmail.com');

        // Validar ruta logout
        $responseLogout = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/public/tiendapresencial/auth/logout");

        $responseLogout->assertStatus(200);
        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_order_creation_associates_authenticated_user_id(): void
    {
        $user = new User([
            'name' => 'Comprador Pedido',
            'email' => 'pedido@gmail.com',
            'password' => 'secret123',
            'role' => 'customer',
        ]);
        $user->tenant_id = $this->tenant->id;
        $user->save();

        $token = $user->createToken('customer-token')->plainTextToken;

        $responseOrder = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/public/tiendapresencial/orders", [
                'customer_name' => 'Comprador Pedido',
                'customer_phone' => '123456789',
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 2,
                    ]
                ]
            ]);

        $responseOrder->assertStatus(201);
        $orderId = $responseOrder->json('id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
        ]);

        // Validar que aparece en su historial
        $responseHistory = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/public/tiendapresencial/my-orders");

        $responseHistory->assertStatus(200);
        $responseHistory->assertJsonCount(1);
        $responseHistory->assertJsonPath('0.id', $orderId);
    }

    public function test_customer_can_toggle_favorites_and_list_them(): void
    {
        $user = new User([
            'name' => 'Comprador Favoritos',
            'email' => 'favoritos@gmail.com',
            'password' => 'secret123',
            'role' => 'customer',
        ]);
        $user->tenant_id = $this->tenant->id;
        $user->save();

        $token = $user->createToken('customer-token')->plainTextToken;

        // Listado inicial vacío
        $responseListEmpty = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/public/tiendapresencial/favorites");
        $responseListEmpty->assertStatus(200);
        $responseListEmpty->assertJsonCount(0);

        // Agregar a favoritos
        $responseToggleAdd = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/public/tiendapresencial/favorites/{$this->product->id}");
        
        $responseToggleAdd->assertStatus(200);
        $responseToggleAdd->assertJsonPath('favorited', true);

        // Listado ahora con 1 favorito
        $responseListWithOne = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/public/tiendapresencial/favorites");
        $responseListWithOne->assertStatus(200);
        $responseListWithOne->assertJsonCount(1);
        $responseListWithOne->assertJsonPath('0.id', $this->product->id);

        // Remover de favoritos
        $responseToggleRemove = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/public/tiendapresencial/favorites/{$this->product->id}");
        
        $responseToggleRemove->assertStatus(200);
        $responseToggleRemove->assertJsonPath('favorited', false);

        // Listado vacío de nuevo
        $responseListEmptyAgain = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/public/tiendapresencial/favorites");
        $responseListEmptyAgain->assertStatus(200);
        $responseListEmptyAgain->assertJsonCount(0);
    }
}
