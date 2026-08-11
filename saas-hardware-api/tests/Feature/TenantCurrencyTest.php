<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Moneda configurable por tienda (OWN-1 / 7.4).
 *
 * El default es USD para no cambiarle la vista a las tiendas que ya existian,
 * que es lo que venian mostrando con el "$" incrustado.
 *
 * La lista de monedas vive en config/currencies.php y el frontend tiene su
 * copia en src/utils/money.ts; el test del rechazo (422) es lo que avisa si las
 * dos listas se desincronizan.
 */
class TenantCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

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
    }

    private function asAdmin(): static
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ]);
    }

    public function test_una_tienda_nueva_nace_en_usd(): void
    {
        $this->assertSame('USD', $this->tenant->fresh()->currency);
    }

    public function test_el_dueno_puede_cambiar_la_moneda(): void
    {
        $this->asAdmin()
            ->putJson('/api/tenant', ['currency' => 'PEN'])
            ->assertOk()
            ->assertJsonPath('currency', 'PEN');

        $this->assertSame('PEN', $this->tenant->fresh()->currency);
    }

    public function test_rechaza_una_moneda_fuera_de_la_lista(): void
    {
        $this->asAdmin()
            ->putJson('/api/tenant', ['currency' => 'XYZ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');

        $this->assertSame('USD', $this->tenant->fresh()->currency);
    }

    public function test_el_catalogo_publico_expone_la_moneda(): void
    {
        $this->tenant->update(['currency' => 'CLP']);

        $this->getJson("/api/public/{$this->tenant->slug}")
            ->assertOk()
            ->assertJsonPath('currency', 'CLP');
    }

    public function test_el_formato_respeta_los_decimales_de_cada_moneda(): void
    {
        // El peso chileno no usa decimales: mostrar "$1.028,98" delata que el
        // sistema no es de por alli.
        $this->assertSame('S/1,028.98', Money::format(1028.98, 'PEN'));
        $this->assertSame('$1,029', Money::format(1028.98, 'CLP'));
        $this->assertSame('₲1,029', Money::format(1028.98, 'PYG'));

        // Sin moneda o con una desconocida no se inventa un simbolo.
        $this->assertSame('$1,028.98', Money::format(1028.98, null));
        $this->assertSame('XYZ 1,028.98', Money::format(1028.98, 'XYZ'));
    }

    public function test_el_correo_del_pedido_usa_la_moneda_de_la_tienda(): void
    {
        $this->tenant->update(['currency' => 'PEN']);

        $order = Order::create([
            'tenant_id'      => $this->tenant->id,
            'customer_name'  => 'Ana Compradora',
            'customer_phone' => '51988877766',
            'status'         => 'pending',
            'total'          => 45.50,
        ]);
        $order->items()->create([
            'product_name' => 'Kingston Fury 16GB',
            'unit_price'   => 45.50,
            'quantity'     => 1,
            'subtotal'     => 45.50,
        ]);

        $mail = (new NewOrderNotification($order->fresh()->load('items')))->toMail($this->admin);
        $texto = implode("\n", array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringContainsString('S/45.50', $mail->subject);
        $this->assertStringContainsString('S/45.50', $texto);
        $this->assertStringNotContainsString('$45.50', $texto);
    }
}
