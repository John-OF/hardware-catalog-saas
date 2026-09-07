<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * "Avísame cuando llegue": la lista de espera no se consume hasta que el aviso se
 * envíe de verdad (FUN-1a).
 *
 * El fallo que fija este fichero no era que el aviso no se enviara —eso se sabía,
 * estaba escrito como "simulado"—, sino que `notifyStockSubscribers()` marcaba
 * `notified_at` igual. O sea que cada reposición daba por avisada a toda la lista
 * sin que a nadie le llegara nada: los interesados desaparecían de lo pendiente y
 * al cablear el envío real (FUN-1b) ya no habrían recibido el aviso nunca.
 *
 * Esta función no tenía ni un test, que es exactamente por lo que sobrevivió tanto.
 * Mientras el envío siga pendiente, lo que hay que fijar es que **la lista sigue
 * ahí**; cuando se cablee, este fichero se amplía para comprobar que se marca
 * `notified_at` sólo DESPUÉS de encolar.
 */
class StockNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $agotado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-avisos',
            'name'            => 'Tienda Avisos',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        // `tenant_id` a mano porque no es fillable (lo pone el hook `creating`
        // desde la tienda enlazada, y aquí no hay ninguna).
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

    public function test_el_interes_en_un_producto_agotado_queda_registrado(): void
    {
        $respuesta = $this->apuntarse('Comprador Paciente', 'comprador@ejemplo.com');

        $respuesta->assertStatus(201);

        $this->assertDatabaseHas('stock_notifications', [
            'product_id'       => $this->agotado->id,
            'customer_contact' => 'comprador@ejemplo.com',
            'notified_at'      => null,
        ]);
    }

    public function test_reponer_stock_no_consume_la_lista_de_espera(): void
    {
        $this->apuntarse('Comprador Paciente', 'comprador@ejemplo.com');

        // Reposición: es el paso que dispara `notifyStockSubscribers()`.
        $this->agotado->update(['stock' => 5]);

        // Sigue pendiente. Antes de FUN-1a esta fila salía con `notified_at`
        // puesto y el cliente se quedaba sin aviso para siempre.
        $this->assertDatabaseHas('stock_notifications', [
            'product_id'       => $this->agotado->id,
            'customer_contact' => 'comprador@ejemplo.com',
            'notified_at'      => null,
        ]);
    }

    public function test_agotarse_y_reponerse_otra_vez_tampoco_la_consume(): void
    {
        $this->apuntarse('Comprador Paciente', 'comprador@ejemplo.com');

        // El ciclo completo de un producto que entra y sale de stock varias veces
        // antes de que exista el envío: ninguna de las vueltas puede gastar el aviso.
        $this->agotado->update(['stock' => 5]);
        $this->agotado->update(['stock' => 0]);
        $this->agotado->update(['stock' => 2]);

        $this->assertDatabaseHas('stock_notifications', [
            'product_id'       => $this->agotado->id,
            'customer_contact' => 'comprador@ejemplo.com',
            'notified_at'      => null,
        ]);
    }

    public function test_apuntarse_dos_veces_no_duplica_el_aviso(): void
    {
        $this->apuntarse('Comprador Paciente', 'comprador@ejemplo.com');
        $this->apuntarse('Comprador Paciente', 'comprador@ejemplo.com');

        $this->assertDatabaseCount('stock_notifications', 1);
    }

    public function test_no_se_puede_apuntar_a_un_producto_disponible(): void
    {
        $this->agotado->update(['stock' => 3]);

        $this->apuntarse('Comprador Impaciente', 'otro@ejemplo.com')
            ->assertStatus(422);

        $this->assertDatabaseCount('stock_notifications', 0);
    }

    private function apuntarse(string $nombre, string $contacto)
    {
        return $this->postJson(
            "/api/public/{$this->tenant->slug}/products/{$this->agotado->id}/notify-me",
            [
                'customer_name'    => $nombre,
                'customer_contact' => $contacto,
            ]
        );
    }
}
