<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
 * Costo de compra y utilidad por venta (MOD-6).
 *
 * Tres cosas que, si se rompen, rompen algo peor que una pantalla:
 *
 * 1. **El costo no sale del panel, y dentro del panel no sale del admin.** Es lo
 *    que la tienda le paga al proveedor: en el catálogo público es un regalo a la
 *    competencia, y al vendedor de mostrador no le hace falta para vender.
 * 2. **Quien no ve el campo no puede borrarlo.** Staff edita productos; si su
 *    formulario, que no trae el costo, lo dejara en blanco al guardar, el dato se
 *    perdería sin que nadie tocara nada.
 * 3. **La utilidad de una venta no cambia nunca.** El costo se copia en la línea
 *    del pedido el día de la venta, así que subir el precio del proveedor mañana
 *    no puede reescribir lo que se ganó ayer.
 */
class CostoYMargenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $staff;

    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tenant = Tenant::create([
            'slug' => 'tienda-margenes',
            'name' => 'Tienda Márgenes',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
        ]);

        $this->admin = $this->usuario('admin', 'duenia@margenes.test');
        $this->staff = $this->usuario('staff', 'vendedor@margenes.test');

        $this->producto = new Product([
            'name' => 'Teclado mecánico', 'price' => 200, 'cost' => 120, 'stock' => 10,
            'is_active' => true, 'status' => 'published',
        ]);
        $this->producto->tenant_id = $this->tenant->id;
        $this->producto->save();
    }

    // ------------------------------------------------------- quién ve el costo

    public function test_el_admin_ve_el_costo_en_el_listado_y_en_la_ficha(): void
    {
        $this->comoAdmin()->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.cost', '120.00');

        $this->comoAdmin()->getJson("/api/products/{$this->producto->id}")
            ->assertOk()
            ->assertJsonPath('cost', '120.00');
    }

    public function test_staff_no_ve_el_costo(): void
    {
        $respuesta = $this->comoStaff()->getJson('/api/products')->assertOk();

        $this->assertArrayNotHasKey('cost', $respuesta->json('data.0'));
    }

    public function test_el_catalogo_publico_no_devuelve_el_costo(): void
    {
        $listado = $this->getJson("/api/public/{$this->tenant->slug}/products")->assertOk();
        $this->assertArrayNotHasKey('cost', $listado->json('data.0'));

        $ficha = $this->getJson("/api/public/{$this->tenant->slug}/products/{$this->producto->id}")->assertOk();
        $this->assertArrayNotHasKey('cost', $ficha->json());
    }

    public function test_el_catalogo_publico_tampoco_lo_devuelve_en_las_variantes(): void
    {
        $this->variante('16 GB', precio: 300, costo: 190);

        $ficha = $this->getJson("/api/public/{$this->tenant->slug}/products/{$this->producto->id}")->assertOk();

        $this->assertNotEmpty($ficha->json('variants'));
        $this->assertArrayNotHasKey('cost', $ficha->json('variants.0'));
    }

    // ------------------------------------------------- quién puede escribirlo

    public function test_el_admin_guarda_el_costo(): void
    {
        $this->comoAdmin()->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Teclado mecánico', 'price' => 200, 'stock' => 10, 'cost' => 135.50,
        ])->assertOk();

        $this->assertSame('135.50', $this->producto->fresh()->cost);
    }

    public function test_staff_editando_el_producto_no_borra_el_costo(): void
    {
        // El formulario de staff no trae el campo: la petición va sin él.
        $this->comoStaff()->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Teclado mecánico RGB', 'price' => 200, 'stock' => 10,
        ])->assertOk();

        $producto = $this->producto->fresh();

        $this->assertSame('Teclado mecánico RGB', $producto->name);
        $this->assertSame('120.00', $producto->cost);
    }

    public function test_staff_no_puede_poner_un_costo_aunque_lo_mande_a_mano(): void
    {
        $this->comoStaff()->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Teclado mecánico', 'price' => 200, 'stock' => 10, 'cost' => 1,
        ])->assertOk();

        $this->assertSame('120.00', $this->producto->fresh()->cost);
    }

    public function test_el_admin_puede_dejar_el_costo_en_blanco(): void
    {
        $this->comoAdmin()->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Teclado mecánico', 'price' => 200, 'stock' => 10, 'cost' => null,
        ])->assertOk();

        $this->assertNull($this->producto->fresh()->cost);
    }

    public function test_cambiar_el_costo_queda_escrito_en_la_bitacora(): void
    {
        $this->comoAdmin()->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Teclado mecánico', 'price' => 200, 'stock' => 10, 'cost' => 150,
        ])->assertOk();

        $linea = ActivityLog::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('action', ActivityLog::PRODUCTO_EDITADO)
            ->latest()
            ->firstOrFail();

        // Tocar el costo cambia el margen de todo lo que se venda después: es un
        // cambio de dinero, y se anota como el precio.
        $this->assertStringContainsString('costo 120 → 150', $linea->description);
    }

    // ------------------------------------------------------------- variantes

    public function test_con_variantes_el_costo_es_de_cada_una_y_la_ficha_resume_la_mas_barata(): void
    {
        $this->comoAdmin()->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Teclado mecánico',
            'variants' => [
                ['options' => [['name' => 'Switch', 'value' => 'Rojo']], 'price' => 250, 'cost' => 160, 'stock' => 3],
                ['options' => [['name' => 'Switch', 'value' => 'Azul']], 'price' => 220, 'cost' => 140, 'stock' => 2],
            ],
        ])->assertOk();

        $producto = $this->producto->fresh();

        // `withoutTenant()` porque la peticion ya termino y con ella se olvido la
        // tienda: el scope de AUD-4 falla en cerrado y la relacion normal
        // devolveria cero variantes (no es que no las haya).
        $variantes = ProductVariant::withoutTenant()->where('product_id', $producto->id)->get();

        // El resumen de la ficha describe SIEMPRE a la misma variante: la más
        // barata. Precio y costo emparejados, o el margen de la ficha mezclaría
        // dos variantes distintas.
        $this->assertSame('220.00', $producto->price);
        $this->assertSame('140.00', $producto->cost);
        $roja = $variantes->first(fn ($v) => $v->options[0]['value'] === 'Rojo');
        $this->assertSame('160.00', $roja->cost);
    }

    public function test_staff_editando_variantes_no_borra_sus_costos(): void
    {
        $variante = $this->variante('16 GB', precio: 300, costo: 190);

        $this->comoStaff()->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Teclado mecánico',
            'variants' => [
                ['id' => $variante->id, 'options' => [['name' => 'Capacidad', 'value' => '16 GB']], 'price' => 310, 'stock' => 4],
            ],
        ])->assertOk();

        $actualizada = ProductVariant::withoutTenant()->findOrFail($variante->id);

        $this->assertSame('310.00', $actualizada->price);
        $this->assertSame('190.00', $actualizada->cost);
    }

    // ---------------------------------------------------- la venta y su margen

    public function test_la_venta_copia_el_costo_y_calcula_la_utilidad(): void
    {
        $pedido = $this->venderDosUnidades();

        $this->assertSame('120.00', $pedido->items->first()->unit_cost);

        $this->comoAdmin()->getJson("/api/orders/{$pedido->id}")
            ->assertOk()
            // 2 x (200 - 120)
            ->assertJsonPath('utilidad', 160)
            ->assertJsonPath('costo_total', 240)
            ->assertJsonPath('lineas_sin_costo', 0);
    }

    public function test_cambiar_el_costo_despues_no_reescribe_la_utilidad_de_lo_ya_vendido(): void
    {
        $pedido = $this->venderDosUnidades();

        $this->producto->update(['cost' => 190]);

        $this->comoAdmin()->getJson("/api/orders/{$pedido->id}")
            ->assertOk()
            ->assertJsonPath('utilidad', 160);
    }

    public function test_un_pedido_sin_ningun_costo_no_dice_que_la_utilidad_sea_cero(): void
    {
        $this->producto->update(['cost' => null]);

        $pedido = $this->venderDosUnidades();

        $this->comoAdmin()->getJson("/api/orders/{$pedido->id}")
            ->assertOk()
            // null es "no se sabe": cero sería afirmar que se ganó el precio entero.
            ->assertJsonPath('utilidad', null)
            ->assertJsonPath('costo_total', null)
            ->assertJsonPath('lineas_sin_costo', 1);
    }

    public function test_si_solo_algunas_lineas_tienen_costo_la_utilidad_es_parcial_y_se_avisa(): void
    {
        $sinCosto = new Product([
            'name' => 'Alfombrilla', 'price' => 50, 'stock' => 10,
            'is_active' => true, 'status' => 'published',
        ]);
        $sinCosto->tenant_id = $this->tenant->id;
        $sinCosto->save();

        $respuesta = $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente mostrador',
            'status' => 'attended',
            'items' => [
                ['product_id' => $this->producto->id, 'quantity' => 1],
                ['product_id' => $sinCosto->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->comoAdmin()->getJson("/api/orders/{$respuesta->json('id')}")
            ->assertOk()
            ->assertJsonPath('utilidad', 80)
            ->assertJsonPath('lineas_sin_costo', 1);
    }

    public function test_el_envio_cobrado_no_cuenta_como_utilidad(): void
    {
        $this->tenant->update(['delivery_enabled' => true, 'delivery_cost' => 15]);

        $this->postJson("/api/public/{$this->tenant->slug}/orders", [
            'customer_name' => 'Compradora',
            'customer_phone' => '987654321',
            'delivery_method' => 'delivery',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertCreated();

        $pedido = Order::withoutTenant()->where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->comoAdmin()->getJson("/api/orders/{$pedido->id}")
            ->assertOk()
            // 200 - 120, y los 15 del envío fuera: eso se va en gasolina, no es
            // margen del producto.
            ->assertJsonPath('utilidad', 80)
            ->assertJsonPath('total', '215.00');
    }

    public function test_staff_no_ve_la_utilidad_de_un_pedido(): void
    {
        $pedido = $this->venderDosUnidades();

        $respuesta = $this->comoStaff()->getJson("/api/orders/{$pedido->id}")->assertOk();

        $this->assertArrayNotHasKey('utilidad', $respuesta->json());
        $this->assertArrayNotHasKey('unit_cost', $respuesta->json('items.0'));
    }

    public function test_el_cliente_no_ve_el_costo_de_su_propio_pedido(): void
    {
        $cliente = $this->usuario('customer', 'compradora@correo.test');

        $this->postJson("/api/public/{$this->tenant->slug}/orders", [
            'customer_name' => 'Compradora',
            'customer_phone' => '987654321',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ], [
            'Authorization' => 'Bearer '.$cliente->createToken('cliente')->plainTextToken,
        ])->assertCreated();

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/my-orders", [
            'Authorization' => 'Bearer '.$cliente->createToken('cliente')->plainTextToken,
        ])->assertOk();

        $this->assertStringNotContainsString('unit_cost', $respuesta->getContent());
    }

    // ------------------------------------------------------------- ayudantes

    private function venderDosUnidades(): Order
    {
        $respuesta = $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente mostrador',
            'status' => 'attended',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 2]],
        ])->assertCreated();

        return Order::withoutTenant()->with('items')->findOrFail($respuesta->json('id'));
    }

    private function variante(string $valor, float $precio, ?float $costo): ProductVariant
    {
        $variante = new ProductVariant([
            'product_id' => $this->producto->id,
            'options' => [['name' => 'Capacidad', 'value' => $valor]],
            'price' => $precio,
            'cost' => $costo,
            'stock' => 5,
        ]);
        $variante->tenant_id = $this->tenant->id;
        $variante->save();

        return $variante;
    }

    private function usuario(string $rol, string $correo): User
    {
        $usuario = new User([
            'name' => ucfirst($rol), 'email' => $correo, 'password' => 'secret1234',
            'role' => $rol, 'is_active' => true,
        ]);
        $usuario->tenant_id = $this->tenant->id;
        $usuario->save();

        return $usuario;
    }

    private function comoAdmin(): self
    {
        return $this->conSesionDe($this->admin);
    }

    private function comoStaff(): self
    {
        return $this->conSesionDe($this->staff);
    }

    private function conSesionDe(User $usuario): self
    {
        // Sin esto, el guard se queda con el usuario de la peticion anterior: en
        // un mismo test la aplicacion no se reconstruye entre peticiones, y un
        // test que entra como admin y luego como staff seguiria siendo admin.
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant' => $this->tenant->slug,
        ]);
    }
}
