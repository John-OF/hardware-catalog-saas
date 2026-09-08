<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Avisame cuando llegue" ya avisa de verdad (FUN-1b).
 *
 * Cierra lo que 5.4 dejo a medias y FUN-1a dejo de estropear. Lo que fija:
 *
 * 1. Al reponer stock sale el correo, y solo entonces se marca `notified_at`.
 * 2. Quien dejo un TELEFONO no recibe correo y **sigue pendiente**, para que
 *    aparezca en la lista de espera del panel.
 * 3. No se avisa dos veces por la misma espera.
 * 4. Bajar stock o tocar otra cosa del producto no dispara nada.
 * 5. Un mailer caido no tumba el guardado del producto ni marca como avisado a
 *    quien no lo recibio.
 */
class BackInStockNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Product $agotado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        Notification::fake();

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-espera',
            'name'            => 'Tienda Espera',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-espera.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();

        $this->agotado = new Product([
            'name'      => 'RTX 5090',
            'price'     => 3000,
            'stock'     => 0,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $this->agotado->tenant_id = $this->tenant->id;
        $this->agotado->save();
    }

    public function test_reponer_stock_avisa_a_quien_dejo_correo(): void
    {
        $espera = $this->apuntar('Ana', 'ana@ejemplo.com');

        $this->agotado->update(['stock' => 4]);

        Notification::assertSentOnDemand(
            BackInStockNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ana@ejemplo.com'
        );

        // Y AHORA si se marca: el orden es lo que fijo FUN-1a.
        $this->assertNotNull($espera->fresh()->notified_at);
    }

    public function test_quien_dejo_telefono_no_recibe_correo_y_sigue_esperando(): void
    {
        $espera = $this->apuntar('Beto', '51987654321');

        $this->agotado->update(['stock' => 4]);

        Notification::assertNothingSentTo(new AnonymousNotifiable);

        // Lo importante no es que no se le avise: es que NO se le da por avisado.
        // Sigue pendiente para que el dueño lo vea en la lista de espera y le
        // escriba por WhatsApp.
        $this->assertNull($espera->fresh()->notified_at);
    }

    public function test_no_se_avisa_dos_veces_de_la_misma_espera(): void
    {
        $this->apuntar('Ana', 'ana@ejemplo.com');

        $this->agotado->update(['stock' => 4]);
        $this->agotado->update(['stock' => 0]);
        $this->agotado->update(['stock' => 7]);

        Notification::assertSentOnDemandTimes(BackInStockNotification::class, 1);
    }

    public function test_bajar_el_stock_o_editar_otra_cosa_no_dispara_nada(): void
    {
        // La espera se crea a mano y no por el endpoint publico, que rechaza
        // apuntarse a un producto que ya tiene stock: aqui hace falta justo eso,
        // una espera viva sobre un producto disponible.
        $this->agotado->update(['stock' => 5]);
        $espera = new StockNotification([
            'product_id'       => $this->agotado->id,
            'customer_name'    => 'Ana',
            'customer_contact' => 'ana@ejemplo.com',
        ]);
        $espera->tenant_id = $this->tenant->id;
        $espera->save();

        $this->agotado->update(['stock' => 2]);
        $this->agotado->update(['name' => 'RTX 5090 Ti']);
        $this->agotado->update(['stock' => 0]);

        Notification::assertNothingSentTo(new AnonymousNotifiable);
        $this->assertNull($espera->fresh()->notified_at);
    }

    public function test_un_mailer_caido_no_tumba_el_guardado_ni_da_por_avisado(): void
    {
        $espera = $this->apuntar('Ana', 'ana@ejemplo.com');

        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP caido'));

        $this->agotado->update(['stock' => 4]);

        // El stock se repuso igual...
        $this->assertSame(4, $this->agotado->fresh()->stock);
        // ...y Ana sigue pendiente, asi que la proxima reposicion vuelve a intentarlo.
        $this->assertNull($espera->fresh()->notified_at);
    }

    public function test_el_panel_ve_la_lista_de_espera_y_puede_marcarla(): void
    {
        $porCorreo = $this->apuntar('Ana', 'ana@ejemplo.com');
        $porTelefono = $this->apuntar('Beto', '51987654321');

        $this->agotado->update(['stock' => 4]);

        // Por defecto salen los dos, pendientes primero.
        $this->asAdmin()->getJson('/api/stock-notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.customer_name', 'Beto')
            ->assertJsonPath('data.0.product.name', 'RTX 5090');

        $this->asAdmin()->getJson('/api/stock-notifications?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Beto');

        // El dueño escribe por WhatsApp a Beto y lo marca a mano.
        $this->asAdmin()->putJson("/api/stock-notifications/{$porTelefono->id}", ['notified' => true])
            ->assertOk();

        $this->assertNotNull($porTelefono->fresh()->notified_at);
        $this->assertNotNull($porCorreo->fresh()->notified_at);

        $this->asAdmin()->getJson('/api/stock-notifications?status=pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->asAdmin()->deleteJson("/api/stock-notifications/{$porTelefono->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('stock_notifications', ['id' => $porTelefono->id]);
    }

    public function test_la_lista_de_espera_no_cruza_tiendas(): void
    {
        $this->apuntar('Ana', 'ana@ejemplo.com');

        $otra = Tenant::create([
            'slug'            => 'tienda-ajena',
            'name'            => 'Tienda Ajena',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $suAdmin = new User([
            'name'      => 'Duenio Ajeno',
            'email'     => 'duenio@tienda-ajena.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $suAdmin->tenant_id = $otra->id;
        $suAdmin->save();

        $token = $suAdmin->createToken('test', ['admin'])->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $otra->slug,
        ])->getJson('/api/stock-notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function apuntar(string $nombre, string $contacto): StockNotification
    {
        $this->postJson("/api/public/{$this->tenant->slug}/products/{$this->agotado->id}/notify-me", [
            'customer_name'    => $nombre,
            'customer_contact' => $contacto,
        ])->assertCreated();

        return StockNotification::withoutTenant()
            ->where('product_id', $this->agotado->id)
            ->where('customer_contact', $contacto)
            ->firstOrFail();
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
