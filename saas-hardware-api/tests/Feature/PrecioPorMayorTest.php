<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PreciosPorCantidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Precio por cantidad — "precio por mayor" (`MOD-15`).
 *
 * Lo que de verdad se vigila aquí:
 *
 * 1. **Que un tramo sea un PRECIO y no un descuento.** De eso cuelga todo lo
 *    demás: que `unit_price` sea lo que se cobró, que `subtotal` siga siendo
 *    precio × cantidad, y que por tanto la utilidad del pedido y el margen del
 *    reporte no necesiten saber que los tramos existen. Si alguien lo convierte
 *    en un descuento, estos tests son los que lo cazan.
 * 2. **Que nunca cobre de más.** Quien compra diez no puede acabar pagando más
 *    por unidad que quien compra una, pase lo que pase con la configuración.
 * 3. **Que el navegador no pueda elegir el precio.** Solo viaja la cantidad, y
 *    el tramo lo resuelve el servidor sobre sus propios números — la misma regla
 *    que el costo del envío (`MOD-1`) y el descuento del cupón (`MOD-4`).
 * 4. **Que se acumule con el cupón sin reordenar el cálculo.** El tramo fija a
 *    cuánto sale el producto; el cupón descuenta de lo que el producto cueste.
 * 5. **Que con variantes cobre la variante**, como manda `MOD-5`: los tramos de
 *    la ficha son un resumen y no cobran nada.
 */
class PrecioPorMayorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tienda = Tenant::create([
            'slug' => 'tienda-mayorista',
            'name' => 'Tienda Mayorista',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
            'plan' => 'enterprise',
        ]);

        $this->admin = $this->usuario('admin', 'duenia@mayorista.test');
    }

    // --------------------------------------------------------------- el cálculo

    public function test_manda_el_tramo_mas_alto_que_la_cantidad_alcanza(): void
    {
        $tramos = [['min' => 10, 'price' => 90], ['min' => 25, 'price' => 80]];

        $this->assertSame(100.0, PreciosPorCantidad::precioPara(100, $tramos, 9));
        $this->assertSame(90.0, PreciosPorCantidad::precioPara(100, $tramos, 10));
        $this->assertSame(90.0, PreciosPorCantidad::precioPara(100, $tramos, 24));
        $this->assertSame(80.0, PreciosPorCantidad::precioPara(100, $tramos, 25));
        $this->assertSame(80.0, PreciosPorCantidad::precioPara(100, $tramos, 1000));
    }

    public function test_nunca_cobra_mas_que_el_precio_normal(): void
    {
        // El dueño pone una oferta de 70 por debajo de su propio precio por mayor
        // de 90. Quien compra diez no puede pagar más que quien compra una: sería
        // el mundo al revés, y lo vería el comprador antes que el dueño.
        $tramos = [['min' => 10, 'price' => 90]];

        $this->assertSame(70.0, PreciosPorCantidad::precioPara(70, $tramos, 10));
    }

    public function test_sin_tramos_el_precio_es_el_de_siempre(): void
    {
        $this->assertSame(100.0, PreciosPorCantidad::precioPara(100, null, 50));
        $this->assertSame(100.0, PreciosPorCantidad::precioPara(100, [], 50));
    }

    public function test_al_normalizar_se_ordena_y_se_tira_lo_que_no_se_entiende(): void
    {
        // Una fila escrita a mano en la base, o por una versión anterior, no
        // puede hacer que el catálogo cobre cualquier cosa.
        $normalizados = PreciosPorCantidad::normalizar([
            ['min' => 25, 'price' => 80],
            ['min' => 10, 'price' => 90],
            ['min' => 1, 'price' => 99],          // por debajo del mínimo: fuera
            ['min' => 'diez', 'price' => 50],     // no es un número: fuera
            ['precio' => 5],                       // sin las dos claves: fuera
            ['min' => 50, 'price' => -3],          // negativo: fuera
        ]);

        $this->assertSame([
            ['min' => 10, 'price' => 90.0],
            ['min' => 25, 'price' => 80.0],
        ], $normalizados);
    }

    public function test_con_dos_tramos_para_la_misma_cantidad_gana_el_mas_barato(): void
    {
        // Es el único desempate que no le cobra de más al comprador.
        $normalizados = PreciosPorCantidad::normalizar([
            ['min' => 10, 'price' => 95],
            ['min' => 10, 'price' => 85],
        ]);

        $this->assertSame([['min' => 10, 'price' => 85.0]], $normalizados);
    }

    public function test_no_se_guardan_mas_tramos_que_el_techo(): void
    {
        $muchos = [];

        for ($i = 2; $i < 20; $i++) {
            $muchos[] = ['min' => $i, 'price' => 100 - $i];
        }

        $this->assertCount(PreciosPorCantidad::MAXIMO_DE_TRAMOS, PreciosPorCantidad::normalizar($muchos));
    }

    public function test_una_lista_vacia_se_guarda_como_null(): void
    {
        // La base distingue "sin tramos" de "una lista vacía"; guardar `[]`
        // dejaría dos formas que todo lo que lee tendría que comprobar.
        $this->assertNull(PreciosPorCantidad::paraGuardar([]));
        $this->assertNull(PreciosPorCantidad::paraGuardar([['min' => 1, 'price' => 5]]));
        $this->assertNotNull(PreciosPorCantidad::paraGuardar([['min' => 5, 'price' => 5]]));
    }

    // ----------------------------------------------------------- lo que se cobra

    public function test_un_pedido_que_llega_al_tramo_se_cobra_al_precio_del_tramo(): void
    {
        $producto = $this->producto(precio: 100, tramos: [['min' => 10, 'price' => 90]]);

        $pedido = $this->pedidoPublico($producto, 10);
        $linea = $pedido->items->first();

        // Lo que hace que todo lo demás siga funcionando: `unit_price` es el
        // precio al que de verdad se vendió y `subtotal` sigue siendo precio ×
        // cantidad. Nada quedó como "descuento" en el pedido.
        $this->assertSame('90.00', $linea->unit_price);
        $this->assertSame('900.00', $linea->subtotal);
        $this->assertSame('900.00', $pedido->total);
        $this->assertNull($pedido->discount_amount);
    }

    public function test_por_debajo_del_tramo_se_cobra_el_precio_de_siempre(): void
    {
        $producto = $this->producto(precio: 100, tramos: [['min' => 10, 'price' => 90]]);

        $pedido = $this->pedidoPublico($producto, 9);

        $this->assertSame('100.00', $pedido->items->first()->unit_price);
        $this->assertSame('900.00', $pedido->total);
    }

    public function test_el_navegador_no_puede_elegir_el_precio(): void
    {
        // Solo viaja la cantidad. Un comprador que mande su propio precio —o su
        // propio tramo— no cambia nada: el servidor resuelve sobre sus números,
        // igual que con el costo del envío (MOD-1) y el cupón (MOD-4).
        $producto = $this->producto(precio: 100, tramos: [['min' => 10, 'price' => 90]]);

        $respuesta = $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Cliente',
            'customer_phone' => '999888777',
            'items' => [[
                'product_id' => $producto->id,
                'quantity' => 2,
                'unit_price' => 1,
                'price_tiers' => [['min' => 2, 'price' => 1]],
            ]],
        ])->assertCreated();

        $pedido = Order::withoutTenant()->with('items')->findOrFail($respuesta->json('id'));

        $this->assertSame('100.00', $pedido->items->first()->unit_price);
        $this->assertSame('200.00', $pedido->total);
    }

    public function test_la_oferta_gana_cuando_es_mas_barata_que_el_tramo(): void
    {
        $producto = $this->producto(precio: 100, tramos: [['min' => 10, 'price' => 90]]);
        $producto->update(['sale_price' => 70]);

        $pedido = $this->pedidoPublico($producto, 10);

        $this->assertSame('70.00', $pedido->items->first()->unit_price);
    }

    public function test_la_venta_de_mostrador_cobra_el_mismo_tramo_que_el_catalogo(): void
    {
        // Los dos caminos que crean pedidos pasan por `OrderPricing`, que es
        // justo para lo que existe: si uno cobrara el tramo y el otro no, la
        // misma venta daría dos totales según por dónde entrara.
        $producto = $this->producto(precio: 100, tramos: [['min' => 10, 'price' => 90]]);

        $respuesta = $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente Mostrador',
            'customer_phone' => '999888777',
            'status' => 'pending',
            'items' => [['product_id' => $producto->id, 'quantity' => 10]],
        ])->assertCreated();

        $this->assertSame('900.00', (string) $respuesta->json('total'));
    }

    // ------------------------------------------------------------- con variantes

    public function test_con_variantes_cobra_el_tramo_de_la_variante(): void
    {
        $producto = $this->producto(precio: 100);
        $barata = $this->variante($producto, ['Capacidad' => '1 TB'], 400, [['min' => 5, 'price' => 380]]);
        $this->variante($producto, ['Capacidad' => '2 TB'], 700, [['min' => 5, 'price' => 650]]);
        $producto->sincronizarResumenDeVariantes();

        $pedido = $this->pedidoPublico($producto, 5, varianteId: $barata->id);

        $this->assertSame('380.00', $pedido->items->first()->unit_price);
        $this->assertSame('1900.00', $pedido->total);
    }

    public function test_la_ficha_resume_los_tramos_de_la_variante_mas_barata(): void
    {
        // Mismo criterio que el precio y el costo (MOD-5, MOD-6): la ficha
        // describe siempre a la misma variante, la más barata. Así la tarjeta del
        // catálogo puede decir que hay precio por mayor sin cargar las variantes.
        $producto = $this->producto(precio: 100);
        $this->variante($producto, ['Capacidad' => '2 TB'], 700, [['min' => 5, 'price' => 650]]);
        $this->variante($producto, ['Capacidad' => '1 TB'], 400, [['min' => 5, 'price' => 380]]);
        $producto->sincronizarResumenDeVariantes();

        $this->assertSame([['min' => 5, 'price' => 380.0]], $producto->fresh()->price_tiers);
    }

    // ---------------------------------------------------------------- el cupón

    public function test_el_tramo_y_el_cupon_se_acumulan_sin_reordenar_el_calculo(): void
    {
        // El tramo fija a cuánto sale el producto; el cupón descuenta de lo que
        // el producto cueste. Es la misma relación que el cupón tiene ya con
        // `sale_price`, que nadie consideró un conflicto.
        $this->cupon('VEINTE', 'percent', 20);
        $producto = $this->producto(precio: 100, tramos: [['min' => 10, 'price' => 90]]);

        $pedido = $this->pedidoPublico($producto, 10, 'VEINTE');

        // 10 × 90 = 900 de productos, y el 20% se calcula sobre esos 900 (no
        // sobre los 1000 del precio de lista).
        $this->assertSame('900.00', $pedido->items_subtotal);
        $this->assertSame('180.00', $pedido->discount_amount);
        $this->assertSame('720.00', $pedido->total);
    }

    public function test_el_tramo_entra_antes_del_envio_y_del_impuesto(): void
    {
        $this->tienda->update([
            'delivery_enabled' => true, 'delivery_cost' => 20,
            'tax_enabled' => true, 'tax_name' => 'IGV', 'tax_rate' => 18, 'tax_included' => false,
        ]);
        $producto = $this->producto(precio: 100, tramos: [['min' => 10, 'price' => 90]]);

        $pedido = $this->pedidoPublico($producto, 10, entrega: 'delivery');

        // productos 900 → + envío 20 = 920 → + 18% = 1085.60.
        $this->assertSame(920.0, $pedido->base_imponible);
        $this->assertSame('165.60', $pedido->tax_amount);
        $this->assertSame('1085.60', $pedido->total);
    }

    // ---------------------------------------------------------------- el margen

    public function test_la_utilidad_sale_del_precio_que_de_verdad_se_cobro(): void
    {
        // Vendido a 90 (tramo) lo que costó 70: 20 por unidad, 200 en diez. Con
        // el precio de lista saldría 300, que es dinero que nadie pagó.
        $producto = $this->producto(precio: 100, costo: 70, tramos: [['min' => 10, 'price' => 90]]);

        $pedido = $this->pedidoPublico($producto, 10);

        $this->assertSame(200.0, $pedido->fresh()->load('items')->utilidad);
    }

    public function test_el_pedido_y_el_reporte_dan_la_misma_utilidad_con_tramo_y_cupon(): void
    {
        // La prueba de que modelar el tramo como precio fue lo correcto: dos
        // cálculos distintos —PHP sobre el modelo, SQL sobre las líneas— y
        // ninguno de los dos sabe que los tramos existen.
        $this->cupon('TERCIO', 'fixed', 333);
        $producto = $this->producto(precio: 111, costo: 40, tramos: [['min' => 9, 'price' => 99]]);

        $pedido = $this->pedidoPublico($producto, 9, 'TERCIO');
        $this->comoAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'attended'])->assertOk();

        $delPedido = $pedido->fresh()->load('items')->utilidad;
        $delReporte = (float) $this->reporte()->json('resumen.utilidad');

        // 9 × 99 = 891 cobrados, menos 333 del cupón = 558; costó 9 × 40 = 360.
        $this->assertSame($delPedido, $delReporte);
        $this->assertSame(198.0, $delReporte);
    }

    public function test_el_reporte_no_cuenta_el_tramo_como_descuento(): void
    {
        // `descuento` es lo que costaron las campañas de cupones. Un precio por
        // mayor no es una campaña: es el precio, igual que `sale_price`, y
        // meterlo ahí haría que el dueño creyera que regaló lo que nunca cobró.
        $producto = $this->producto(precio: 100, tramos: [['min' => 10, 'price' => 90]]);

        $pedido = $this->pedidoPublico($producto, 10);
        $this->comoAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'attended'])->assertOk();

        $resumen = $this->reporte()->json('resumen');

        $this->assertSame(900.0, (float) $resumen['ventas']);
        $this->assertSame(0.0, (float) $resumen['descuento']);
    }

    // ------------------------------------------------------------ la validación

    public function test_un_tramo_no_puede_costar_mas_que_el_precio_normal(): void
    {
        // Nunca se llegaría a cobrar —`precioPara()` no pasa del precio normal—,
        // así que guardarlo dejaría al dueño creyendo que hizo algo.
        $this->crear(['price_tiers' => [['min' => 10, 'price' => 120]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_tiers.0.price');
    }

    public function test_cada_tramo_tiene_que_costar_menos_que_el_anterior(): void
    {
        $this->crear(['price_tiers' => [['min' => 10, 'price' => 90], ['min' => 25, 'price' => 95]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_tiers.1.price');
    }

    public function test_el_orden_en_que_se_escriben_los_tramos_no_cambia_el_veredicto(): void
    {
        // El formulario los manda como el dueño los escribió; "a más unidades,
        // menos precio" solo significa algo sobre la lista ordenada por cantidad.
        $this->crear(['price_tiers' => [['min' => 25, 'price' => 80], ['min' => 10, 'price' => 90]]])
            ->assertCreated();
    }

    public function test_no_se_admiten_dos_tramos_para_la_misma_cantidad(): void
    {
        $this->crear(['price_tiers' => [['min' => 10, 'price' => 90], ['min' => 10, 'price' => 85]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_tiers.1.min');
    }

    public function test_un_tramo_no_puede_empezar_en_una_unidad(): void
    {
        // Un tramo "desde 1" no es un precio por mayor: es el precio, y para eso
        // está la oferta. Dos sitios diciendo lo mismo acaban no coincidiendo.
        $this->crear(['price_tiers' => [['min' => 1, 'price' => 90]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_tiers.0.min');
    }

    public function test_no_se_admiten_mas_tramos_que_el_techo(): void
    {
        $muchos = [];

        for ($i = 0; $i <= PreciosPorCantidad::MAXIMO_DE_TRAMOS; $i++) {
            $muchos[] = ['min' => 10 + $i, 'price' => 90 - $i];
        }

        $this->crear(['price_tiers' => $muchos])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_tiers');
    }

    public function test_se_guardan_ordenados_aunque_lleguen_al_reves(): void
    {
        $id = $this->crear(['price_tiers' => [['min' => 25, 'price' => 80], ['min' => 10, 'price' => 90]]])
            ->assertCreated()
            ->json('id');

        $this->assertSame([
            ['min' => 10, 'price' => 90.0],
            ['min' => 25, 'price' => 80.0],
        ], Product::withoutTenant()->findOrFail($id)->price_tiers);
    }

    public function test_una_lista_vacia_quita_los_tramos(): void
    {
        $id = $this->crear(['price_tiers' => [['min' => 10, 'price' => 90]]])->json('id');

        $this->comoAdmin()->putJson("/api/products/{$id}", ['price_tiers' => []])->assertOk();

        $this->assertNull(Product::withoutTenant()->findOrFail($id)->price_tiers);
    }

    public function test_una_edicion_que_no_los_manda_no_los_toca(): void
    {
        // Misma convención que el costo (MOD-6) y que las variantes: ausente es
        // "no lo toques". Sin esto, cualquier edición parcial los borraría.
        $id = $this->crear(['price_tiers' => [['min' => 10, 'price' => 90]]])->json('id');

        $this->comoAdmin()->putJson("/api/products/{$id}", ['name' => 'Otro nombre'])->assertOk();

        $this->assertSame([['min' => 10, 'price' => 90.0]], Product::withoutTenant()->findOrFail($id)->price_tiers);
    }

    // ------------------------------------------------------------------- el CSV

    public function test_el_texto_del_csv_va_y_vuelve(): void
    {
        $tramos = [['min' => 10, 'price' => 90.0], ['min' => 25, 'price' => 85.5]];

        $this->assertSame('10:90|25:85.5', PreciosPorCantidad::aTexto($tramos));
        $this->assertSame($tramos, PreciosPorCantidad::desdeTexto('10:90|25:85.5'));
        // Tolerante con lo que alguien escribe en una hoja de cálculo.
        $this->assertSame($tramos, PreciosPorCantidad::desdeTexto(' 25 : 85.50 ; 10 : 90 '));
        $this->assertNull(PreciosPorCantidad::desdeTexto(''));
        $this->assertNull(PreciosPorCantidad::desdeTexto('sin sentido'));
    }

    public function test_exportar_y_reimportar_conserva_el_precio_por_mayor(): void
    {
        // La promesa que dejó escrita MOD-12 sobre las variantes, aplicada a los
        // tramos: sin la columna, el dueño perdía lo que había negociado y nadie
        // se lo decía.
        $this->crear([
            'name' => 'Memoria Kingston',
            'price_tiers' => [['min' => 10, 'price' => 90], ['min' => 25, 'price' => 80]],
        ])->assertCreated();

        $csv = $this->comoAdmin()->get('/api/products/export')->streamedContent();

        $this->assertStringContainsString('10:90|25:80', $csv);

        [$destino, $duenia] = $this->tiendaQueRecibe();

        $this->app['auth']->forgetGuards();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$duenia->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $destino->slug,
        ])->post('/api/products/import', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
        ])->assertOk()->assertJsonPath('success_count', 1);

        $importado = Product::withoutTenant()->where('tenant_id', $destino->id)->firstOrFail();

        $this->assertSame([
            ['min' => 10, 'price' => 90.0],
            ['min' => 25, 'price' => 80.0],
        ], $importado->price_tiers);
    }

    public function test_el_import_rechaza_una_fila_cuyo_tramo_no_baja_del_precio(): void
    {
        $csv = "nombre;precio;stock;tramos\nMemoria;100;5;10:120\n";

        $respuesta = $this->comoAdmin()->post('/api/products/import', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
        ])->assertOk();

        $this->assertSame(0, $respuesta->json('success_count'));
        $this->assertStringContainsString('precio por mayor', $respuesta->json('errors.0'));
    }

    // ----------------------------------------------------------------- ayudantes

    /** @param array<string, mixed> $extra */
    private function crear(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->comoAdmin()->postJson('/api/products', array_merge([
            'name' => 'Memoria', 'price' => 100, 'stock' => 50, 'status' => 'published',
        ], $extra));
    }

    /** @param array<int, array{min: int, price: float|int}>|null $tramos */
    private function producto(float $precio, ?float $costo = null, ?array $tramos = null, int $stock = 100): Product
    {
        $producto = new Product([
            'name' => 'Producto', 'price' => $precio, 'cost' => $costo, 'stock' => $stock,
            'price_tiers' => $tramos, 'is_active' => true, 'status' => 'published',
        ]);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    /**
     * @param  array<string, string>  $opciones
     * @param  array<int, array{min: int, price: float|int}>|null  $tramos
     */
    private function variante(Product $producto, array $opciones, float $precio, ?array $tramos = null): ProductVariant
    {
        $variante = new ProductVariant([
            'product_id' => $producto->id,
            'options' => collect($opciones)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all(),
            'price' => $precio, 'stock' => 100, 'price_tiers' => $tramos,
        ]);
        $variante->tenant_id = $this->tienda->id;
        $variante->save();

        return $variante;
    }

    private function cupon(string $codigo, string $tipo, float $valor): Coupon
    {
        $cupon = new Coupon(['code' => $codigo, 'type' => $tipo, 'value' => $valor, 'is_active' => true]);
        $cupon->tenant_id = $this->tienda->id;
        $cupon->save();

        return $cupon;
    }

    private function pedidoPublico(Product $producto, int $cantidad, ?string $codigo = null, ?string $entrega = null, ?string $varianteId = null): Order
    {
        $respuesta = $this->postJson("/api/public/{$this->tienda->slug}/orders", array_filter([
            'customer_name' => 'Cliente',
            'customer_phone' => '999888777',
            'coupon_code' => $codigo,
            'delivery_method' => $entrega,
            'items' => [array_filter([
                'product_id' => $producto->id,
                'variant_id' => $varianteId,
                'quantity' => $cantidad,
            ])],
        ]))->assertCreated();

        return Order::withoutTenant()->with('items')->findOrFail($respuesta->json('id'));
    }

    private function reporte(): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->comoAdmin()->getJson('/api/reports')->assertOk();
    }

    /** @return array{0: Tenant, 1: User} */
    private function tiendaQueRecibe(): array
    {
        $destino = Tenant::create([
            'slug' => 'tienda-destino',
            'name' => 'Tienda Destino',
            'whatsapp_number' => '51999999998',
            'is_active' => true,
            'is_published' => true,
            'plan' => 'enterprise',
        ]);

        $duenia = new User([
            'name' => 'Dueña', 'email' => 'duenia@destino.test', 'password' => 'password123',
            'role' => 'admin', 'is_active' => true,
        ]);
        $duenia->tenant_id = $destino->id;
        $duenia->save();

        return [$destino, $duenia];
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
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }
}
