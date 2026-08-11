<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Aviso al dueño de cada pedido nuevo (OWN-2 / 7.3).
 *
 * Lo que fija este test:
 *
 * 1. El aviso llega a los admins ACTIVOS de esa tienda y a nadie mas: ni a los
 *    admins de otra tienda, ni a los clientes registrados, ni a los suspendidos.
 * 2. El correo lleva el detalle real del pedido (items, cantidades y el total
 *    calculado en servidor, que puede no coincidir con lo que mando el cliente).
 * 3. Si el mailer falla, el pedido SIGUE creandose y devolviendo 201. Es la
 *    parte que mas importa: el comprador no puede perder su pedido porque el
 *    SMTP del operador este caido.
 */
class NewOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        Notification::fake();

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = $this->makeUser($this->tenant, 'duenio@tienda-a.com');
        $this->producto = $this->makeProduct($this->tenant, 'Kingston Fury 16GB', 45.50);
    }

    private function makeUser(Tenant $tenant, string $email, string $role = 'admin', bool $isActive = true): User
    {
        $user = new User([
            'name'      => 'Duenio de '.$tenant->name,
            'email'     => $email,
            'password'  => 'password123',
            'role'      => $role,
            'is_active' => $isActive,
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    private function makeProduct(Tenant $tenant, string $name, float $price): Product
    {
        // tenant_id se asigna a mano porque esta en $guarded, no en $fillable.
        $product = new Product([
            'name'      => $name,
            'price'     => $price,
            'stock'     => 10,
            'is_active' => true,
            'status'    => 'published',
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    private function placeOrder(Tenant $tenant, Product $product, int $quantity = 2): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/public/{$tenant->slug}/orders", [
            'customer_name'  => 'Ana Compradora',
            'customer_phone' => '51988877766',
            'items'          => [
                ['product_id' => $product->id, 'quantity' => $quantity],
            ],
        ]);
    }

    public function test_el_admin_de_la_tienda_recibe_el_aviso(): void
    {
        $this->placeOrder($this->tenant, $this->producto)->assertCreated();

        Notification::assertSentTo($this->admin, NewOrderNotification::class);
    }

    public function test_el_aviso_no_sale_de_la_tienda(): void
    {
        $otraTienda = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);
        $adminAjeno = $this->makeUser($otraTienda, 'duenio@tienda-b.com');

        $this->placeOrder($this->tenant, $this->producto)->assertCreated();

        Notification::assertSentTo($this->admin, NewOrderNotification::class);
        Notification::assertNotSentTo($adminAjeno, NewOrderNotification::class);
    }

    public function test_ni_clientes_ni_admins_suspendidos_reciben_el_aviso(): void
    {
        $cliente = $this->makeUser($this->tenant, 'comprador@ejemplo.com', role: 'customer');
        $suspendido = $this->makeUser($this->tenant, 'exsocio@tienda-a.com', isActive: false);

        $this->placeOrder($this->tenant, $this->producto)->assertCreated();

        Notification::assertSentTimes(NewOrderNotification::class, 1);
        Notification::assertNotSentTo($cliente, NewOrderNotification::class);
        Notification::assertNotSentTo($suspendido, NewOrderNotification::class);
    }

    public function test_avisa_a_todos_los_admins_de_la_tienda(): void
    {
        $segundoAdmin = $this->makeUser($this->tenant, 'socio@tienda-a.com');

        $this->placeOrder($this->tenant, $this->producto)->assertCreated();

        Notification::assertSentTo($this->admin, NewOrderNotification::class);
        Notification::assertSentTo($segundoAdmin, NewOrderNotification::class);
    }

    public function test_el_correo_lleva_el_detalle_del_pedido(): void
    {
        $this->placeOrder($this->tenant, $this->producto, quantity: 3)->assertCreated();

        Notification::assertSentTo($this->admin, NewOrderNotification::class, function (NewOrderNotification $notification) {
            $mail = $notification->toMail($this->admin);
            $texto = implode("\n", array_merge($mail->introLines, $mail->outroLines));

            // 3 x 45.50 = 136.50, calculado en servidor.
            $this->assertStringContainsString('Pedido nuevo #', $mail->subject);
            $this->assertStringContainsString('$136.50', $mail->subject);
            $this->assertStringContainsString('Ana Compradora', $texto);
            $this->assertStringContainsString('51988877766', $texto);
            $this->assertStringContainsString('3 x Kingston Fury 16GB', $texto);
            $this->assertStringContainsString('**Total: $136.50**', $texto);
            $this->assertStringEndsWith('/dashboard/orders', $mail->actionUrl);

            return true;
        });
    }

    public function test_el_pedido_se_crea_aunque_el_mailer_falle(): void
    {
        // Esta es la razon de ser del try/catch en storeOrder: el pedido ya esta
        // guardado cuando se manda el aviso, asi que un SMTP caido no puede
        // devolverle un 500 al comprador.
        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('Connection could not be established with host smtp'));

        $this->placeOrder($this->tenant, $this->producto)->assertCreated();

        $this->assertDatabaseHas('orders', [
            'tenant_id'     => $this->tenant->id,
            'customer_name' => 'Ana Compradora',
            'total'         => 91.00,
        ]);
    }

    public function test_una_tienda_sin_admin_activo_no_rompe_el_pedido(): void
    {
        $this->admin->update(['is_active' => false]);

        $this->placeOrder($this->tenant, $this->producto)->assertCreated();

        Notification::assertNothingSent();
    }
}
