<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
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

        // La columna `variante` es de MOD-12: va vacia en un producto sin ellas.
        $this->assertStringContainsString('nombre;marca;variante;sku;precio;precio_oferta;costo', $csv);
        // `fputcsv` entrecomilla lo que lleva espacios, de ahi las comillas.
        $this->assertStringContainsString('"Ryzen 5 7600";AMD;;CPU-7600;900.00;850.00;700.00;4;Procesadores', $csv);
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
        [$destino, $dueniaDestino] = $this->tiendaQueRecibe();

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
        // fallaria en cerrado (AUD-4) y diria que no tiene categoria. Y es la
        // que ya tenia el destino, no una nueva llamada como la del archivo.
        $this->assertSame(
            'PROCESADORES',
            Category::withoutTenant()->findOrFail($importado->category_id)->name,
        );
        $this->assertSame(1, Category::withoutTenant()->where('tenant_id', $destino->id)->count());
    }

    /**
     * FUN-17: el caso con el que se encontró. Exportar el catálogo y subirlo de
     * vuelta a la MISMA tienda lo duplicaba entero, porque el import solo sabía
     * crear. Por defecto ahora omite lo que ya existe.
     */
    public function test_reimportar_lo_exportado_en_la_misma_tienda_no_duplica_nada(): void
    {
        $this->variante(['Capacidad' => '1 TB'], 'SSD-1T', 400, null, 320, 3);
        $this->producto->sincronizarResumenDeVariantes();

        $csv = $this->descargar('/api/products/export');

        // El import es una funcion del plan (SAAS-3) y el de por defecto no la trae.
        $this->tenant->update(['plan' => 'pro']);
        $this->app['auth']->forgetGuards();

        $this->comoAdmin()->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
        ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('errors', []);

        $this->assertSame(1, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, ProductVariant::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * FUN-19: subir lo recién exportado en modo `actualizar` no cambia nada, y el
     * informe tiene que decir eso —"ya estaba al día"— y no "se actualizó".
     * Cubre además que la exportación y el import redondean igual: si un precio
     * o una spec dieran otra cosa al volver, aquí saldría como cambio.
     */
    public function test_reimportar_sin_tocar_en_modo_actualizar_no_cambia_nada(): void
    {
        $this->variante(['Capacidad' => '1 TB'], 'SSD-1T', 400, null, 320, 3);
        $this->producto->sincronizarResumenDeVariantes();

        $csv = $this->descargar('/api/products/export');

        $this->tenant->update(['plan' => 'pro']);
        $this->app['auth']->forgetGuards();

        $this->comoAdmin()->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
            'modo' => 'actualizar',
        ])
            ->assertOk()
            ->assertJsonPath('updated_count', 0)
            ->assertJsonPath('unchanged_count', 1)
            ->assertJsonPath('errors', []);
    }

    /**
     * FUN-17: el viaje que de verdad se quiere hacer con la exportación —sacar,
     * cambiar precios en Excel, volver a subir— en modo `actualizar`. Lo que el
     * archivo no cambia se queda igual, y la variante se reconoce por sus
     * opciones.
     */
    public function test_exportar_editar_y_reimportar_actualiza_en_su_sitio(): void
    {
        $this->variante(['Capacidad' => '1 TB'], 'SSD-1T', 400, null, 320, 3);
        $this->producto->sincronizarResumenDeVariantes();

        $csv = str_replace(';400.00;', ';455.00;', $this->descargar('/api/products/export'));

        $this->tenant->update(['plan' => 'pro']);
        $this->app['auth']->forgetGuards();

        $this->comoAdmin()->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
            'modo' => 'actualizar',
        ])
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('errors', []);

        $variantes = ProductVariant::withoutTenant()->where('product_id', $this->producto->id)->get();

        $this->assertCount(1, $variantes);
        $this->assertSame('455.00', $variantes->first()->price);
        $this->assertSame('320.00', $variantes->first()->cost);

        // El resumen de la ficha se rehizo con el precio nuevo, y lo que el
        // archivo traia igual se quedo igual.
        $ficha = Product::withoutTenant()->findOrFail($this->producto->id);
        $this->assertSame('455.00', $ficha->price);
        $this->assertSame(['Socket' => 'AM5', 'TDP' => '65W'], $ficha->specs);
    }

    public function test_un_producto_con_variantes_sale_con_una_fila_por_variante_y_vuelve_entero(): void
    {
        // MOD-12: sin la columna `variante`, esto exportaba una sola fila con el
        // resumen de la ficha —el precio de la mas barata y el stock sumado— y
        // reimportarlo creaba un producto suelto con los numeros mezclados.
        $this->variante(['Capacidad' => '1 TB'], 'SSD-1T', 400, null, 320, 3);
        $this->variante(['Capacidad' => '2 TB'], 'SSD-2T', 700, 650, 560, 1);
        $this->producto->sincronizarResumenDeVariantes();

        $csv = $this->descargar('/api/products/export');

        // El nombre se repite en las dos filas (es lo que las agrupa al volver) y
        // la descripcion de la ficha solo va en la primera.
        $this->assertStringContainsString('"Capacidad: 1 TB";SSD-1T;400.00;;320.00;3;Procesadores', $csv);
        $this->assertStringContainsString('"Capacidad: 2 TB";SSD-2T;700.00;650.00;560.00;1;;;', $csv);

        [$destino, $duenia] = $this->tiendaQueRecibe();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$duenia->createToken('test')->plainTextToken,
            'X-Tenant' => $destino->slug,
        ])->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
        ])->assertOk()->assertJsonPath('success_count', 1);

        $importado = Product::withoutTenant()->where('tenant_id', $destino->id)->firstOrFail();

        $variantes = ProductVariant::withoutTenant()
            ->where('product_id', $importado->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $variantes);
        $this->assertSame(['SSD-1T', 'SSD-2T'], $variantes->pluck('sku')->all());
        $this->assertSame([['name' => 'Capacidad', 'value' => '2 TB']], $variantes[1]->options);
        $this->assertSame('650.00', $variantes[1]->sale_price);
        $this->assertSame('320.00', $variantes[0]->cost);

        // Y el resumen de la ficha se rehace solo, sin arrastrar el de origen.
        $this->assertSame('400.00', $importado->price);
        $this->assertSame(4, $importado->stock);
        $this->assertSame(['Socket' => 'AM5', 'TDP' => '65W'], $importado->specs);
    }

    private function variante(array $opciones, string $sku, float $precio, ?float $oferta, float $costo, int $stock): ProductVariant
    {
        $variante = new ProductVariant([
            'product_id' => $this->producto->id,
            'options' => collect($opciones)->map(fn ($valor, $nombre) => ['name' => $nombre, 'value' => $valor])->values()->all(),
            'sku' => $sku,
            'price' => $precio,
            'sale_price' => $oferta,
            'cost' => $costo,
            'stock' => $stock,
            'sort_order' => ProductVariant::withoutTenant()->where('product_id', $this->producto->id)->count(),
        ]);
        $variante->tenant_id = $this->tenant->id;
        $variante->save();

        return $variante;
    }

    /** @return array{0: Tenant, 1: User} */
    private function tiendaQueRecibe(): array
    {
        $destino = Tenant::create([
            'slug' => 'tienda-destino', 'name' => 'Tienda Destino',
            'whatsapp_number' => '51988888888', 'is_active' => true, 'is_published' => true,
            // El import es una funcion del plan (SAAS-3) y el de por defecto no
            // la trae; aqui lo que se prueba es el formato, no el plan.
            'plan' => 'pro',
        ]);

        $duenia = new User([
            'name' => 'Dueña 2', 'email' => 'duenia@destino.test', 'password' => 'secret1234',
            'role' => 'admin', 'is_active' => true,
        ]);
        $duenia->tenant_id = $destino->id;
        $duenia->save();

        // FUN-18: el import ya no crea categorias. La tienda que recibe tiene la
        // suya, escrita distinto a proposito: tiene que reconocerla igual.
        $categoria = new Category(['name' => 'PROCESADORES', 'icon' => 'cpu', 'is_active' => true, 'sort_order' => 0]);
        $categoria->tenant_id = $destino->id;
        $categoria->save();

        $this->app['auth']->forgetGuards();

        return [$destino, $duenia];
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
