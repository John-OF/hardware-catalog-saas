<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Número de pedido correlativo por tienda (FUN-3).
 *
 * Antes un pedido solo tenía su UUID, y cada pantalla enseñaba un trozo distinto:
 * el panel y el carrito los últimos 8 caracteres, la cuenta del cliente los 8
 * primeros. El dueño no tenía nada que dictar por teléfono ni nada que buscar.
 *
 * Lo que fija este fichero:
 *
 * 1. La serie empieza en 1 y avanza de uno en uno.
 * 2. Cada tienda lleva la suya: el número no filtra cuántos pedidos mueve el
 *    resto de la plataforma, que es visible para cualquier comprador.
 * 3. Los dos caminos que crean pedidos —checkout público y venta de mostrador—
 *    comparten la misma serie, porque el número se asigna en el modelo y no en
 *    los controladores.
 * 4. El contador no retrocede al borrar: un número usado no se reasigna.
 * 5. El número no se puede fijar desde la petición.
 */
class OrderNumberTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Product $productoA;

    private Product $productoB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenantA = $this->makeTenant('tienda-a', 'Tienda A');
        $this->tenantB = $this->makeTenant('tienda-b', 'Tienda B');

        $this->productoA = $this->makeProduct($this->tenantA, 'RAM 16GB');
        $this->productoB = $this->makeProduct($this->tenantB, 'SSD 1TB');

        $this->adminA = new User([
            'name'      => 'Duenio A',
            'email'     => 'duenio@tienda-a.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->adminA->tenant_id = $this->tenantA->id;
        $this->adminA->save();
    }

    public function test_la_serie_de_cada_tienda_empieza_en_uno_y_avanza(): void
    {
        $this->assertSame(1, $this->pedirEn($this->tenantA, $this->productoA));
        $this->assertSame(2, $this->pedirEn($this->tenantA, $this->productoA));
        $this->assertSame(3, $this->pedirEn($this->tenantA, $this->productoA));
    }

    public function test_cada_tienda_lleva_su_propia_serie(): void
    {
        $this->pedirEn($this->tenantA, $this->productoA);
        $this->pedirEn($this->tenantA, $this->productoA);

        // La tienda B abre hoy: su primer pedido es el #1 aunque en la plataforma
        // ya haya dos. Con un autoincremento global aquí saldría un 3, y su
        // cliente sabría cuántos pedidos mueven las demás tiendas.
        $this->assertSame(1, $this->pedirEn($this->tenantB, $this->productoB));
        $this->assertSame(3, $this->pedirEn($this->tenantA, $this->productoA));
    }

    public function test_la_venta_de_mostrador_comparte_la_serie_del_checkout(): void
    {
        $this->assertSame(1, $this->pedirEn($this->tenantA, $this->productoA));

        $respuesta = $this->asAdminA()->postJson('/api/orders', [
            'customer_name' => 'Cliente de mostrador',
            'status'        => 'attended',
            'items'         => [
                ['product_id' => $this->productoA->id, 'quantity' => 1],
            ],
        ]);

        $respuesta->assertCreated()->assertJsonPath('number', 2);

        $this->assertSame(3, $this->pedirEn($this->tenantA, $this->productoA));
    }

    public function test_borrar_un_pedido_no_reutiliza_su_numero(): void
    {
        $this->pedirEn($this->tenantA, $this->productoA);
        $this->pedirEn($this->tenantA, $this->productoA);

        $segundo = Order::withoutTenant()->where('tenant_id', $this->tenantA->id)
            ->where('number', 2)
            ->firstOrFail();

        $this->asAdminA()->deleteJson("/api/orders/{$segundo->id}")->assertNoContent();

        // Si el correlativo saliera de MAX(number) + 1, el pedido siguiente sería
        // otro #2 y el dueño tendría dos pedidos distintos con el mismo número en
        // su historial de WhatsApp.
        $this->assertSame(3, $this->pedirEn($this->tenantA, $this->productoA));
    }

    public function test_el_numero_no_se_puede_fijar_desde_la_peticion(): void
    {
        $respuesta = $this->postJson("/api/public/{$this->tenantA->slug}/orders", [
            'customer_name'  => 'Listillo',
            'customer_phone' => '999888777',
            'number'         => 9999,
            'items'          => [
                ['product_id' => $this->productoA->id, 'quantity' => 1],
            ],
        ]);

        $respuesta->assertCreated()->assertJsonPath('number', 1);
    }

    public function test_el_panel_encuentra_un_pedido_por_su_numero(): void
    {
        $this->pedirEn($this->tenantA, $this->productoA, 'Ana');
        $this->pedirEn($this->tenantA, $this->productoA, 'Beto');

        // Con y sin almohadilla: el cliente reenvía el mensaje de WhatsApp tal
        // cual, y ahí el número va escrito como "#2".
        foreach (['2', '#2'] as $termino) {
            $this->asAdminA()->getJson('/api/orders?search='.urlencode($termino))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.customer_name', 'Beto');
        }
    }

    public function test_la_busqueda_por_nombre_sigue_funcionando(): void
    {
        $this->pedirEn($this->tenantA, $this->productoA, 'Ana');
        $this->pedirEn($this->tenantA, $this->productoA, 'Beto');

        $this->asAdminA()->getJson('/api/orders?search=Ana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Ana');
    }

    /** Crea un pedido por el checkout público y devuelve su número. */
    private function pedirEn(Tenant $tenant, Product $producto, string $cliente = 'Comprador'): int
    {
        $respuesta = $this->postJson("/api/public/{$tenant->slug}/orders", [
            'customer_name'  => $cliente,
            'customer_phone' => '999888777',
            'items'          => [
                ['product_id' => $producto->id, 'quantity' => 1],
            ],
        ]);

        $respuesta->assertCreated();

        return $respuesta->json('number');
    }

    private function makeTenant(string $slug, string $nombre): Tenant
    {
        $tenant = Tenant::create([
            'slug'            => $slug,
            'name'            => $nombre,
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        // El contador arranca en 1 por el default de la migración; se comprueba
        // aquí para que un cambio de default no pase inadvertido.
        $this->assertSame(1, (int) DB::table('tenants')->where('id', $tenant->id)->value('next_order_number'));

        return $tenant;
    }

    private function makeProduct(Tenant $tenant, string $nombre): Product
    {
        $producto = new Product([
            'name'      => $nombre,
            'price'     => 100,
            'stock'     => 50,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $producto->tenant_id = $tenant->id;
        $producto->save();

        return $producto;
    }

    private function asAdminA(): static
    {
        $token = $this->adminA->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenantA->slug,
        ]);
    }
}
