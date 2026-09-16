<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Reportes de la tienda (MOD-9).
 *
 * Lo que fija, por orden de lo que costaría romperlo sin enterarse:
 *
 * 1. **Las cifras son las mismas que el dueño ve en Pedidos.** Un reporte que
 *    suma los pendientes, o que se come los de un día, es peor que no tenerlo:
 *    se decide con él. De ahí que casi todo lo de abajo sea una suma concreta
 *    con su resultado escrito a mano.
 * 2. **El costo y la utilidad no se le escapan a staff** (MOD-6). No basta con
 *    que el panel no los pinte: las claves no pueden ni llegar.
 * 3. **Los días sin ventas existen.** Es el fallo clásico del `GROUP BY`: la
 *    gráfica se salta los días vacíos y dibuja una racha que no hubo.
 * 4. **Una tienda no ve las ventas de otra**, ni por el `join` contra
 *    `order_items`, que no tiene tenant propio.
 */
class ReportesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $admin;

    private User $staff;

    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tienda = $this->tienda('tienda-reportes', 'Tienda Reportes');
        $this->admin = $this->usuario($this->tienda, 'admin', 'duenia@reportes.test');
        $this->staff = $this->usuario($this->tienda, 'staff', 'vendedor@reportes.test');

        $this->producto = $this->producto($this->tienda, 'Ryzen 5 7600', precio: 850, costo: 700, stock: 40);
    }

    // -------------------------------------------------------------- resumen

    public function test_el_resumen_suma_solo_lo_atendido_del_rango(): void
    {
        // Dos ventas dentro del rango: 2x850 y 1x850.
        $this->venta(fecha: '-3 days', cantidad: 2);
        $this->venta(fecha: '-1 day', cantidad: 1);

        // Y tres que NO son ventas: una pendiente, una cancelada y una atendida
        // de hace medio año.
        $this->venta(fecha: '-2 days', cantidad: 5, estado: 'pending');
        $this->venta(fecha: '-2 days', cantidad: 5, estado: 'cancelled');
        $this->venta(fecha: '-200 days', cantidad: 9);

        $resumen = $this->reporte()['resumen'];

        $this->assertEquals(2550.0, $resumen['ventas']);
        $this->assertSame(2, $resumen['pedidos']);
        $this->assertSame(3, $resumen['unidades']);
        $this->assertEquals(1275.0, $resumen['ticket_promedio']);

        // Lo que entró en el rango, se atendiera o no: cuatro pedidos, uno de
        // ellos cancelado. El de hace 200 días queda fuera de los dos.
        $this->assertSame(4, $resumen['recibidos']);
        $this->assertSame(1, $resumen['cancelados']);
    }

    public function test_el_margen_sale_del_costo_del_dia_de_la_venta(): void
    {
        // 2 x 850 vendidos con un costo copiado de 700 cada uno.
        $this->venta(fecha: '-1 day', cantidad: 2);

        // Que el proveedor suba mañana no puede cambiar lo que se ganó ayer.
        $this->producto->forceFill(['cost' => 830])->save();

        $resumen = $this->reporte()['resumen'];

        $this->assertEquals(1700.0, $resumen['ventas']);
        $this->assertEquals(1400.0, $resumen['costo']);
        $this->assertEquals(300.0, $resumen['utilidad']);
        $this->assertEquals(17.6, $resumen['margen']);
        $this->assertSame(0, $resumen['lineas_sin_costo']);
    }

    public function test_una_linea_sin_costo_no_infla_el_margen_y_se_avisa(): void
    {
        $sinCosto = $this->producto($this->tienda, 'Cable suelto', precio: 100, costo: null, stock: 10);

        $pedido = $this->venta(fecha: '-1 day', cantidad: 1);
        $this->linea($pedido, $sinCosto, cantidad: 1);

        $resumen = $this->reporte()['resumen'];

        // El margen se mide solo contra lo que sí tiene costo: 850 vendidos,
        // 700 de costo. Los 100 del cable no entran ni arriba ni abajo.
        $this->assertEquals(150.0, $resumen['utilidad']);
        $this->assertEquals(17.6, $resumen['margen']);
        $this->assertSame(1, $resumen['lineas_sin_costo']);
    }

    public function test_el_envio_entra_en_las_ventas_pero_no_en_la_utilidad(): void
    {
        $pedido = $this->venta(fecha: '-1 day', cantidad: 1);
        $pedido->forceFill([
            'delivery_method' => 'delivery',
            'delivery_cost'   => 20,
            'total'           => 870,
        ])->saveQuietly();

        $resumen = $this->reporte()['resumen'];

        $this->assertEquals(870.0, $resumen['ventas'], 'Es lo que pagó el cliente.');
        $this->assertEquals(20.0, $resumen['envio']);
        // 850 - 700. Meter el envío aquí inflaría la utilidad con dinero que se
        // va en gasolina (MOD-1).
        $this->assertEquals(150.0, $resumen['utilidad']);
    }

    // ---------------------------------------------------------------- serie

    public function test_la_serie_rellena_con_cero_los_dias_sin_ventas(): void
    {
        $this->venta(fecha: '-2 days', cantidad: 1);

        $serie = $this->reporte([
            'desde' => now()->subDays(4)->toDateString(),
            'hasta' => now()->toDateString(),
        ])['serie'];

        $this->assertCount(5, $serie, 'Los cinco días del rango, hubiera ventas o no.');
        $this->assertSame(now()->subDays(4)->toDateString(), $serie[0]['periodo']);
        $this->assertEquals(0.0, $serie[0]['ventas']);
        $this->assertEquals(850.0, $serie[2]['ventas']);
        $this->assertSame(1, $serie[2]['pedidos']);
        $this->assertEquals(0.0, $serie[4]['ventas']);
    }

    public function test_agrupar_por_mes_junta_los_dias_del_mes(): void
    {
        $this->venta(fecha: '-40 days', cantidad: 1);
        $this->venta(fecha: '-41 days', cantidad: 1);

        $serie = $this->reporte([
            'desde'      => now()->subDays(60)->toDateString(),
            'hasta'      => now()->toDateString(),
            'agrupacion' => 'mes',
        ])['serie'];

        $delMes = collect($serie)->firstWhere('periodo', now()->subDays(40)->format('Y-m'));

        // Si los dos días cayeron en el mismo mes son un punto de 1700; si el
        // rango los parte, cada uno en el suyo. Lo que se comprueba es que se
        // agrupa por mes y no por día, y que no se pierde nada.
        $this->assertNotNull($delMes);
        $this->assertEquals(1700.0, collect($serie)->sum('ventas'));
        $this->assertLessThanOrEqual(3, count($serie), 'Sesenta días son dos o tres meses, no sesenta puntos.');
    }

    // --------------------------------------------------------- mas vendidos

    public function test_los_mas_vendidos_van_por_unidades_y_no_por_visitas(): void
    {
        $barato = $this->producto($this->tienda, 'Pasta térmica', precio: 20, costo: 8, stock: 100);

        // El caro mueve 2 unidades; el barato, 9. El "top" es el barato.
        $this->venta(fecha: '-1 day', cantidad: 2);

        $pedido = $this->venta(fecha: '-1 day', cantidad: 1);
        $this->linea($pedido, $barato, cantidad: 9);

        // Y el producto más VISTO es otro distinto, para que no se cuele.
        $this->producto->forceFill(['views_count' => 9999])->saveQuietly();

        $top = $this->reporte()['mas_vendidos'];

        $this->assertSame('Pasta térmica', $top[0]['nombre']);
        $this->assertSame(9, $top[0]['unidades']);
        $this->assertEquals(180.0, $top[0]['ventas']);
        $this->assertEquals(108.0, $top[0]['utilidad']);
        $this->assertSame('Ryzen 5 7600', $top[1]['nombre']);
    }

    public function test_un_producto_borrado_sigue_contando_con_el_nombre_que_tenia(): void
    {
        $pedido = $this->venta(fecha: '-1 day', cantidad: 1);

        // El snapshot de la línea es lo único que queda cuando la ficha se va.
        OrderItem::where('order_id', $pedido->id)->update(['product_id' => null]);

        $top = $this->reporte()['mas_vendidos'];

        $this->assertSame('Ryzen 5 7600', $top[0]['nombre']);
        $this->assertNull($top[0]['product_id']);
        $this->assertSame(1, $top[0]['unidades']);
    }

    // ----------------------------------------------------------- stock bajo

    public function test_el_stock_bajo_usa_el_umbral_de_cada_ficha(): void
    {
        $this->producto($this->tienda, 'Fuente 600W', precio: 200, costo: 150, stock: 2);
        $sobrado = $this->producto($this->tienda, 'Teclado', precio: 80, costo: 40, stock: 50);
        $sobrado->forceFill(['low_stock_threshold' => 3])->save();

        $bajo = collect($this->reporte()['stock_bajo']);

        $this->assertTrue($bajo->contains('nombre', 'Fuente 600W'));
        $this->assertFalse($bajo->contains('nombre', 'Teclado'));
        // El del setUp tiene 40 unidades y umbral 5: tampoco.
        $this->assertFalse($bajo->contains('nombre', 'Ryzen 5 7600'));
    }

    public function test_una_variante_agotada_se_ve_aunque_la_ficha_sume_de_sobra(): void
    {
        $conVariantes = $this->producto($this->tienda, 'Memoria Kingston', precio: 200, costo: 150, stock: 0);

        $this->variante($conVariantes, '16 GB', stock: 40);
        $this->variante($conVariantes, '32 GB', stock: 1);

        $conVariantes->sincronizarResumenDeVariantes();

        $bajo = collect($this->reporte()['stock_bajo']);

        // La ficha suma 41 unidades: mirándola, nada que reponer. Pero la de
        // 32 GB está en 1 (MOD-5).
        $this->assertTrue($bajo->contains('nombre', 'Memoria Kingston (32 GB)'));
        $this->assertFalse($bajo->contains('nombre', 'Memoria Kingston (16 GB)'));
        $this->assertFalse($bajo->contains('nombre', 'Memoria Kingston'), 'La ficha con variantes no se mira entera.');
    }

    // ------------------------------------------------------------- permisos

    public function test_staff_ve_las_ventas_pero_no_el_costo_ni_la_utilidad(): void
    {
        $this->venta(fecha: '-1 day', cantidad: 2);

        $reporte = $this->conSesionDe($this->staff)->getJson('/api/reports')->assertOk()->json();

        $this->assertEquals(1700.0, $reporte['resumen']['ventas']);

        foreach (['costo', 'utilidad', 'margen', 'lineas_sin_costo'] as $prohibido) {
            $this->assertArrayNotHasKey($prohibido, $reporte['resumen']);
        }

        $this->assertArrayNotHasKey('utilidad', $reporte['mas_vendidos'][0]);
        $this->assertArrayNotHasKey('utilidad', $reporte['serie'][0]);
    }

    public function test_sin_sesion_no_hay_reporte(): void
    {
        $this->getJson('/api/reports')->assertUnauthorized();
    }

    public function test_una_tienda_no_ve_las_ventas_de_otra(): void
    {
        $otra = $this->tienda('tienda-ajena-reportes', 'Tienda Ajena');
        $ajeno = $this->producto($otra, 'Producto ajeno', precio: 5000, costo: 1000, stock: 10);

        $pedido = Order::create([
            'tenant_id' => $otra->id, 'customer_name' => 'Cliente ajeno',
            'customer_phone' => '51966666666', 'status' => 'attended', 'total' => 5000,
        ]);
        $this->linea($pedido, $ajeno, cantidad: 1);

        $reporte = $this->reporte();

        $this->assertEquals(0.0, $reporte['resumen']['ventas']);
        $this->assertSame([], $reporte['mas_vendidos']);
        $this->assertFalse(collect($reporte['stock_bajo'])->contains('nombre', 'Producto ajeno'));
    }

    // --------------------------------------------------------------- rangos

    public function test_el_rango_al_reves_se_rechaza(): void
    {
        $this->conSesionDe($this->admin)
            ->getJson('/api/reports?desde=2026-03-01&hasta=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');
    }

    public function test_un_rango_de_años_por_dia_se_rechaza_en_vez_de_dibujar_una_linea_negra(): void
    {
        $this->conSesionDe($this->admin)
            ->getJson('/api/reports?desde=2020-01-01&hasta=2026-01-01')
            ->assertStatus(422);
    }

    public function test_sin_fechas_el_reporte_es_el_ultimo_mes(): void
    {
        $reporte = $this->reporte();

        $this->assertSame(now()->subDays(29)->toDateString(), $reporte['rango']['desde']);
        $this->assertSame(now()->toDateString(), $reporte['rango']['hasta']);
        $this->assertSame('dia', $reporte['rango']['agrupacion']);
        $this->assertCount(30, $reporte['serie']);
    }

    // ---------------------------------------------------------- exportación

    public function test_el_csv_del_reporte_trae_la_serie_y_los_mas_vendidos(): void
    {
        $this->venta(fecha: '-1 day', cantidad: 2);

        $csv = $this->conSesionDe($this->admin)
            ->get('/api/reports/export?desde='.now()->subDays(2)->toDateString().'&hasta='.now()->toDateString())
            ->assertOk()
            ->streamedContent();

        // Que no venga vacío es medio test: lo que escribe el CSV corre al
        // enviar la respuesta, cuando el middleware ya olvidó la tienda, y con
        // el scope puesto se descargaría solo la cabecera (MOD-7).
        $this->assertStringContainsString('fecha;ventas;pedidos;unidades;utilidad', $csv);
        $this->assertStringContainsString(now()->subDay()->toDateString().';1700;1;2;300', $csv);
        $this->assertStringContainsString('producto;unidades;ventas;utilidad', $csv);
        $this->assertStringContainsString('"Ryzen 5 7600";2;1700;300', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_staff_no_descarga_el_reporte_porque_lleva_la_utilidad(): void
    {
        $this->conSesionDe($this->staff)->getJson('/api/reports/export')->assertForbidden();
    }

    // ------------------------------------------------------------ ayudantes

    /** @return array<string, mixed> */
    private function reporte(array $filtros = []): array
    {
        return $this->conSesionDe($this->admin)
            ->getJson('/api/reports?'.http_build_query($filtros))
            ->assertOk()
            ->json();
    }

    /**
     * Un pedido con una línea del producto del setUp, fechado a mano.
     *
     * Se crea con los modelos y no por la API para poder ponerle la fecha: la
     * venta de mostrador siempre nace hoy, y aquí lo que se prueba es
     * justamente el reparto por fechas.
     */
    private function venta(string $fecha, int $cantidad, string $estado = 'attended'): Order
    {
        $pedido = Order::create([
            'tenant_id'      => $this->tienda->id,
            'customer_name'  => 'Cliente',
            'customer_phone' => '51999999999',
            'status'         => $estado,
            'total'          => $cantidad * 850,
        ]);

        $pedido->forceFill(['created_at' => now()->modify($fecha)])->saveQuietly();

        $this->linea($pedido, $this->producto, $cantidad);

        return $pedido;
    }

    private function linea(Order $pedido, Product $producto, int $cantidad): OrderItem
    {
        $precio = (float) ($producto->sale_price ?? $producto->price);

        return OrderItem::create([
            'order_id'     => $pedido->id,
            'product_id'   => $producto->id,
            'product_name' => $producto->name,
            'unit_price'   => $precio,
            // El costo del día de la venta, copiado como lo copia OrderPricing.
            'unit_cost'    => $producto->cost !== null ? (float) $producto->cost : null,
            'quantity'     => $cantidad,
            'subtotal'     => round($precio * $cantidad, 2),
        ]);
    }

    private function tienda(string $slug, string $nombre): Tenant
    {
        return Tenant::create([
            'slug' => $slug, 'name' => $nombre, 'whatsapp_number' => '51999999999',
            'is_active' => true, 'is_published' => true,
        ]);
    }

    private function producto(Tenant $tienda, string $nombre, float $precio, ?float $costo, int $stock): Product
    {
        $producto = new Product([
            'name' => $nombre, 'price' => $precio, 'cost' => $costo, 'stock' => $stock,
            'is_active' => true, 'status' => 'published',
        ]);
        $producto->tenant_id = $tienda->id;
        $producto->save();

        return $producto;
    }

    private function variante(Product $producto, string $valor, int $stock): ProductVariant
    {
        $variante = new ProductVariant([
            'product_id' => $producto->id,
            'options'    => [['name' => 'Capacidad', 'value' => $valor]],
            'price'      => $producto->price,
            'cost'       => $producto->cost,
            'stock'      => $stock,
        ]);
        $variante->tenant_id = $producto->tenant_id;
        $variante->save();

        return $variante;
    }

    private function usuario(Tenant $tienda, string $rol, string $correo): User
    {
        $usuario = new User([
            'name' => ucfirst($rol), 'email' => $correo, 'password' => 'secret1234',
            'role' => $rol, 'is_active' => true,
        ]);
        $usuario->tenant_id = $tienda->id;
        $usuario->save();

        return $usuario;
    }

    private function conSesionDe(User $usuario): self
    {
        // Sin esto el guard se queda con el usuario de la petición anterior
        // dentro del mismo test (mismo ayudante que en CostoYMargenTest).
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant'      => $this->tienda->slug,
        ]);
    }
}
