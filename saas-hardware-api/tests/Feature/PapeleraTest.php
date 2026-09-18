<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * La papelera de productos y pedidos (`MOD-8`).
 *
 * Lo que de verdad se vigila aquí, por encima de que restaurar funcione:
 *
 * 1. **Que lo borrado desaparezca de TODAS partes.** Un producto en la papelera
 *    que siga saliendo en el catálogo público, o un pedido borrado que siga
 *    contando como venta en los reportes, es peor que no tener papelera: el
 *    dueño cree que borró y no borró. Los reportes son el caso delicado, porque
 *    la única consulta de pedidos que no pasa por Eloquent está ahí y el global
 *    scope de `SoftDeletes` no la toca.
 * 2. **Que el stock cuadre en los dos sentidos.** Borrar un pedido atendido
 *    devuelve su stock; restaurarlo lo vuelve a descontar. Si sólo se hiciera lo
 *    primero, restaurar inventaría unidades.
 * 3. **Que las fotos sigan ahí mientras se pueda restaurar** (`TEC-14` +
 *    `MOD-8`, cubierto en `ImagenesCompartidasTest`).
 */
class PapeleraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tienda = Tenant::create([
            'slug' => 'tienda-papelera',
            'name' => 'Tienda Papelera',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
            'plan' => 'enterprise',
        ]);

        $this->admin = $this->usuario('admin', 'duenia@papelera.test');
        $this->staff = $this->usuario('staff', 'vendedor@papelera.test');
    }

    // ------------------------------------------------------------- productos

    public function test_borrar_un_producto_lo_manda_a_la_papelera_y_lo_saca_del_catalogo(): void
    {
        $producto = $this->producto('RTX 4070', stock: 5);

        $this->comoAdmin()->deleteJson("/api/products/{$producto->id}")->assertNoContent();

        // Ni en el panel...
        $this->assertSame([], $this->comoAdmin()->getJson('/api/products')->assertOk()->json('data'));

        // ...ni en el catálogo público.
        $publico = $this->getJson("/api/public/{$this->tienda->slug}/products")->assertOk();
        $this->assertSame([], $publico->json('data'));

        // Pero en la papelera, entero.
        $papelera = $this->comoAdmin()->getJson('/api/trash')->assertOk();
        $this->assertSame(1, $papelera->json('totales.productos'));
        $this->assertSame('RTX 4070', $papelera->json('items.data.0.name'));
        $this->assertSame(30, $papelera->json('retencion.dias'));
    }

    public function test_restaurar_un_producto_lo_devuelve_al_catalogo_con_sus_variantes(): void
    {
        $producto = $this->producto('Memoria Fury', stock: 0);
        $this->variante($producto, '16 GB', 300, 4);
        $producto->sincronizarResumenDeVariantes();

        $this->comoAdmin()->deleteJson("/api/products/{$producto->id}")->assertNoContent();
        $this->comoAdmin()->postJson("/api/trash/productos/{$producto->id}/restore")->assertOk();

        $enElPanel = $this->comoAdmin()->getJson('/api/products')->assertOk();

        $this->assertCount(1, $enElPanel->json('data'));
        $this->assertSame('Memoria Fury', $enElPanel->json('data.0.name'));
        $this->assertCount(1, $enElPanel->json('data.0.variants'));
        $this->assertSame(0, $this->comoAdmin()->getJson('/api/trash')->json('totales.productos'));
    }

    public function test_un_producto_en_la_papelera_no_ocupa_hueco_del_plan(): void
    {
        // `pro` tiene tope de productos; se estrecha para que el caso quepa.
        config(['plans.plans.pro.limits.products' => 2]);
        $this->tienda->forceFill(['plan' => 'pro', 'trial_ends_at' => null])->save();

        $uno = $this->producto('Uno');
        $this->producto('Dos');

        // Al límite: no cabe un tercero.
        $this->comoAdmin()->postJson('/api/products', ['name' => 'Tres', 'price' => 10, 'stock' => 1])
            ->assertStatus(422);

        $this->comoAdmin()->deleteJson("/api/products/{$uno->id}")->assertNoContent();

        // Borrar libera el hueco al instante, que es lo que espera quien borra
        // para hacer sitio.
        $this->comoAdmin()->postJson('/api/products', ['name' => 'Tres', 'price' => 10, 'stock' => 1])
            ->assertCreated();

        // Y por eso restaurar puede no caber, y lo dice.
        $this->comoAdmin()->postJson("/api/trash/productos/{$uno->id}/restore")->assertStatus(422);
        $this->assertSame(1, $this->comoAdmin()->getJson('/api/trash')->json('totales.productos'));
    }

    public function test_borrar_del_todo_un_producto_de_la_papelera_no_deja_rastro(): void
    {
        $producto = $this->producto('Teclado');

        $this->comoAdmin()->deleteJson("/api/products/{$producto->id}")->assertNoContent();
        $this->comoAdmin()->deleteJson("/api/trash/productos/{$producto->id}")->assertNoContent();

        $this->assertNull(Product::withoutTenant()->withTrashed()->find($producto->id));
        $this->assertSame(0, $this->comoAdmin()->getJson('/api/trash')->json('totales.productos'));
    }

    // -------------------------------------------------------------- pedidos

    public function test_un_pedido_borrado_deja_de_contar_como_venta(): void
    {
        $producto = $this->producto('SSD', stock: 10, precio: 200);
        $pedido = $this->venta($producto, cantidad: 2);

        // Atendido y contando: 400 en el resumen y en los reportes.
        $this->assertSame(400.0, (float) $this->comoAdmin()->getJson('/api/dashboard/stats')->json('total_sales'));
        $this->assertSame(400.0, (float) $this->reporte()->json('resumen.ventas'));

        $this->comoAdmin()->deleteJson("/api/orders/{$pedido->id}")->assertNoContent();

        // Los dos a la vez: son dos consultas distintas —una por Eloquent y otra
        // por `DB::table`— y si sólo una filtrara la papelera, las dos pantallas
        // del panel darían cifras diferentes.
        $this->assertSame(0.0, (float) $this->comoAdmin()->getJson('/api/dashboard/stats')->json('total_sales'));
        $this->assertSame(0.0, (float) $this->reporte()->json('resumen.ventas'));
        $this->assertSame(0, (int) $this->reporte()->json('resumen.unidades'));
    }

    public function test_borrar_y_restaurar_un_pedido_atendido_deja_el_stock_como_estaba(): void
    {
        $producto = $this->producto('SSD', stock: 10, precio: 200);
        $pedido = $this->venta($producto, cantidad: 3);

        $this->assertSame(7, $this->stockDe($producto));

        // Borrar devuelve el stock, como ya hacía antes de MOD-8.
        $this->comoAdmin()->deleteJson("/api/orders/{$pedido->id}")->assertNoContent();
        $this->assertSame(10, $this->stockDe($producto));

        // Y restaurar lo vuelve a descontar: si no, restaurar inventaría tres
        // unidades y la venta contaría sin haber salido del almacén.
        $this->comoAdmin()->postJson("/api/trash/pedidos/{$pedido->id}/restore")->assertOk();
        $this->assertSame(7, $this->stockDe($producto));
        $this->assertSame(400.0 + 200.0, (float) $this->reporte()->json('resumen.ventas'));
    }

    public function test_restaurar_un_pedido_pendiente_no_mueve_stock(): void
    {
        $producto = $this->producto('SSD', stock: 10, precio: 200);
        $pedido = $this->venta($producto, cantidad: 3, estado: 'pending');

        $this->assertSame(10, $this->stockDe($producto));

        $this->comoAdmin()->deleteJson("/api/orders/{$pedido->id}")->assertNoContent();
        $this->comoAdmin()->postJson("/api/trash/pedidos/{$pedido->id}/restore")->assertOk();

        $this->assertSame(10, $this->stockDe($producto));
    }

    public function test_un_pedido_borrado_tampoco_sale_en_el_listado_ni_en_su_csv(): void
    {
        $producto = $this->producto('SSD', stock: 10, precio: 200);
        $pedido = $this->venta($producto, cantidad: 1);

        $this->comoAdmin()->deleteJson("/api/orders/{$pedido->id}")->assertNoContent();

        $this->assertSame([], $this->comoAdmin()->getJson('/api/orders')->assertOk()->json('data'));

        $this->app['auth']->forgetGuards();
        $csv = $this->comoAdmin()->get('/api/orders/export')->assertOk()->streamedContent();

        $this->assertStringNotContainsString('Cliente Prueba', $csv);
    }

    // ------------------------------------------------------- vaciar y purgar

    public function test_vaciar_la_papelera_se_lleva_los_dos_tipos(): void
    {
        $producto = $this->producto('Teclado');
        $otro = $this->producto('Mouse');
        $pedido = $this->venta($this->producto('SSD', stock: 5, precio: 100), cantidad: 1);

        $this->comoAdmin()->deleteJson("/api/products/{$producto->id}")->assertNoContent();
        $this->comoAdmin()->deleteJson("/api/products/{$otro->id}")->assertNoContent();
        $this->comoAdmin()->deleteJson("/api/orders/{$pedido->id}")->assertNoContent();

        $respuesta = $this->comoAdmin()->deleteJson('/api/trash')->assertOk();

        $this->assertSame(2, $respuesta->json('productos'));
        $this->assertSame(1, $respuesta->json('pedidos'));
        $this->assertSame(0, $this->comoAdmin()->getJson('/api/trash')->json('totales.productos'));
        $this->assertNull(Order::withoutTenant()->withTrashed()->find($pedido->id));
    }

    public function test_el_comando_purga_lo_caducado_y_respeta_lo_reciente(): void
    {
        $viejo = $this->producto('Viejo');
        $reciente = $this->producto('Reciente');

        $viejo->delete();
        $reciente->delete();

        // Se envejece a mano: lo que decide es `deleted_at`, no cuándo se creó.
        Product::withoutTenant()->withTrashed()->where('id', $viejo->id)
            ->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('papelera:purgar')->assertExitCode(0);

        $this->assertNull(Product::withoutTenant()->withTrashed()->find($viejo->id));
        $this->assertNotNull(Product::withoutTenant()->withTrashed()->find($reciente->id));
    }

    public function test_el_comando_purga_las_tiendas_de_todas_las_cuentas(): void
    {
        // Corre desde el cron, sin tienda resuelta: sin `withoutTenant()` el
        // scope de AUD-4 falla en cerrado y no purgaría nada, en silencio.
        $otra = Tenant::create([
            'slug' => 'otra-papelera', 'name' => 'Otra',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);

        $mio = $this->producto('Mío');
        $suyo = new Product(['name' => 'Suyo', 'price' => 10, 'stock' => 1, 'is_active' => true, 'status' => 'published']);
        $suyo->tenant_id = $otra->id;
        $suyo->save();

        $mio->delete();
        $suyo->delete();

        Product::withoutTenant()->withTrashed()->whereIn('id', [$mio->id, $suyo->id])
            ->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('papelera:purgar')->assertExitCode(0);

        $this->assertSame(0, Product::withoutTenant()->withTrashed()->count());
    }

    // --------------------------------------------------------------- accesos

    public function test_staff_no_ve_la_papelera_ni_restaura(): void
    {
        $producto = $this->producto('Teclado');
        $producto->delete();

        // FUN-4: si un colaborador pudiera restaurar y volver a borrar, no poder
        // borrar no sería ninguna restricción.
        $this->comoStaff()->getJson('/api/trash')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->comoStaff()->postJson("/api/trash/productos/{$producto->id}/restore")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->comoStaff()->deleteJson('/api/trash')->assertForbidden();
    }

    public function test_una_tienda_no_toca_la_papelera_de_otra(): void
    {
        $otra = Tenant::create([
            'slug' => 'otra-papelera', 'name' => 'Otra',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);

        $ajeno = new Product(['name' => 'Ajeno', 'price' => 10, 'stock' => 1, 'is_active' => true, 'status' => 'published']);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();
        $ajeno->delete();

        $this->assertSame(0, $this->comoAdmin()->getJson('/api/trash')->assertOk()->json('totales.productos'));

        $this->app['auth']->forgetGuards();
        $this->comoAdmin()->postJson("/api/trash/productos/{$ajeno->id}/restore")->assertNotFound();

        $this->assertNotNull(Product::withoutTenant()->withTrashed()->find($ajeno->id));
    }

    public function test_un_tipo_inventado_en_la_url_no_existe(): void
    {
        $this->comoAdmin()->getJson('/api/trash?tipo=categorias')->assertNotFound();
    }

    // --------------------------------------------------------------- ayudas

    private function producto(string $nombre, int $stock = 1, float $precio = 100): Product
    {
        $producto = new Product([
            'name' => $nombre, 'price' => $precio, 'stock' => $stock,
            'is_active' => true, 'status' => 'published',
        ]);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    private function variante(Product $producto, string $valor, float $precio, int $stock): ProductVariant
    {
        $variante = new ProductVariant([
            'product_id' => $producto->id,
            'options' => [['name' => 'Capacidad', 'value' => $valor]],
            'price' => $precio,
            'stock' => $stock,
        ]);
        $variante->tenant_id = $this->tienda->id;
        $variante->save();

        return $variante;
    }

    private function venta(Product $producto, int $cantidad, string $estado = 'attended'): Order
    {
        $respuesta = $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente Prueba',
            'status' => $estado,
            'items' => [['product_id' => $producto->id, 'quantity' => $cantidad]],
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        return Order::withoutTenant()->findOrFail($respuesta->json('id'));
    }

    private function reporte(): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->comoAdmin()->getJson('/api/reports')->assertOk();
    }

    /** El stock leído fuera de la petición: el scope de AUD-4 ya no tiene tienda. */
    private function stockDe(Product $producto): int
    {
        return (int) Product::withoutTenant()->withTrashed()->findOrFail($producto->id)->stock;
    }

    private function usuario(string $rol, string $correo): User
    {
        $usuario = new User([
            'name' => $rol, 'email' => $correo, 'password' => 'password123',
            'role' => $rol, 'is_active' => true,
        ]);
        $usuario->tenant_id = $this->tienda->id;
        $usuario->save();

        return $usuario;
    }

    private function comoAdmin(): static
    {
        return $this->conSesionDe($this->admin);
    }

    private function comoStaff(): static
    {
        return $this->conSesionDe($this->staff);
    }

    private function conSesionDe(User $usuario): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test', [$usuario->role])->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }
}
