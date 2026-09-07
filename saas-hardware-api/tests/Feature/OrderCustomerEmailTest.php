<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Avisos por correo al comprador (FUN-2).
 *
 * Hasta ahora el unico correo que salia al recibir un pedido iba al dueno: el
 * comprador no tenia constancia de nada y todo el seguimiento dependia de que el
 * dueno le escribiera a mano por WhatsApp.
 *
 * Lo que fija este fichero:
 *
 * 1. El correo del comprador es OPCIONAL, y sin el todo sigue funcionando igual.
 * 2. Con correo, el pedido se confirma; el aviso al dueno no cambia.
 * 3. Los cambios de estado avisan, pero no todos: volver a "pendiente" no.
 * 4. La venta de mostrador guarda el correo y NO manda confirmacion: el cliente
 *    estaba delante.
 * 5. Nada de esto puede tumbar la peticion si el mailer falla.
 */
class OrderCustomerEmailTest extends TestCase
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

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-a.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();

        $this->producto = new Product([
            'name'      => 'Kingston Fury 16GB',
            'price'     => 45.50,
            'stock'     => 10,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $this->producto->tenant_id = $this->tenant->id;
        $this->producto->save();
    }

    public function test_el_pedido_con_correo_se_confirma_al_comprador(): void
    {
        $this->pedir('comprador@ejemplo.com')->assertCreated();

        $this->assertDatabaseHas('orders', [
            'customer_email' => 'comprador@ejemplo.com',
        ]);

        Notification::assertSentOnDemand(
            OrderPlacedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'comprador@ejemplo.com'
        );

        // El aviso al dueno (OWN-2) sigue saliendo: son dos correos distintos.
        Notification::assertSentTo($this->admin, NewOrderNotification::class);
    }

    public function test_sin_correo_el_pedido_entra_igual_y_no_se_confirma_a_nadie(): void
    {
        $this->pedir(null)->assertCreated();

        Notification::assertNothingSentTo(new AnonymousNotifiable);
        Notification::assertSentTo($this->admin, NewOrderNotification::class);
    }

    public function test_un_correo_mal_escrito_se_rechaza(): void
    {
        $this->pedir('esto-no-es-un-correo')->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_el_cambio_a_listo_avisa_al_comprador(): void
    {
        $this->pedir('comprador@ejemplo.com');
        $pedido = $this->ultimoPedido();

        $this->asAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'attended'])
            ->assertOk();

        Notification::assertSentOnDemand(
            OrderStatusChangedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'comprador@ejemplo.com'
        );
    }

    public function test_volver_a_pendiente_no_avisa(): void
    {
        $this->pedir('comprador@ejemplo.com');
        $pedido = $this->ultimoPedido();

        $this->asAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'attended'])->assertOk();
        Notification::assertSentOnDemandTimes(OrderStatusChangedNotification::class, 1);

        // Deshacer un "atendido" es una correccion del dueno, no una noticia para
        // el cliente: si esto avisara, recibiria un correo diciendo que su pedido,
        // que ya estaba listo, vuelve a estar pendiente.
        $this->asAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'pending'])->assertOk();
        Notification::assertSentOnDemandTimes(OrderStatusChangedNotification::class, 1);
    }

    public function test_guardar_el_mismo_estado_no_avisa(): void
    {
        $this->pedir('comprador@ejemplo.com');
        $pedido = $this->ultimoPedido();

        $this->asAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'pending'])
            ->assertOk();

        Notification::assertNotSentTo(new AnonymousNotifiable, OrderStatusChangedNotification::class);
    }

    public function test_sin_correo_el_cambio_de_estado_no_intenta_avisar(): void
    {
        $this->pedir(null);
        $pedido = $this->ultimoPedido();

        $this->asAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'attended'])
            ->assertOk();

        Notification::assertNotSentTo(new AnonymousNotifiable, OrderStatusChangedNotification::class);
    }

    public function test_la_venta_de_mostrador_guarda_el_correo_pero_no_confirma(): void
    {
        $this->asAdmin()->postJson('/api/orders', [
            'customer_name'  => 'Cliente de mostrador',
            'customer_email' => 'mostrador@ejemplo.com',
            'status'         => 'pending',
            'items'          => [
                ['product_id' => $this->producto->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('orders', [
            'customer_email' => 'mostrador@ejemplo.com',
        ]);

        // El cliente estaba delante: un "recibimos tu pedido" sobra. Lo que si
        // saldra es el aviso cuando el encargo pase a listo.
        Notification::assertNotSentTo(new AnonymousNotifiable, OrderPlacedNotification::class);
    }

    public function test_un_mailer_caido_no_tumba_el_pedido(): void
    {
        // El pedido ya esta guardado cuando se intenta el correo, asi que un fallo
        // ahi no puede devolverle un error al comprador. Al mockear solo `route`,
        // la llamada de `send` del aviso al dueno tampoco encuentra su metodo y
        // revienta igual: se prueban los dos caminos de correo caidos a la vez,
        // que es el peor caso.
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP caido'));

        $this->pedir('comprador@ejemplo.com')->assertCreated();

        $this->assertDatabaseCount('orders', 1);
    }

    private function pedir(?string $correo)
    {
        $payload = [
            'customer_name'  => 'Comprador',
            'customer_phone' => '999888777',
            'items'          => [
                ['product_id' => $this->producto->id, 'quantity' => 1],
            ],
        ];

        if ($correo !== null) {
            $payload['customer_email'] = $correo;
        }

        return $this->postJson("/api/public/{$this->tenant->slug}/orders", $payload);
    }

    private function ultimoPedido(): Order
    {
        return Order::withoutTenant()->where('tenant_id', $this->tenant->id)->latest('created_at')->firstOrFail();
    }

    private function asAdmin(): static
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ]);
    }
}
