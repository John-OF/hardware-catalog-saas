<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Exportar el catálogo y los pedidos (MOD-7).
 *
 * Lo que de verdad importa aquí, por encima de que el archivo se descargue:
 *
 * 1. **Lo exportado se puede volver a importar.** Es la mitad de la función: el
 *    dueño saca su catálogo, lo edita en Excel y lo vuelve a subir. Si las
 *    cabeceras no casan con las que lee el importador, lo que tiene es un
 *    archivo bonito y ningún camino de vuelta, así que hay un test que hace el
 *    viaje redondo de verdad.
 * 2. **No se le escapa a quien no debe.** El catálogo lleva el costo de compra
 *    (MOD-6) y los pedidos, el teléfono y el correo de todos los clientes.
 * 3. **Una fila por pedido**, o los totales no se pueden sumar en la hoja.
 */
class ExportacionCsvTest extends TestCase
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
            'slug' => 'tienda-exporta',
            'name' => 'Tienda Exporta',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
        ]);

        $this->admin = $this->usuario('admin', 'duenia@exporta.test');
        $this->staff = $this->usuario('staff', 'vendedor@exporta.test');

        $categoria = new Category(['name' => 'Procesadores', 'icon' => 'cpu', 'is_active' => true, 'sort_order' => 0]);
        $categoria->tenant_id = $this->tenant->id;
        $categoria->save();

        $this->producto = new Product([
            'name' => 'Ryzen 5 7600', 'brand' => 'AMD', 'sku' => 'CPU-7600',
            'price' => 900, 'sale_price' => 850, 'cost' => 700, 'stock' => 4,
            'category_id' => $categoria->id,
            'specs' => ['Socket' => 'AM5', 'TDP' => '65W'],
            'is_active' => true, 'status' => 'published',
        ]);
        $this->producto->tenant_id = $this->tenant->id;
        $this->producto->save();
    }

    // ------------------------------------------------------------- catálogo

    public function test_el_admin_descarga_el_catalogo_con_sus_datos(): void
    {
        $csv = $this->descargar('/api/products/export');

        $this->assertStringContainsString('nombre;marca;sku;precio;precio_oferta;costo', $csv);
        // `fputcsv` entrecomilla lo que lleva espacios, de ahi las comillas.
        $this->assertStringContainsString('"Ryzen 5 7600";AMD;CPU-7600;900.00;850.00;700.00;4;Procesadores', $csv);
        $this->assertStringContainsString('Procesadores', $csv);
        $this->assertStringContainsString('Socket: AM5 | TDP: 65W', $csv);
    }

    public function test_el_archivo_lleva_bom_para_que_excel_no_rompa_los_acentos(): void
    {
        $respuesta = $this->comoAdmin()->get('/api/products/export')->assertOk();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $respuesta->streamedContent());
        $this->assertStringContainsString(
            'attachment; filename=catalogo-tienda-exporta',
            $respuesta->headers->get('content-disposition'),
        );
    }

    public function test_el_catalogo_respeta_el_filtro_de_busqueda(): void
    {
        $otro = new Product(['name' => 'Memoria Kingston', 'price' => 200, 'stock' => 5, 'is_active' => true, 'status' => 'published']);
        $otro->tenant_id = $this->tenant->id;
        $otro->save();

        $csv = $this->descargar('/api/products/export?search=Ryzen');

        $this->assertStringContainsString('Ryzen 5 7600', $csv);
        $this->assertStringNotContainsString('Memoria Kingston', $csv);
    }

    public function test_lo_exportado_se_puede_volver_a_importar(): void
    {
        $csv = $this->descargar('/api/products/export');

        // Se sube a OTRA tienda: es el caso real de irse con los datos, y de paso
        // deja ver qué sobrevive al viaje sin mezclarlo con lo que ya había.
        $destino = Tenant::create([
            'slug' => 'tienda-destino', 'name' => 'Tienda Destino',
            'whatsapp_number' => '51988888888', 'is_active' => true, 'is_published' => true,
            // El import es una funcion del plan (SAAS-3) y el de por defecto no
            // la trae; aqui lo que se prueba es el formato, no el plan.
            'plan' => 'pro',
        ]);

        $dueniaDestino = new User([
            'name' => 'Dueña 2', 'email' => 'duenia@destino.test', 'password' => 'secret1234',
            'role' => 'admin', 'is_active' => true,
        ]);
        $dueniaDestino->tenant_id = $destino->id;
        $dueniaDestino->save();

        $this->app['auth']->forgetGuards();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$dueniaDestino->createToken('test')->plainTextToken,
            'X-Tenant' => $destino->slug,
        ])->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
        ])->assertOk()->assertJsonPath('success_count', 1);

        $importado = Product::withoutTenant()->where('tenant_id', $destino->id)->firstOrFail();

        $this->assertSame('Ryzen 5 7600', $importado->name);
        $this->assertSame('CPU-7600', $importado->sku);
        $this->assertSame('900.00', $importado->price);
        $this->assertSame('850.00', $importado->sale_price);
        $this->assertSame('700.00', $importado->cost);
        $this->assertSame(4, $importado->stock);
        $this->assertSame(['Socket' => 'AM5', 'TDP' => '65W'], $importado->specs);
        // `withoutTenant()` porque la peticion ya termino: la relacion normal
        // fallaria en cerrado (AUD-4) y diria que no tiene categoria.
        $this->assertSame(
            'Procesadores',
            Category::withoutTenant()->findOrFail($importado->category_id)->name,
        );
    }

    // -------------------------------------------------------------- pedidos

    public function test_los_pedidos_salen_uno_por_fila_con_su_utilidad(): void
    {
        $this->venta('Ana Compradora', 2);

        $csv = $this->descargar('/api/orders/export');

        $filas = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(2, $filas, 'Cabecera y una sola fila por pedido.');
        $this->assertStringContainsString('Ana Compradora', $filas[1]);
        $this->assertStringContainsString('2x Ryzen 5 7600', $filas[1]);
        // 2 x 850 vendido, 2 x 700 de costo.
        $this->assertStringContainsString('1700.00', $filas[1]);
        $this->assertStringContainsString('300', $filas[1]);
    }

    public function test_los_pedidos_se_pueden_acotar_por_fechas(): void
    {
        $viejo = $this->venta('Pedido viejo', 1);
        $viejo->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();

        $this->venta('Pedido de hoy', 1);

        $csv = $this->descargar('/api/orders/export?desde='.now()->subDays(7)->format('Y-m-d'));

        $this->assertStringContainsString('Pedido de hoy', $csv);
        $this->assertStringNotContainsString('Pedido viejo', $csv);
    }

    public function test_el_rango_al_reves_se_rechaza(): void
    {
        $this->comoAdmin()
            ->getJson('/api/orders/export?desde=2026-03-01&hasta=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');
    }

    // ------------------------------------------------------------- permisos

    public function test_staff_no_puede_exportar_ni_el_catalogo_ni_los_pedidos(): void
    {
        $this->comoStaff()->getJson('/api/products/export')->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->comoStaff()->getJson('/api/orders/export')->assertForbidden();
    }

    public function test_sin_sesion_no_se_exporta_nada(): void
    {
        $this->getJson('/api/products/export')->assertUnauthorized();
    }

    public function test_una_tienda_no_exporta_el_catalogo_de_otra(): void
    {
        $otraTienda = Tenant::create([
            'slug' => 'tienda-ajena', 'name' => 'Tienda Ajena',
            'whatsapp_number' => '51977777777', 'is_active' => true, 'is_published' => true,
        ]);

        $ajeno = new Product(['name' => 'Producto ajeno', 'price' => 10, 'stock' => 1, 'is_active' => true, 'status' => 'published']);
        $ajeno->tenant_id = $otraTienda->id;
        $ajeno->save();

        $csv = $this->descargar('/api/products/export');

        $this->assertStringNotContainsString('Producto ajeno', $csv);
    }

    // ------------------------------------------------------------ ayudantes

    private function descargar(string $url): string
    {
        return $this->comoAdmin()->get($url)->assertOk()->streamedContent();
    }

    private function venta(string $cliente, int $cantidad): Order
    {
        $respuesta = $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => $cliente,
            'status' => 'attended',
            'items' => [['product_id' => $this->producto->id, 'quantity' => $cantidad]],
        ])->assertCreated();

        return Order::withoutTenant()->findOrFail($respuesta->json('id'));
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
        // Ver el mismo ayudante en CostoYMargenTest: sin esto el guard se queda
        // con el usuario de la petición anterior dentro del mismo test.
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant' => $this->tenant->slug,
        ]);
    }
}
