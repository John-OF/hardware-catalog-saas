<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * La zona horaria de la tienda (MOD-13).
 *
 * Salió de `MOD-9`: agrupando las ventas por día, una venta de las 8 de la
 * noche en Perú (UTC-5) contaba como del día siguiente, porque el servidor
 * guarda y agrupaba en UTC.
 *
 * Lo que fija, por orden de lo que costaría romperlo sin enterarse:
 *
 * 1. **Una venta cae en el día en que la vivió la tienda**, no en el del
 *    servidor. Es todo el motivo del cambio, y el mismo pedido tiene que caer
 *    en dos días distintos según la zona de quien lo mira.
 * 2. **El rango son los días del calendario de la tienda.** "Del 1 al 31" no
 *    puede traerse cinco horas del 31 del mes anterior ni dejarse fuera las
 *    cinco primeras del 1.
 * 3. **Nada de esto toca lo guardado**: la base sigue en UTC, y una tienda sin
 *    zona elegida se comporta exactamente como antes de este cambio.
 */
class ZonaHorariaDeLaTiendaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 2026-09-16 01:00 UTC. En Lima (UTC-5) son las 20:00 del **15**.
     *
     * Es el caso que motivó todo: la última venta de la tarde, la que el dueño
     * cuenta como de hoy al cerrar la tienda.
     */
    private const VENTA_DE_LA_NOCHE = '2026-09-16 01:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
    }

    // ------------------------------------------------ el día de cada venta

    public function test_una_venta_de_las_ocho_de_la_noche_en_lima_cuenta_ese_dia_y_no_el_siguiente(): void
    {
        [$tienda, $admin] = $this->tiendaCon('America/Lima');
        $this->venta($tienda, self::VENTA_DE_LA_NOCHE, 100);

        $serie = collect($this->reporte($tienda, $admin, '2026-09-14', '2026-09-17')['serie']);

        $this->assertEquals(100, $serie->firstWhere('periodo', '2026-09-15')['ventas']);
        $this->assertEquals(0, $serie->firstWhere('periodo', '2026-09-16')['ventas']);
    }

    public function test_la_misma_venta_en_una_tienda_en_utc_cae_al_dia_siguiente(): void
    {
        // El mismo instante, la misma fila en la base. Lo único que cambia es
        // quién lo mira: es lo que demuestra que se está leyendo, no guardando.
        [$tienda, $admin] = $this->tiendaCon('UTC', 'tienda-utc', 'utc@zona.test');
        $this->venta($tienda, self::VENTA_DE_LA_NOCHE, 100);

        $serie = collect($this->reporte($tienda, $admin, '2026-09-14', '2026-09-17')['serie']);

        $this->assertEquals(0, $serie->firstWhere('periodo', '2026-09-15')['ventas']);
        $this->assertEquals(100, $serie->firstWhere('periodo', '2026-09-16')['ventas']);
    }

    public function test_una_venta_de_madrugada_en_madrid_cuenta_el_dia_que_ya_empezo_alli(): void
    {
        // 2026-09-15 23:30 UTC = 2026-09-16 01:30 en Madrid (UTC+2 en verano).
        // El desplazamiento positivo empuja al día siguiente, al revés que Lima.
        [$tienda, $admin] = $this->tiendaCon('Europe/Madrid', 'tienda-madrid', 'madrid@zona.test');
        $this->venta($tienda, '2026-09-15 23:30:00', 250);

        $serie = collect($this->reporte($tienda, $admin, '2026-09-14', '2026-09-17')['serie']);

        $this->assertEquals(0, $serie->firstWhere('periodo', '2026-09-15')['ventas']);
        $this->assertEquals(250, $serie->firstWhere('periodo', '2026-09-16')['ventas']);
    }

    // ------------------------------------------------------------ el rango

    public function test_el_rango_son_los_dias_del_calendario_de_la_tienda(): void
    {
        [$tienda, $admin] = $this->tiendaCon('America/Lima');

        // 2026-09-01 02:00 UTC = 2026-08-31 21:00 en Lima: es de AGOSTO para la
        // tienda, aunque la fila diga septiembre.
        $this->venta($tienda, '2026-09-01 02:00:00', 500);
        // 2026-09-02 03:00 UTC = 2026-09-01 22:00 en Lima: ésta sí es del 1.
        $this->venta($tienda, '2026-09-02 03:00:00', 700);

        $resumen = $this->reporte($tienda, $admin, '2026-09-01', '2026-09-30')['resumen'];

        $this->assertEquals(700.0, $resumen['ventas'], 'La del 31 de agosto no entra en septiembre.');
        $this->assertSame(1, $resumen['pedidos']);
    }

    public function test_sin_fechas_el_rango_termina_en_el_hoy_de_la_tienda(): void
    {
        [$tienda, $admin] = $this->tiendaCon('America/Lima');

        // 03:00 UTC del 16 es todavía el 15 a las 22:00 en Lima: el reporte por
        // defecto tiene que acabar el 15, no el 16.
        $this->travelTo('2026-09-16 03:00:00');

        $rango = $this->reporte($tienda, $admin)['rango'];

        $this->assertSame('2026-09-15', $rango['hasta']);
        $this->assertSame('2026-08-17', $rango['desde'], 'Treinta días contados desde el hoy de la tienda.');
        $this->assertSame('America/Lima', $rango['zona']);
    }

    public function test_agrupar_por_mes_usa_tambien_la_hora_de_la_tienda(): void
    {
        [$tienda, $admin] = $this->tiendaCon('America/Lima');

        // 2026-10-01 02:00 UTC = 2026-09-30 21:00 en Lima: cierra septiembre.
        $this->venta($tienda, '2026-10-01 02:00:00', 900);

        $serie = collect($this->reporte($tienda, $admin, '2026-09-01', '2026-10-31', 'mes')['serie']);

        $this->assertEquals(900, $serie->firstWhere('periodo', '2026-09')['ventas']);
        $this->assertEquals(0, $serie->firstWhere('periodo', '2026-10')['ventas']);
    }

    // ------------------------------------------------------------- el CSV

    public function test_el_csv_de_pedidos_lleva_la_hora_de_la_tienda(): void
    {
        [$tienda, $admin] = $this->tiendaCon('America/Lima');
        $this->venta($tienda, self::VENTA_DE_LA_NOCHE, 100);

        $csv = $this->conSesionDe($admin, $tienda)
            ->get('/api/orders/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('2026-09-15 20:00', $csv);
        $this->assertStringNotContainsString('2026-09-16 01:00', $csv, 'Eso es la hora del servidor, no la de la tienda.');
    }

    public function test_el_rango_de_fechas_del_csv_tambien_es_el_de_la_tienda(): void
    {
        [$tienda, $admin] = $this->tiendaCon('America/Lima');
        $this->venta($tienda, self::VENTA_DE_LA_NOCHE, 100, 'Venta de la noche');

        // Pidiendo sólo el 15: en UTC esa venta es del 16 y se habría quedado
        // fuera del archivo.
        $csv = $this->conSesionDe($admin, $tienda)
            ->get('/api/orders/export?desde=2026-09-15&hasta=2026-09-15')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Venta de la noche', $csv);
    }

    // --------------------------------------------------------- la elección

    public function test_el_admin_elige_la_zona_y_queda_escrito_en_la_actividad(): void
    {
        [$tienda, $admin] = $this->tiendaCon('UTC');

        $this->conSesionDe($admin, $tienda)
            ->putJson('/api/tenant', ['timezone' => 'America/Lima'])
            ->assertOk()
            ->assertJsonPath('timezone', 'America/Lima');

        $this->assertSame('America/Lima', $tienda->fresh()->timezone);

        // Cambiar de zona mueve los días de todos los reportes que se miren
        // después, así que tiene que quedar escrito quién lo hizo (INF-3).
        $linea = ActivityLog::where('tenant_id', $tienda->id)->latest()->first();

        $this->assertNotNull($linea);
        $this->assertSame(ActivityLog::CONFIGURACION_EDITADA, $linea->action);
        $this->assertStringContainsString('zona horaria', $linea->description);
        $this->assertSame(['UTC', 'America/Lima'], $linea->context['cambios']['zona horaria']);
    }

    public function test_una_zona_que_no_esta_en_la_lista_se_rechaza(): void
    {
        [$tienda, $admin] = $this->tiendaCon('UTC');

        $this->conSesionDe($admin, $tienda)
            ->putJson('/api/tenant', ['timezone' => 'Marte/Olympus_Mons'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }

    public function test_staff_no_cambia_la_zona_de_la_tienda(): void
    {
        [$tienda] = $this->tiendaCon('UTC');
        $staff = $this->usuario($tienda, 'staff', 'vendedor@zona.test');

        $this->conSesionDe($staff, $tienda)
            ->putJson('/api/tenant', ['timezone' => 'America/Lima'])
            ->assertForbidden();
    }

    // ------------------------------------------------------ la lista y UTC

    public function test_todas_las_zonas_ofrecidas_existen_de_verdad(): void
    {
        $validas = timezone_identifiers_list();

        foreach (array_keys(config('timezones')) as $zona) {
            $this->assertContains($zona, $validas, "'{$zona}' no es un identificador IANA válido.");
        }
    }

    /**
     * Las dos listas -la de aquí y la del selector- tienen que ir a la par.
     *
     * Si el frontend ofrece una zona que el backend no valida, el dueño la
     * elige, le sale un 422 al guardar y no hay forma de saber por qué. Al
     * revés, una zona sólo en el backend es una opción que nadie puede elegir.
     * El comentario de los dos archivos ya lo pide; esto lo hace obligatorio.
     */
    public function test_la_lista_del_frontend_no_se_ha_separado_de_esta(): void
    {
        $archivo = base_path('../saas-hardware-frontend/src/utils/timezones.ts');

        if (! file_exists($archivo)) {
            $this->markTestSkipped('El frontend no está en este árbol.');
        }

        // Solo el bloque de TIMEZONES: más abajo hay funciones que mencionan
        // zonas dentro de comentarios y ejemplos.
        $contenido = file_get_contents($archivo);
        preg_match('/export const TIMEZONES[^{]*\{(.*?)\n\};/s', $contenido, $bloque);

        $this->assertNotEmpty($bloque, 'No se encontró el objeto TIMEZONES en el archivo del frontend.');

        preg_match_all("/^\s*'?([A-Za-z_\/]+)'?\s*:/m", $bloque[1], $encontradas);

        $delFrontend = $encontradas[1];
        $delBackend = array_keys(config('timezones'));

        sort($delFrontend);
        sort($delBackend);

        $this->assertSame(
            $delBackend,
            $delFrontend,
            'config/timezones.php y utils/timezones.ts ofrecen zonas distintas.',
        );
    }

    /**
     * El hueco que encontró el dueño: la lista salió de las monedas, y los
     * países dolarizados no tienen moneda propia, así que desaparecieron.
     */
    public function test_los_paises_dolarizados_tienen_su_propia_zona(): void
    {
        $ofrecidas = array_keys(config('timezones'));

        // Ecuador, Panamá y El Salvador usan el dólar. Ninguno debe obligar a
        // elegir la zona de otro país porque hoy coincida el desplazamiento.
        $this->assertContains('America/Guayaquil', $ofrecidas);
        $this->assertContains('America/Panama', $ofrecidas);
        $this->assertContains('America/El_Salvador', $ofrecidas);
    }

    public function test_una_tienda_de_guayaquil_cuenta_sus_ventas_en_su_hora(): void
    {
        [$tienda, $admin] = $this->tiendaCon('America/Guayaquil');
        $this->venta($tienda, self::VENTA_DE_LA_NOCHE, 100);

        $serie = collect($this->reporte($tienda, $admin, '2026-09-14', '2026-09-17')['serie']);

        // Ecuador continental es UTC-5, como Perú, pero con su identificador:
        // si mañana uno de los dos cambiara sus reglas, esto lo cazaría.
        $this->assertEquals(100, $serie->firstWhere('periodo', '2026-09-15')['ventas']);
        $this->assertEquals(0, $serie->firstWhere('periodo', '2026-09-16')['ventas']);
    }

    public function test_una_tienda_nueva_nace_en_utc_y_se_comporta_como_antes(): void
    {
        [$tienda, $admin] = $this->tiendaCon(null);

        $this->assertSame('UTC', $tienda->fresh()->timezone, 'El valor por defecto no mueve los reportes de nadie.');

        $this->venta($tienda, self::VENTA_DE_LA_NOCHE, 100);

        $serie = collect($this->reporte($tienda, $admin, '2026-09-14', '2026-09-17')['serie']);

        $this->assertEquals(100, $serie->firstWhere('periodo', '2026-09-16')['ventas']);
    }

    public function test_una_zona_vacia_en_la_base_no_rompe_el_reporte(): void
    {
        // Una fila anterior a la columna, o tocada a mano. Cae en UTC en vez de
        // reventar al construir el Carbon.
        [$tienda, $admin] = $this->tiendaCon('UTC');
        $tienda->forceFill(['timezone' => ''])->save();

        $this->assertSame('UTC', $tienda->fresh()->zonaHoraria());
        $this->assertSame('UTC', $this->reporte($tienda, $admin)['rango']['zona']);
    }

    // ------------------------------------------------------------ ayudantes

    /** @return array{0: Tenant, 1: User} */
    private function tiendaCon(?string $zona, string $slug = 'tienda-zona', string $correo = 'duenia@zona.test'): array
    {
        $datos = [
            'slug' => $slug, 'name' => 'Tienda Zona', 'whatsapp_number' => '51999999999',
            'is_active' => true, 'is_published' => true,
        ];

        if ($zona !== null) {
            $datos['timezone'] = $zona;
        }

        $tienda = Tenant::create($datos);

        return [$tienda, $this->usuario($tienda, 'admin', $correo)];
    }

    /** @return array<string, mixed> */
    private function reporte(Tenant $tienda, User $usuario, ?string $desde = null, ?string $hasta = null, string $agrupacion = 'dia'): array
    {
        $filtros = array_filter([
            'desde' => $desde,
            'hasta' => $hasta,
            'agrupacion' => $agrupacion,
        ]);

        return $this->conSesionDe($usuario, $tienda)
            ->getJson('/api/reports?'.http_build_query($filtros))
            ->assertOk()
            ->json();
    }

    /**
     * Un pedido atendido con una línea, fechado en UTC a mano.
     *
     * La fecha se escribe siempre en UTC porque es como vive en la base: el
     * test dice "esta fila existe", y lo que se comprueba es en qué día la
     * coloca cada tienda.
     */
    private function venta(Tenant $tienda, string $instanteUtc, float $total, string $cliente = 'Cliente'): Order
    {
        $producto = Product::withoutTenant()->where('tenant_id', $tienda->id)->first();

        if (! $producto) {
            $producto = new Product([
                'name' => 'Producto', 'price' => $total, 'stock' => 100,
                'is_active' => true, 'status' => 'published',
            ]);
            $producto->tenant_id = $tienda->id;
            $producto->save();
        }

        $pedido = Order::create([
            'tenant_id' => $tienda->id, 'customer_name' => $cliente,
            'customer_phone' => '51999999999', 'status' => 'attended', 'total' => $total,
        ]);

        $pedido->forceFill(['created_at' => $instanteUtc])->saveQuietly();

        OrderItem::create([
            'order_id' => $pedido->id, 'product_id' => $producto->id,
            'product_name' => $producto->name, 'unit_price' => $total,
            'quantity' => 1, 'subtotal' => $total,
        ]);

        return $pedido;
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

    private function conSesionDe(User $usuario, Tenant $tienda): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant'      => $tienda->slug,
        ]);
    }
}
