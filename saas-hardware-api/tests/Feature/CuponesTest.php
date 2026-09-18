<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cupones de descuento (`MOD-4`).
 *
 * Lo que de verdad se vigila aquí:
 *
 * 1. **El orden de las operaciones.** productos → descuento → envío → impuesto.
 *    Cambiarlo no da error: da otro total, y uno que parece razonable.
 * 2. **Que el descuento no llegue desde el navegador.** Solo viaja el código; el
 *    descuento lo calcula el servidor sobre sus propios precios.
 * 3. **Que el margen lo note.** Un cupón es dinero que no se cobró; si la
 *    utilidad no lo resta, el dueño cree que ganó lo que regaló.
 * 4. **Que el tope de usos aguante dos compradores a la vez**, que es cuando de
 *    verdad se rompe un contador.
 */
class CuponesTest extends TestCase
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
            'slug' => 'tienda-cupones',
            'name' => 'Tienda Cupones',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
            'plan' => 'enterprise',
        ]);

        $this->admin = $this->usuario('admin', 'duenia@cupones.test');
        $this->staff = $this->usuario('staff', 'vendedor@cupones.test');
    }

    // ------------------------------------------------------------- el cálculo

    public function test_un_cupon_de_porcentaje_descuenta_sobre_los_productos(): void
    {
        $this->cupon('VERANO25', 'percent', 25);
        $producto = $this->producto(precio: 1000);

        $pedido = $this->pedidoPublico($producto, 2, 'VERANO25');

        $this->assertSame('2000.00', $pedido->items_subtotal);
        $this->assertSame('500.00', $pedido->discount_amount);
        $this->assertSame('1500.00', $pedido->total);
        $this->assertSame('VERANO25', $pedido->coupon_code);
    }

    public function test_un_cupon_de_monto_fijo_nunca_deja_el_total_en_negativo(): void
    {
        // Un cupón de 100 sobre una compra de 60 descuenta 60, no 100: si no, la
        // tienda acabaría debiéndole dinero al comprador.
        $this->cupon('REGALO100', 'fixed', 100);
        $producto = $this->producto(precio: 60);

        $pedido = $this->pedidoPublico($producto, 1, 'REGALO100');

        $this->assertSame('60.00', $pedido->discount_amount);
        $this->assertSame('0.00', $pedido->total);
    }

    public function test_el_descuento_no_toca_el_envio(): void
    {
        // Lo que la tienda le paga al repartidor no baja porque el comprador
        // tenga un código.
        $this->tienda->update(['delivery_enabled' => true, 'delivery_cost' => 20]);
        $this->cupon('MITAD', 'percent', 50);
        $producto = $this->producto(precio: 100);

        $pedido = $this->pedidoPublico($producto, 1, 'MITAD', entrega: 'delivery');

        // 100 - 50 + 20 = 70. Con el envío dentro del descuento saldría 60.
        $this->assertSame('50.00', $pedido->discount_amount);
        $this->assertSame('70.00', $pedido->total);
    }

    public function test_el_descuento_va_antes_del_impuesto(): void
    {
        // Al revés, el cupón descontaría también de la parte que es del fisco,
        // que la tienda paga igual: le costaría más del 25% que cree dar.
        $this->tienda->update([
            'tax_enabled' => true, 'tax_name' => 'IGV', 'tax_rate' => 18, 'tax_included' => false,
        ]);
        $this->cupon('VERANO25', 'percent', 25);
        $producto = $this->producto(precio: 1000);

        $pedido = $this->pedidoPublico($producto, 1, 'VERANO25');

        // 1000 - 250 = 750 de base, +18% = 885.
        $this->assertSame(750.0, $pedido->base_imponible);
        $this->assertSame('135.00', $pedido->tax_amount);
        $this->assertSame('885.00', $pedido->total);
    }

    public function test_el_codigo_no_distingue_mayusculas_ni_espacios_de_sobra(): void
    {
        // El comprador lo copia de una historia de Instagram y lo pega con un
        // espacio detrás; el teclado del móvil le pone la primera en mayúscula.
        $this->cupon('VERANO25', 'percent', 10);
        $producto = $this->producto(precio: 100);

        $pedido = $this->pedidoPublico($producto, 1, '  verano25 ');

        $this->assertSame('10.00', $pedido->discount_amount);
        $this->assertSame('VERANO25', $pedido->coupon_code);
    }

    public function test_el_navegador_manda_el_codigo_y_nunca_el_descuento(): void
    {
        $this->cupon('DIEZ', 'percent', 10);
        $producto = $this->producto(precio: 100);

        $respuesta = $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Cliente',
            'customer_phone' => '999888777',
            'coupon_code' => 'DIEZ',
            // Lo que se intenta colar: no existe como campo, así que se ignora.
            'discount_amount' => 999,
            'total' => 1,
            'items' => [['product_id' => $producto->id, 'quantity' => 1]],
        ])->assertCreated();

        $pedido = Order::withoutTenant()->findOrFail($respuesta->json('id'));

        $this->assertSame('10.00', $pedido->discount_amount);
        $this->assertSame('90.00', $pedido->total);
    }

    // ------------------------------------------------------------ los límites

    public function test_un_cupon_vencido_o_futuro_se_rechaza_diciendo_cual_es(): void
    {
        $this->cupon('VIEJO', 'percent', 10, ['ends_at' => now()->subDay()]);
        $this->cupon('PRONTO', 'percent', 10, ['starts_at' => now()->addDay()]);
        $producto = $this->producto(precio: 100);

        $this->assertSame(
            'Ese código ya venció.',
            $this->pedidoRechazado($producto, 'VIEJO'),
        );
        $this->assertSame(
            'Ese código todavía no está activo.',
            $this->pedidoRechazado($producto, 'PRONTO'),
        );
    }

    public function test_un_cupon_apagado_no_vale_aunque_siga_vigente(): void
    {
        $this->cupon('APAGADO', 'percent', 10, ['is_active' => false]);

        $this->assertSame(
            'Ese código ya no está disponible.',
            $this->pedidoRechazado($this->producto(precio: 100), 'APAGADO'),
        );
    }

    public function test_la_compra_minima_corta_el_cupon(): void
    {
        $this->cupon('GRANDE', 'percent', 25, ['min_purchase' => 500]);

        $this->assertSame(
            'Ese código pide una compra mínima mayor.',
            $this->pedidoRechazado($this->producto(precio: 100), 'GRANDE'),
        );
    }

    public function test_un_codigo_que_no_existe_no_dice_de_quien_es(): void
    {
        // Otra tienda tiene ese código: decir "no es tuyo" le confirmaría a quien
        // prueba que ha acertado uno ajeno.
        $otra = Tenant::create([
            'slug' => 'otra-cupones', 'name' => 'Otra',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);
        $ajeno = new Coupon(['code' => 'AJENO', 'type' => 'percent', 'value' => 50, 'is_active' => true]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        $this->assertSame(
            'Ese código no existe.',
            $this->pedidoRechazado($this->producto(precio: 100), 'AJENO'),
        );
    }

    public function test_el_tope_de_usos_se_agota_y_no_se_pasa(): void
    {
        $this->cupon('PRIMEROS2', 'percent', 10, ['max_uses' => 2]);
        $producto = $this->producto(precio: 100, stock: 50);

        $this->pedidoPublico($producto, 1, 'PRIMEROS2');
        $this->pedidoPublico($producto, 1, 'PRIMEROS2');

        $this->assertSame(
            'Ese código ya se agotó.',
            $this->pedidoRechazado($producto, 'PRIMEROS2'),
        );

        $this->assertSame(2, Coupon::withoutTenant()->porCodigo('PRIMEROS2')->first()->used_count);
    }

    public function test_el_uso_no_se_devuelve_al_cancelar_el_pedido(): void
    {
        // Devolverlo abriría pedir, cancelar y volver a pedir para estirar una
        // campaña limitada.
        $this->cupon('UNICO', 'percent', 10, ['max_uses' => 1]);
        $producto = $this->producto(precio: 100);

        $pedido = $this->pedidoPublico($producto, 1, 'UNICO');

        $this->comoAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(1, Coupon::withoutTenant()->porCodigo('UNICO')->first()->used_count);
    }

    // ------------------------------------------------------------- el margen

    public function test_el_cupon_se_reparte_en_la_utilidad_del_pedido(): void
    {
        $this->cupon('MITAD', 'percent', 50);
        // Se vende a 1000 y costó 600; con el cupón se cobran 500, así que se
        // pierden 100. Sin repartir el descuento saldría una utilidad de 400.
        $producto = $this->producto(precio: 1000, costo: 600);

        $pedido = $this->pedidoPublico($producto, 1, 'MITAD');

        $this->assertSame(-100.0, $pedido->fresh()->load('items')->utilidad);
    }

    public function test_el_pedido_y_el_reporte_dan_la_misma_utilidad_con_cupon(): void
    {
        $this->cupon('VEINTE', 'percent', 20);
        $producto = $this->producto(precio: 1000, costo: 600);

        $pedido = $this->pedidoPublico($producto, 1, 'VEINTE');
        $this->comoAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'attended'])->assertOk();

        $delPedido = $pedido->fresh()->load('items')->utilidad;
        $delReporte = (float) $this->reporte()->json('resumen.utilidad');

        // Dos cálculos distintos —PHP sobre el modelo, SQL sobre las líneas— y
        // por eso comparten `App\Support\Cupones`.
        $this->assertSame($delPedido, $delReporte);
        $this->assertSame(200.0, $delReporte);
    }

    public function test_el_margen_cuadra_con_cifras_que_no_dividen_exacto(): void
    {
        // Este es el caso que caza la división entera de SQLite: con 1000 y 200
        // el cociente sale redondo y un `/` entero daría 0 sin que se note; con
        // 333 sobre 999 el error se ve en el último decimal. La regla del
        // proyecto sobre escribir SQL para los DOS motores no es solo para las
        // funciones de fecha.
        $this->cupon('TERCIO', 'fixed', 333);
        $producto = $this->producto(precio: 999, costo: 400);

        $pedido = $this->pedidoPublico($producto, 1, 'TERCIO');
        $this->comoAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'attended'])->assertOk();

        // Se cobran 666 y costó 400.
        $this->assertSame(266.0, $pedido->fresh()->load('items')->utilidad);
        $this->assertSame(266.0, (float) $this->reporte()->json('resumen.utilidad'));
    }

    public function test_el_reporte_saca_lo_descontado_aparte(): void
    {
        $this->cupon('VEINTE', 'percent', 20);
        $pedido = $this->pedidoPublico($this->producto(precio: 1000), 1, 'VEINTE');
        $this->comoAdmin()->putJson("/api/orders/{$pedido->id}", ['status' => 'attended'])->assertOk();

        $resumen = $this->reporte()->json('resumen');

        // `ventas` ya viene con el descuento restado; sin esta línea no habría
        // forma de saber cuánto costó la campaña.
        $this->assertSame(800.0, (float) $resumen['ventas']);
        $this->assertSame(200.0, (float) $resumen['descuento']);
    }

    // ------------------------------------------------- comprobar desde el carrito

    public function test_el_carrito_comprueba_el_codigo_antes_de_confirmar(): void
    {
        $this->cupon('VERANO25', 'percent', 25);

        $respuesta = $this->postJson("/api/public/{$this->tienda->slug}/coupons/check", [
            'code' => 'verano25',
            'subtotal' => 400,
        ])->assertOk();

        $this->assertSame('VERANO25', $respuesta->json('code'));
        $this->assertSame(100.0, (float) $respuesta->json('discount'));
    }

    public function test_comprobar_un_codigo_malo_devuelve_su_motivo(): void
    {
        $this->cupon('GRANDE', 'percent', 25, ['min_purchase' => 500]);

        $this->postJson("/api/public/{$this->tienda->slug}/coupons/check", [
            'code' => 'GRANDE',
            'subtotal' => 100,
        ])->assertStatus(422)->assertJsonPath('errors.coupon_code.0', 'Ese código pide una compra mínima mayor.');
    }

    public function test_mentir_en_el_subtotal_al_comprobar_no_consigue_descuento(): void
    {
        // El subtotal de la comprobación solo sirve para la compra mínima y para
        // enseñar cuánto descontaría; el del pedido lo calcula el servidor.
        $this->cupon('GRANDE', 'percent', 50, ['min_purchase' => 500]);
        $producto = $this->producto(precio: 100);

        $this->postJson("/api/public/{$this->tienda->slug}/coupons/check", [
            'code' => 'GRANDE',
            'subtotal' => 999999,
        ])->assertOk();

        // Pero al pedir de verdad, con 100 de compra, el cupón no vale.
        $this->assertSame(
            'Ese código pide una compra mínima mayor.',
            $this->pedidoRechazado($producto, 'GRANDE'),
        );
    }

    // ---------------------------------------------------------------- el panel

    public function test_el_duenio_crea_un_cupon_y_queda_anotado(): void
    {
        $this->comoAdmin()->postJson('/api/coupons', [
            'code' => 'navidad',
            'type' => 'percent',
            'value' => 15,
            'max_uses' => 50,
        ])->assertCreated()->assertJsonPath('code', 'NAVIDAD');

        $linea = \App\Models\ActivityLog::latest('id')->first();
        $this->assertSame(\App\Models\ActivityLog::CUPON_CREADO, $linea->action);
        $this->assertStringContainsString('NAVIDAD', $linea->description);
    }

    public function test_dos_cupones_con_el_mismo_codigo_en_la_misma_tienda_se_rechazan(): void
    {
        $this->cupon('REPE', 'percent', 10);

        $this->comoAdmin()->postJson('/api/coupons', [
            'code' => 'repe', 'type' => 'percent', 'value' => 20,
        ])->assertStatus(422)->assertJsonPath('errors.code.0', 'Ya tienes un cupón con ese código.');
    }

    public function test_dos_tiendas_pueden_tener_el_mismo_codigo(): void
    {
        $otra = Tenant::create([
            'slug' => 'otra-cupones', 'name' => 'Otra',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);
        $suyo = new Coupon(['code' => 'VERANO25', 'type' => 'percent', 'value' => 50, 'is_active' => true]);
        $suyo->tenant_id = $otra->id;
        $suyo->save();

        $this->comoAdmin()->postJson('/api/coupons', [
            'code' => 'VERANO25', 'type' => 'percent', 'value' => 10,
        ])->assertCreated();

        $this->assertSame(2, Coupon::withoutTenant()->where('code', 'VERANO25')->count());
    }

    public function test_un_codigo_con_espacios_o_signos_se_rechaza(): void
    {
        $this->comoAdmin()->postJson('/api/coupons', [
            'code' => 'MI CUPÓN!', 'type' => 'percent', 'value' => 10,
        ])->assertStatus(422);
    }

    public function test_staff_no_toca_los_cupones(): void
    {
        // Un cupón es dinero que se deja de cobrar: lo decide quien decide los
        // precios (FUN-4).
        $this->comoStaff()->getJson('/api/coupons')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->comoStaff()->postJson('/api/coupons', [
            'code' => 'NOPE', 'type' => 'percent', 'value' => 10,
        ])->assertForbidden();
    }

    public function test_una_tienda_no_ve_ni_edita_los_cupones_de_otra(): void
    {
        $otra = Tenant::create([
            'slug' => 'otra-cupones', 'name' => 'Otra',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);
        $ajeno = new Coupon(['code' => 'AJENO', 'type' => 'percent', 'value' => 50, 'is_active' => true]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        $this->assertSame([], $this->comoAdmin()->getJson('/api/coupons')->assertOk()->json('data'));

        $this->app['auth']->forgetGuards();
        $this->comoAdmin()->putJson("/api/coupons/{$ajeno->id}", ['value' => 1])->assertNotFound();
    }

    public function test_hay_un_techo_de_cupones_por_tienda(): void
    {
        // No es un tope de plan: es que la tabla no puede quedar abierta a que un
        // script la llene.
        for ($i = 0; $i < Coupon::MAXIMO_POR_TIENDA; $i++) {
            $this->cupon("CUP{$i}", 'percent', 5);
        }

        $this->comoAdmin()->postJson('/api/coupons', [
            'code' => 'UNOMAS', 'type' => 'percent', 'value' => 5,
        ])->assertStatus(422);
    }

    public function test_borrar_un_cupon_no_toca_los_pedidos_que_lo_usaron(): void
    {
        $cupon = $this->cupon('VERANO25', 'percent', 25);
        $pedido = $this->pedidoPublico($this->producto(precio: 1000), 1, 'VERANO25');

        $this->comoAdmin()->deleteJson("/api/coupons/{$cupon->id}")->assertNoContent();

        $pedido->refresh();

        // El id se va a null, pero el código y el descuento son snapshot: el
        // historial sigue diciendo qué se aplicó.
        $this->assertNull($pedido->coupon_id);
        $this->assertSame('VERANO25', $pedido->coupon_code);
        $this->assertSame('250.00', $pedido->discount_amount);
    }

    // --------------------------------------------------------------- ayudas

    private function cupon(string $codigo, string $tipo, float $valor, array $extra = []): Coupon
    {
        $cupon = new Coupon(array_merge([
            'code' => $codigo, 'type' => $tipo, 'value' => $valor, 'is_active' => true,
        ], $extra));
        $cupon->tenant_id = $this->tienda->id;
        $cupon->save();

        return $cupon;
    }

    private function producto(float $precio, ?float $costo = null, int $stock = 20): Product
    {
        $producto = new Product([
            'name' => 'Producto', 'price' => $precio, 'cost' => $costo, 'stock' => $stock,
            'is_active' => true, 'status' => 'published',
        ]);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    private function pedidoPublico(Product $producto, int $cantidad, ?string $codigo = null, ?string $entrega = null): Order
    {
        $respuesta = $this->postJson("/api/public/{$this->tienda->slug}/orders", array_filter([
            'customer_name' => 'Cliente',
            'customer_phone' => '999888777',
            'coupon_code' => $codigo,
            'delivery_method' => $entrega,
            'items' => [['product_id' => $producto->id, 'quantity' => $cantidad]],
        ]))->assertCreated();

        return Order::withoutTenant()->findOrFail($respuesta->json('id'));
    }

    /** El mensaje con el que se rechazó el pedido por su cupón. */
    private function pedidoRechazado(Product $producto, string $codigo): string
    {
        return $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Cliente',
            'customer_phone' => '999888777',
            'coupon_code' => $codigo,
            'items' => [['product_id' => $producto->id, 'quantity' => 1]],
        ])->assertStatus(422)->json('errors.coupon_code.0');
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
