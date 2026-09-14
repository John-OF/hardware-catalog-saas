<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Variantes de producto (MOD-5).
 *
 * Opcionales: un producto sin variantes se comporta como siempre (eso lo fija el
 * resto de la suite, que no se toco). Con variantes, precio y stock son de cada
 * una y el producto guarda el resumen. Lo que se fija aqui es que ese resumen no
 * se desalinea nunca de lo que se vende, y que cada camino que cobra, descuenta
 * o avisa lo hace sobre la variante y no sobre la ficha.
 */
class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $duenio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tienda = $this->crearTienda('tienda-a');
        $this->duenio = new User([
            'name' => 'Duenio', 'email' => 'duenio@tienda-a.com',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->duenio->tenant_id = $this->tienda->id;
        $this->duenio->save();
    }

    private function crearTienda(string $slug): Tenant
    {
        return Tenant::create([
            'slug' => $slug, 'name' => ucfirst($slug),
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);
    }

    private function comoDuenio(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->duenio->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
        ]);
    }

    /** @return array<string, mixed> */
    private function variante(string $capacidad, float $precio, int $stock, ?float $oferta = null, ?string $id = null): array
    {
        return array_filter([
            'id' => $id,
            'options' => [['name' => 'Capacidad', 'value' => $capacidad]],
            'price' => $precio,
            'sale_price' => $oferta,
            'stock' => $stock,
        ], fn ($v) => $v !== null);
    }

    /** Crea por el panel una memoria con tres capacidades y la devuelve. */
    private function crearMemoria(): Product
    {
        $respuesta = $this->comoDuenio()->postJson('/api/products', [
            'name' => 'Kingston Fury',
            'status' => 'published',
            'variants' => [
                $this->variante('16 GB', 50, 4),
                $this->variante('32 GB', 90, 0, oferta: 80),
                $this->variante('8 GB', 30, 2),
            ],
        ])->assertCreated();

        return Product::withoutTenant()->findOrFail($respuesta->json('id'));
    }

    /**
     * Las variantes de un producto, en orden. Con `withoutTenant()` porque aqui,
     * fuera de una peticion, no hay tienda resuelta y el scope falla en cerrado
     * (AUD-4): la relacion devolveria vacio.
     *
     * @return Collection<int, ProductVariant>
     */
    private function variantesDe(Product $producto)
    {
        return ProductVariant::withoutTenant()
            ->where('product_id', $producto->id)
            ->orderBy('sort_order')
            ->get();
    }

    private function varianteDe(Product $producto, string $capacidad): ProductVariant
    {
        return $this->variantesDe($producto)->first(fn ($v) => $v->nombre === $capacidad);
    }

    // --- Panel: crear, editar y el resumen ------------------------------------

    public function test_crear_con_variantes_guarda_el_resumen_en_la_ficha(): void
    {
        $producto = $this->crearMemoria();

        $this->assertCount(3, $this->variantesDe($producto));
        // La mas barata por precio visible es la de 8 GB (30), no la oferta de 80.
        $this->assertSame('30.00', (string) $producto->price);
        $this->assertNull($producto->sale_price);
        $this->assertSame(6, $producto->stock);
        // El orden en que se escribieron se conserva.
        $this->assertSame(['16 GB', '32 GB', '8 GB'], $this->variantesDe($producto)->pluck('nombre')->all());
    }

    public function test_con_variantes_no_hace_falta_precio_ni_stock_de_la_ficha(): void
    {
        // Y sin variantes siguen siendo obligatorios, como siempre.
        $this->comoDuenio()->postJson('/api/products', ['name' => 'Sin nada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'stock']);
    }

    /**
     * Lo que manda el formulario del panel de verdad: multipart, `variants` como
     * JSON y precio y stock de la ficha vacios. Asi fallo la primera prueba en el
     * navegador ("The price field must be a number"), con los tests en verde.
     */
    public function test_el_formulario_multipart_con_precio_y_stock_vacios_se_acepta(): void
    {
        $respuesta = $this->comoDuenio()->withHeader('Accept', 'application/json')->post('/api/products', [
            'name' => 'Desde el formulario',
            'price' => '',
            'sale_price' => '',
            'stock' => '',
            'variants' => json_encode([$this->variante('16 GB', 65, 8)]),
        ])->assertCreated();

        $producto = Product::withoutTenant()->findOrFail($respuesta->json('id'));
        $this->assertSame('65.00', (string) $producto->price);

        $this->comoDuenio()->withHeader('Accept', 'application/json')->post("/api/products/{$producto->id}", [
            '_method' => 'PUT',
            'price' => '',
            'stock' => '',
            'variants' => json_encode([$this->variante('16 GB', 70, 3, id: $this->varianteDe($producto, '16 GB')->id)]),
        ])->assertOk();

        $this->assertSame('70.00', (string) $producto->fresh()->price);

        // Y sin variantes, vacio sigue sin valer.
        $this->comoDuenio()->withHeader('Accept', 'application/json')->post('/api/products', [
            'name' => 'Sin precio', 'price' => '', 'stock' => '', 'variants' => '[]',
        ])->assertStatus(422)->assertJsonValidationErrors(['price', 'stock']);
    }

    public function test_editar_actualiza_crea_y_borra_segun_la_lista(): void
    {
        $producto = $this->crearMemoria();
        $v16 = $this->varianteDe($producto, '16 GB');

        $this->comoDuenio()->putJson("/api/products/{$producto->id}", [
            'variants' => [
                $this->variante('16 GB', 55, 10, id: $v16->id),
                $this->variante('64 GB', 150, 1),
            ],
        ])->assertOk();

        $producto->refresh();
        $this->assertSame(['16 GB', '64 GB'], $this->variantesDe($producto)->pluck('nombre')->all());
        // Se actualizo la misma fila, no se creo otra.
        $this->assertSame($v16->id, $this->variantesDe($producto)->first()->id);
        $this->assertSame('55.00', (string) $producto->price);
        $this->assertSame(11, $producto->stock);
    }

    public function test_editar_sin_mandar_variantes_no_las_toca(): void
    {
        $producto = $this->crearMemoria();

        $this->comoDuenio()->putJson("/api/products/{$producto->id}", ['name' => 'Kingston Fury Beast'])
            ->assertOk();

        $this->assertCount(3, $this->variantesDe($producto));
    }

    public function test_mandar_la_lista_vacia_las_quita_y_la_ficha_vuelve_a_mandar(): void
    {
        $producto = $this->crearMemoria();

        $this->comoDuenio()->putJson("/api/products/{$producto->id}", [
            'variants' => [],
            'price' => 42,
            'stock' => 7,
        ])->assertOk();

        $producto->refresh();
        $this->assertCount(0, $this->variantesDe($producto));
        $this->assertSame('42.00', (string) $producto->price);
        $this->assertSame(7, $producto->stock);
    }

    public function test_dos_variantes_con_las_mismas_opciones_se_rechazan(): void
    {
        $this->comoDuenio()->postJson('/api/products', [
            'name' => 'Repetida',
            'variants' => [$this->variante('16 GB', 50, 1), $this->variante(' 16 gb ', 60, 1)],
        ])->assertStatus(422)->assertJsonValidationErrors(['variants.1.options']);
    }

    public function test_hay_un_techo_de_variantes_por_producto(): void
    {
        $muchas = array_map(
            fn ($i) => $this->variante("{$i} GB", 10, 1),
            range(1, ProductVariant::MAXIMO_POR_PRODUCTO + 1),
        );

        $this->comoDuenio()->postJson('/api/products', ['name' => 'Demasiadas', 'variants' => $muchas])
            ->assertStatus(422)->assertJsonValidationErrors(['variants']);
    }

    public function test_un_id_de_variante_de_otro_producto_no_se_adopta(): void
    {
        $memoria = $this->crearMemoria();
        $ajena = $this->varianteDe($memoria, '16 GB');

        $respuesta = $this->comoDuenio()->postJson('/api/products', [
            'name' => 'Otra memoria',
            'variants' => [$this->variante('16 GB', 1, 1, id: $ajena->id)],
        ])->assertCreated();

        $otra = Product::withoutTenant()->findOrFail($respuesta->json('id'));
        $this->assertNotSame($ajena->id, $this->variantesDe($otra)->first()->id);
        // La variante original sigue en su producto, intacta.
        $this->assertSame($memoria->id, $ajena->fresh()->product_id);
        $this->assertSame('50.00', (string) $ajena->fresh()->price);
    }

    public function test_para_el_plan_cuenta_la_ficha_y_no_cada_variante(): void
    {
        $this->crearMemoria();

        $this->comoDuenio()->getJson('/api/plan')->assertOk()->assertJsonPath('usage.products', 1);
    }

    public function test_cada_variante_puede_tener_su_foto_y_quitarla(): void
    {
        Storage::fake('public');

        $respuesta = $this->comoDuenio()->withHeader('Accept', 'application/json')->post('/api/products', [
            'name' => 'Gabinete',
            'variants' => json_encode([
                ['options' => [['name' => 'Color', 'value' => 'Negro']], 'price' => 90, 'stock' => 3],
                ['options' => [['name' => 'Color', 'value' => 'Blanco']], 'price' => 95, 'stock' => 2],
            ]),
            'variant_images' => [1 => UploadedFile::fake()->image('blanco.jpg', 800, 800)],
        ])->assertCreated();

        $producto = Product::withoutTenant()->findOrFail($respuesta->json('id'));
        [$negro, $blanco] = $this->variantesDe($producto)->all();

        $this->assertNull($negro->image_url);
        $this->assertNotNull($blanco->image_url);

        $this->comoDuenio()->putJson("/api/products/{$producto->id}", [
            'variants' => [
                ['id' => $negro->id, 'options' => [['name' => 'Color', 'value' => 'Negro']], 'price' => 90, 'stock' => 3],
                ['id' => $blanco->id, 'options' => [['name' => 'Color', 'value' => 'Blanco']], 'price' => 95, 'stock' => 2, 'remove_image' => true],
            ],
        ])->assertOk();

        $this->assertNull($blanco->fresh()->image_url);
    }

    public function test_duplicar_copia_las_variantes(): void
    {
        $producto = $this->crearMemoria();

        $copia = $this->comoDuenio()->postJson("/api/products/{$producto->id}/duplicate")->assertCreated();

        $this->assertCount(3, $this->variantesDe(Product::withoutTenant()->findOrFail($copia->json('id'))));
        $this->assertCount(3, $this->variantesDe($producto));
    }

    public function test_ajustar_precios_en_lote_ajusta_cada_variante(): void
    {
        $producto = $this->crearMemoria();

        $this->comoDuenio()->postJson('/api/products/bulk', [
            'product_ids' => [$producto->id],
            'bulk_action' => 'adjust_price',
            'price_adjustment' => 10,
        ])->assertOk();

        $this->assertSame('55.00', (string) $this->varianteDe($producto, '16 GB')->price);
        $this->assertSame('88.00', (string) $this->varianteDe($producto, '32 GB')->sale_price);
        $this->assertSame('33.00', (string) $producto->fresh()->price);
    }

    // --- Pedidos: se cobra y se descuenta la variante --------------------------

    public function test_el_checkout_cobra_el_precio_de_la_variante(): void
    {
        $producto = $this->crearMemoria();
        $v32 = $this->varianteDe($producto, '32 GB');

        $respuesta = $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '999',
            'items' => [['product_id' => $producto->id, 'variant_id' => $v32->id, 'quantity' => 2]],
        ])->assertCreated();

        // La oferta de la de 32 GB (80), no el resumen de la ficha (30).
        $this->assertSame('160.00', (string) $respuesta->json('total'));
        $respuesta->assertJsonPath('items.0.variant_name', '32 GB');
        $respuesta->assertJsonPath('items.0.product_name', 'Kingston Fury');
    }

    public function test_sin_elegir_variante_no_se_puede_pedir(): void
    {
        $producto = $this->crearMemoria();

        $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '999',
            'items' => [['product_id' => $producto->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_una_variante_de_otro_producto_o_de_otra_tienda_no_vale(): void
    {
        $memoria = $this->crearMemoria();

        $otraTienda = $this->crearTienda('tienda-b');
        $ajeno = new Product(['name' => 'Ajeno', 'price' => 1, 'stock' => 1, 'status' => 'published']);
        $ajeno->tenant_id = $otraTienda->id;
        $ajeno->save();
        $varianteAjena = new ProductVariant([
            'product_id' => $ajeno->id, 'options' => [['name' => 'X', 'value' => 'Y']], 'price' => 0.01, 'stock' => 5,
        ]);
        $varianteAjena->tenant_id = $otraTienda->id;
        $varianteAjena->save();

        $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '999',
            'items' => [['product_id' => $memoria->id, 'variant_id' => $varianteAjena->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, Order::withoutTenant()->count());
    }

    public function test_una_variante_para_un_producto_sin_variantes_no_vale(): void
    {
        $memoria = $this->crearMemoria();
        $simple = new Product(['name' => 'Simple', 'price' => 10, 'stock' => 5, 'status' => 'published']);
        $simple->tenant_id = $this->tienda->id;
        $simple->save();

        $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '999',
            'items' => [[
                'product_id' => $simple->id,
                'variant_id' => $this->varianteDe($memoria, '16 GB')->id,
                'quantity' => 1,
            ]],
        ])->assertStatus(422);
    }

    public function test_vender_y_cancelar_mueve_el_stock_de_la_variante_y_el_resumen(): void
    {
        $producto = $this->crearMemoria();
        $v16 = $this->varianteDe($producto, '16 GB');

        $pedido = $this->comoDuenio()->postJson('/api/orders', [
            'customer_name' => 'Mostrador',
            'status' => 'attended',
            'items' => [['product_id' => $producto->id, 'variant_id' => $v16->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(1, $v16->fresh()->stock);
        $this->assertSame(3, $producto->fresh()->stock);

        $this->comoDuenio()->putJson("/api/orders/{$pedido->json('id')}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(4, $v16->fresh()->stock);
        $this->assertSame(6, $producto->fresh()->stock);
    }

    public function test_si_la_variante_se_borro_cancelar_no_toca_ningun_stock(): void
    {
        $producto = $this->crearMemoria();
        $v16 = $this->varianteDe($producto, '16 GB');
        $v8 = $this->varianteDe($producto, '8 GB');

        $pedido = $this->comoDuenio()->postJson('/api/orders', [
            'customer_name' => 'Mostrador',
            'status' => 'attended',
            'items' => [['product_id' => $producto->id, 'variant_id' => $v16->id, 'quantity' => 1]],
        ])->assertCreated();

        // El dueño quita la de 16 GB.
        $this->comoDuenio()->putJson("/api/products/{$producto->id}", [
            'variants' => [$this->variante('8 GB', 30, 2, id: $v8->id)],
        ])->assertOk();

        $this->comoDuenio()->putJson("/api/orders/{$pedido->json('id')}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(2, $v8->fresh()->stock);
        $this->assertSame(2, $producto->fresh()->stock);
        // La linea conserva lo que se vendio.
        $this->assertDatabaseHas('order_items', ['variant_id' => null, 'variant_name' => '16 GB']);
    }

    // --- Lista de espera por variante ------------------------------------------

    public function test_apuntarse_pide_elegir_la_variante_agotada(): void
    {
        $producto = $this->crearMemoria();
        $url = "/api/public/{$this->tienda->slug}/products/{$producto->id}/notify-me";
        $datos = ['customer_name' => 'Luis', 'customer_contact' => 'luis@correo.com'];

        $this->postJson($url, $datos)->assertStatus(422);

        // La de 16 GB tiene stock: no hay nada que esperar.
        $this->postJson($url, $datos + ['variant_id' => $this->varianteDe($producto, '16 GB')->id])
            ->assertStatus(422);

        $this->postJson($url, $datos + ['variant_id' => $this->varianteDe($producto, '32 GB')->id])
            ->assertCreated();

        $this->assertDatabaseHas('stock_notifications', [
            'product_id' => $producto->id,
            'variant_id' => $this->varianteDe($producto, '32 GB')->id,
        ]);
    }

    public function test_reponer_una_variante_avisa_solo_a_quien_la_esperaba(): void
    {
        $producto = $this->crearMemoria();
        $v32 = $this->varianteDe($producto, '32 GB');
        $v8 = $this->varianteDe($producto, '8 GB');

        // Alguien espera la de 32 GB; otro, la de 8 GB (que se agota a mano).
        StockNotification::create([
            'tenant_id' => $this->tienda->id, 'product_id' => $producto->id, 'variant_id' => $v32->id,
            'customer_name' => 'Espera 32', 'customer_contact' => 'treinta@correo.com',
        ]);
        $v8->update(['stock' => 0]);
        StockNotification::create([
            'tenant_id' => $this->tienda->id, 'product_id' => $producto->id, 'variant_id' => $v8->id,
            'customer_name' => 'Espera 8', 'customer_contact' => 'ocho@correo.com',
        ]);

        $v32->update(['stock' => 5]);

        Notification::assertSentOnDemand(
            BackInStockNotification::class,
            fn ($notificacion, $canales, $notifiable) => $notifiable->routes['mail'] === 'treinta@correo.com'
                && str_contains($notificacion->aviso['producto'], '32 GB'),
        );
        Notification::assertSentOnDemandTimes(BackInStockNotification::class, 1);
        $this->assertNull(StockNotification::withoutTenant()->where('variant_id', $v8->id)->first()->notified_at);
    }

    /**
     * Devolver stock al cancelar tambien es reponer. Antes el descuento y la
     * devolucion eran un `increment()` sobre la consulta, que no dispara eventos:
     * cancelar la venta de la ultima unidad dejaba la pieza disponible y a quien
     * la esperaba sin enterarse. Ahora va por el modelo, en productos con y sin
     * variantes.
     */
    public function test_cancelar_una_venta_que_agoto_la_pieza_avisa_a_quien_esperaba(): void
    {
        $simple = new Product(['name' => 'Fuente 750W', 'price' => 90, 'stock' => 1, 'status' => 'published']);
        $simple->tenant_id = $this->tienda->id;
        $simple->save();

        $pedido = $this->comoDuenio()->postJson('/api/orders', [
            'customer_name' => 'Mostrador',
            'status' => 'attended',
            'items' => [['product_id' => $simple->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(0, $simple->fresh()->stock);

        StockNotification::create([
            'tenant_id' => $this->tienda->id, 'product_id' => $simple->id,
            'customer_name' => 'Espera', 'customer_contact' => 'espera@correo.com',
        ]);

        $this->comoDuenio()->putJson("/api/orders/{$pedido->json('id')}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(1, $simple->fresh()->stock);
        Notification::assertSentOnDemandTimes(BackInStockNotification::class, 1);
    }

    // --- Catalogo publico --------------------------------------------------------

    public function test_el_catalogo_trae_las_variantes_y_busca_por_su_sku(): void
    {
        $producto = $this->crearMemoria();
        $this->varianteDe($producto, '16 GB')->update(['sku' => 'KF432C16BB/16']);

        $this->getJson("/api/public/{$this->tienda->slug}/products?search=KF432C16")
            ->assertOk()
            ->assertJsonPath('data.0.id', $producto->id)
            ->assertJsonCount(3, 'data.0.variants');

        $this->getJson("/api/public/{$this->tienda->slug}/products/{$producto->id}")
            ->assertOk()
            ->assertJsonCount(3, 'variants')
            ->assertJsonPath('variants.0.nombre', '16 GB');
    }
}
