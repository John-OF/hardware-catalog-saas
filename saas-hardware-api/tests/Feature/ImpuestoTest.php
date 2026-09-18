<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Impuesto por tienda y cotización en PDF (`MOD-2`).
 *
 * Lo que de verdad se vigila aquí:
 *
 * 1. **Que encenderlo no cambie nada de lo ya vendido.** Una tienda que lleva
 *    seis meses facturando no puede ver moverse sus reportes porque desplegamos
 *    esto: los pedidos viejos guardan `tax_rate` a `null` y siguen contando
 *    igual.
 * 2. **Que el modo "incluido" no suba el catálogo de precio.** Es la diferencia
 *    entre las dos formas de cobrar y la razón de que se guarde por pedido.
 * 3. **Que el margen no se infle.** Con los precios llevando el impuesto dentro,
 *    parte de cada línea es del fisco; contarla como venta infla la utilidad en
 *    el porcentaje entero, y ese número decide precios.
 * 4. **Que el pedido y el reporte digan lo mismo.** Son dos cálculos distintos
 *    —uno en PHP, otro en SQL— y si solo uno descuenta el impuesto, el dueño ve
 *    dos márgenes de la misma venta.
 */
class ImpuestoTest extends TestCase
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
            'slug' => 'tienda-impuesto',
            'name' => 'Tienda Impuesto',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
            'plan' => 'enterprise',
            'currency' => 'PEN',
        ]);

        $this->admin = $this->usuario('admin', 'duenia@impuesto.test');
        $this->staff = $this->usuario('staff', 'vendedor@impuesto.test');
    }

    // ------------------------------------------------------- por defecto, nada

    public function test_una_tienda_nueva_no_cobra_impuesto_y_sus_pedidos_no_lo_llevan(): void
    {
        $this->assertFalse((bool) $this->tienda->tax_enabled);

        $producto = $this->producto(precio: 1000);
        $pedido = $this->venta($producto, 1);

        $this->assertNull($pedido->tax_rate);
        $this->assertNull($pedido->tax_amount);
        $this->assertSame('1000.00', $pedido->total);
        // Sin impuesto, la base imponible es el total entero: un pedido de antes
        // de MOD-2 se pinta igual que siempre.
        $this->assertSame(1000.0, $pedido->base_imponible);
    }

    // --------------------------------------------------------- precio incluido

    public function test_con_el_impuesto_incluido_el_total_no_cambia_y_se_desglosa(): void
    {
        $this->conImpuesto(18, incluido: true);

        $producto = $this->producto(precio: 2800);
        $pedido = $this->venta($producto, 1);

        // Lo importante: el comprador paga lo que decía el catálogo. Encender el
        // impuesto no sube los precios.
        $this->assertSame('2800.00', $pedido->total);
        // 2800 * 18 / 118 = 427.12, sacado hacia atrás y no 2800 * 0.18 = 504,
        // que es el error clásico y cobra de más.
        $this->assertSame('427.12', $pedido->tax_amount);
        $this->assertSame(2372.88, $pedido->base_imponible);
        $this->assertSame('IGV', $pedido->tax_name);
        $this->assertTrue((bool) $pedido->tax_included);
    }

    public function test_con_el_impuesto_sumado_el_total_crece(): void
    {
        $this->conImpuesto(18, incluido: false);

        $producto = $this->producto(precio: 1000);
        $pedido = $this->venta($producto, 1);

        $this->assertSame('1180.00', $pedido->total);
        $this->assertSame('180.00', $pedido->tax_amount);
        $this->assertSame(1000.0, $pedido->base_imponible);
        $this->assertFalse((bool) $pedido->tax_included);
    }

    public function test_una_tasa_de_cero_es_lo_mismo_que_no_cobrarlo(): void
    {
        // Guardar 0.00 haría que la cotización imprimiera una línea "IGV 0,00",
        // que no dice nada.
        $this->conImpuesto(0, incluido: true);

        $pedido = $this->venta($this->producto(precio: 500), 1);

        $this->assertNull($pedido->tax_rate);
        $this->assertNull($pedido->tax_amount);
    }

    // ----------------------------------------------------------- es un snapshot

    public function test_cambiar_el_impuesto_no_reescribe_lo_ya_vendido(): void
    {
        $this->conImpuesto(18, incluido: true);
        $antiguo = $this->venta($this->producto(precio: 1180), 1);

        $this->conImpuesto(10, incluido: true);
        $nuevo = $this->venta($this->producto(precio: 1180), 1);

        $this->assertSame('180.00', $antiguo->fresh()->tax_amount);
        $this->assertSame('18.00', $antiguo->fresh()->tax_rate);
        $this->assertSame('107.27', $nuevo->tax_amount);
    }

    public function test_apagar_el_impuesto_no_borra_el_desglose_de_lo_vendido(): void
    {
        $this->conImpuesto(18, incluido: true);
        $pedido = $this->venta($this->producto(precio: 1180), 1);

        $this->tienda->update(['tax_enabled' => false]);

        $this->assertSame('180.00', $pedido->fresh()->tax_amount);
    }

    // ------------------------------------------------------------ el checkout

    public function test_el_checkout_publico_cobra_el_impuesto_sobre_el_envio_tambien(): void
    {
        $this->conImpuesto(18, incluido: false);
        $this->tienda->update(['delivery_enabled' => true, 'delivery_cost' => 20]);

        $producto = $this->producto(precio: 100);

        $respuesta = $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Cliente',
            'customer_phone' => '999888777',
            'delivery_method' => 'delivery',
            'items' => [['product_id' => $producto->id, 'quantity' => 1]],
        ])->assertCreated();

        $pedido = Order::withoutTenant()->findOrFail($respuesta->json('id'));

        // Base 120 (producto + envío), impuesto 21.60, total 141.60.
        $this->assertSame('21.60', $pedido->tax_amount);
        $this->assertSame('141.60', $pedido->total);
    }

    public function test_el_catalogo_publico_dice_como_cobra_el_impuesto(): void
    {
        // Con el impuesto sumándose al final, el comprador TIENE que poder saber
        // que el total no va a ser la suma de su carrito.
        $this->conImpuesto(18, incluido: false);

        $tienda = $this->getJson("/api/public/{$this->tienda->slug}")->assertOk();

        $this->assertTrue($tienda->json('tax_enabled'));
        $this->assertSame('IGV', $tienda->json('tax_name'));
        $this->assertFalse($tienda->json('tax_included'));
    }

    // -------------------------------------------------------------- el margen

    public function test_el_impuesto_incluido_no_infla_la_utilidad(): void
    {
        $this->conImpuesto(18, incluido: true);

        // Se vende a 1180 (con IGV dentro: 1000 netos) y costó 600.
        $producto = $this->producto(precio: 1180, costo: 600);
        $pedido = $this->venta($producto, 1);

        // Sin descontar el impuesto saldría 580, un 18% de más sobre un número
        // que el dueño usa para poner precios.
        $this->assertSame(400.0, $pedido->fresh()->load('items')->utilidad);
    }

    public function test_el_pedido_y_el_reporte_dan_la_misma_utilidad(): void
    {
        $this->conImpuesto(18, incluido: true);

        $producto = $this->producto(precio: 1180, costo: 600);
        $pedido = $this->venta($producto, 1);

        $delPedido = $pedido->fresh()->load('items')->utilidad;
        $delReporte = (float) $this->reporte()->json('resumen.utilidad');

        // Son dos cálculos distintos —uno en PHP sobre el modelo, otro en SQL
        // sobre las líneas— y por eso comparten `App\Support\Impuesto`.
        $this->assertSame($delPedido, $delReporte);
        $this->assertSame(400.0, $delReporte);
    }

    public function test_el_margen_cuadra_con_una_tasa_que_no_divide_exacto(): void
    {
        // El caso anterior usa cifras redondas y el margen sale bien incluso con
        // una división entera, que es como se coló la trampa de SQLite hasta que
        // MOD-4 la destapó. Con 15% sobre 1234 el cociente ya no es exacto.
        $this->conImpuesto(15, incluido: true);

        $producto = $this->producto(precio: 1234, costo: 500);
        $pedido = $this->venta($producto, 1);

        // 1234 - (1234 * 15 / 115) = 1234 - 160.96 = 1073.04; menos 500 = 573.04.
        $this->assertSame(573.04, $pedido->fresh()->load('items')->utilidad);
        $this->assertSame(573.04, (float) $this->reporte()->json('resumen.utilidad'));
    }

    public function test_el_reporte_saca_el_impuesto_cobrado_aparte(): void
    {
        $this->conImpuesto(18, incluido: true);
        $this->venta($this->producto(precio: 1180), 1);

        $resumen = $this->reporte()->json('resumen');

        // `ventas` es lo que entró en caja; `impuesto`, cuánto de eso no es de la
        // tienda. Mismo trato que el envío (MOD-1).
        $this->assertSame(1180.0, (float) $resumen['ventas']);
        $this->assertSame(180.0, (float) $resumen['impuesto']);
    }

    public function test_con_el_impuesto_sumado_las_lineas_ya_son_netas(): void
    {
        $this->conImpuesto(18, incluido: false);

        $producto = $this->producto(precio: 1000, costo: 600);
        $pedido = $this->venta($producto, 1);

        // Aquí no hay nada que descontar: el subtotal de la línea es neto y el
        // impuesto se añadió encima.
        $this->assertSame(400.0, $pedido->fresh()->load('items')->utilidad);
        $this->assertSame(400.0, (float) $this->reporte()->json('resumen.utilidad'));
    }

    // ------------------------------------------------------------------- PDF

    public function test_el_pdf_de_la_cotizacion_se_descarga(): void
    {
        $this->conImpuesto(18, incluido: true);
        $pedido = $this->venta($this->producto(precio: 1180, nombre: 'RTX 4070'), 2);

        $respuesta = $this->comoAdmin()->get("/api/orders/{$pedido->id}/pdf")->assertOk();

        $this->assertSame('application/pdf', $respuesta->headers->get('content-type'));
        $this->assertStringContainsString(
            "cotizacion-{$this->tienda->slug}-{$pedido->number}.pdf",
            (string) $respuesta->headers->get('content-disposition'),
        );
        // Un PDF de verdad, no una página de error con cabecera de PDF.
        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());
    }

    public function test_staff_tambien_puede_cotizar(): void
    {
        // Quien atiende el mostrador es quien cotiza, y el PDF no lleva ningún
        // dato que staff no vea ya en la ficha del pedido.
        $pedido = $this->venta($this->producto(precio: 100), 1);

        $this->comoStaff()->get("/api/orders/{$pedido->id}/pdf")->assertOk();
    }

    public function test_una_tienda_no_cotiza_el_pedido_de_otra(): void
    {
        $otra = Tenant::create([
            'slug' => 'otra-impuesto', 'name' => 'Otra',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);

        $ajeno = new Order([
            'customer_name' => 'Ajeno', 'status' => 'pending', 'total' => 100,
        ]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        $this->comoAdmin()->get("/api/orders/{$ajeno->id}/pdf")->assertNotFound();
    }

    // ---------------------------------------------------------- configuración

    public function test_el_duenio_configura_el_impuesto_y_queda_anotado(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', [
            'tax_enabled' => true,
            'tax_name' => 'IVA',
            'tax_rate' => 12.5,
            'tax_included' => false,
        ])->assertOk();

        $this->tienda->refresh();

        $this->assertTrue((bool) $this->tienda->tax_enabled);
        $this->assertSame('IVA', $this->tienda->tax_name);
        $this->assertSame('12.50', $this->tienda->tax_rate);
        $this->assertFalse((bool) $this->tienda->tax_included);

        // Cambiar el impuesto cambia lo que paga el cliente en la venta
        // siguiente: tiene que quedar escrito quién lo tocó (INF-3).
        $linea = \App\Models\ActivityLog::latest('id')->first();
        $this->assertStringContainsString('impuesto', $linea->description);
    }

    public function test_staff_no_toca_el_impuesto(): void
    {
        $this->comoStaff()->putJson('/api/tenant', ['tax_enabled' => true])->assertForbidden();
    }

    // --------------------------------------------------------------- ayudas

    private function conImpuesto(float $tasa, bool $incluido): void
    {
        $this->tienda->update([
            'tax_enabled' => true,
            'tax_name' => 'IGV',
            'tax_rate' => $tasa,
            'tax_included' => $incluido,
        ]);
    }

    private function producto(float $precio, ?float $costo = null, string $nombre = 'Producto'): Product
    {
        $producto = new Product([
            'name' => $nombre, 'price' => $precio, 'cost' => $costo, 'stock' => 50,
            'is_active' => true, 'status' => 'published',
        ]);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    private function venta(Product $producto, int $cantidad): Order
    {
        $respuesta = $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente',
            'status' => 'attended',
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
