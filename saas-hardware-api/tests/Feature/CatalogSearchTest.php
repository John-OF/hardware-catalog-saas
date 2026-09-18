<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fija la búsqueda del catálogo público (PUB-3).
 *
 * Antes solo miraba `name`, así que buscar "Kingston" no encontraba nada aunque
 * la tienda tuviera media docena de productos de esa marca.
 *
 * Los casos de aislamiento son los importantes: al pasar de un `where` a varios
 * `orWhere` hay que agruparlos, o el OR se mezcla con los filtros de tenant y de
 * publicación y la búsqueda empieza a mostrar lo que no debe.
 */
class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);

        $this->makeProduct($this->tenantA, 'Memoria Fury Beast 16GB', brand: 'Kingston', sku: 'KF-16');
        $this->makeProduct($this->tenantA, 'SSD NV2 1TB', brand: 'Kingston', sku: 'NV2-1TB');
        $this->makeProduct($this->tenantA, 'Procesador Ryzen 5', brand: 'AMD', sku: 'R5-7600');
    }

    private function makeProduct(
        Tenant $tenant,
        string $name,
        string $brand,
        string $sku,
        bool $isActive = true,
        string $status = 'published',
    ): Product {
        $product = new Product([
            'name'      => $name,
            'brand'     => $brand,
            'sku'       => $sku,
            'price'     => 100,
            'stock'     => 5,
            'status'    => $status,
            'is_active' => $isActive,
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    /** @return array<int, string> nombres devueltos por el catálogo */
    private function search(Tenant $tenant, string $term): array
    {
        $response = $this->getJson("/api/public/{$tenant->slug}/products?search=" . urlencode($term));
        $response->assertStatus(200);

        return array_column($response->json('data'), 'name');
    }

    public function test_busca_por_marca(): void
    {
        $encontrados = $this->search($this->tenantA, 'Kingston');

        $this->assertCount(2, $encontrados);
        $this->assertContains('Memoria Fury Beast 16GB', $encontrados);
        $this->assertContains('SSD NV2 1TB', $encontrados);
        $this->assertNotContains('Procesador Ryzen 5', $encontrados);
    }

    public function test_sigue_buscando_por_nombre(): void
    {
        $this->assertSame(['Procesador Ryzen 5'], $this->search($this->tenantA, 'Ryzen'));
    }

    public function test_busca_por_sku(): void
    {
        $this->assertSame(['SSD NV2 1TB'], $this->search($this->tenantA, 'NV2-1TB'));
    }

    public function test_la_busqueda_no_distingue_mayusculas(): void
    {
        $this->assertCount(2, $this->search($this->tenantA, 'kingston'));
    }

    public function test_sin_coincidencias_devuelve_vacio(): void
    {
        $this->assertSame([], $this->search($this->tenantA, 'Logitech'));
    }

    /**
     * Si los orWhere no van agrupados, el OR se mezcla con el where de tenant_id
     * y la busqueda filtra productos de OTRA tienda.
     */
    public function test_la_busqueda_no_cruza_tenants(): void
    {
        $this->makeProduct($this->tenantB, 'Teclado de la tienda B', brand: 'Kingston', sku: 'KB-B');

        $encontrados = $this->search($this->tenantA, 'Kingston');

        $this->assertCount(2, $encontrados);
        $this->assertNotContains('Teclado de la tienda B', $encontrados);
    }

    /**
     * Mismo motivo: sin agrupar, el OR se come los filtros is_active/status y
     * el catálogo publica borradores y productos desactivados.
     */
    public function test_la_busqueda_no_expone_borradores_ni_inactivos(): void
    {
        $this->makeProduct($this->tenantA, 'Borrador Kingston', brand: 'Kingston', sku: 'DRAFT-1', status: 'draft');
        $this->makeProduct($this->tenantA, 'Inactivo Kingston', brand: 'Kingston', sku: 'OFF-1', isActive: false);

        $encontrados = $this->search($this->tenantA, 'Kingston');

        $this->assertCount(2, $encontrados);
        $this->assertNotContains('Borrador Kingston', $encontrados);
        $this->assertNotContains('Inactivo Kingston', $encontrados);
    }

    // ----------------------------------------------------------------- INF-6
    //
    // Sigue siendo un LIKE, pero se pregunta por palabras y no por la cadena
    // entera, siempre sobre las mismas columnas, y los comodines de SQL dejan de
    // serlo. El porque de cada cosa, en `App\Support\Busqueda`.

    public function test_varias_palabras_encuentran_aunque_no_vayan_pegadas(): void
    {
        // El fallo que arregla INF-6: entre "Ryzen" y "7600" esta el "5", asi que
        // la cadena entera no casaba y esto devolvia cero resultados.
        $this->makeProduct($this->tenantA, 'Procesador Ryzen 5 7600X', brand: 'AMD', sku: 'R5-7600X');

        $this->assertSame(['Procesador Ryzen 5 7600X'], $this->search($this->tenantA, 'ryzen 7600x'));
    }

    public function test_el_orden_de_las_palabras_da_igual(): void
    {
        $this->makeProduct($this->tenantA, 'Placa ROG Strix B550-F', brand: 'Asus', sku: 'B550F');

        $this->assertSame(['Placa ROG Strix B550-F'], $this->search($this->tenantA, 'b550 strix'));
    }

    public function test_una_palabra_puede_venir_del_nombre_y_otra_de_la_marca(): void
    {
        // "Kingston" solo esta en la marca y "Fury" solo en el nombre: la busqueda
        // se cumple entre las dos columnas, no en una sola.
        $this->assertSame(['Memoria Fury Beast 16GB'], $this->search($this->tenantA, 'kingston fury'));
    }

    public function test_todas_las_palabras_tienen_que_aparecer(): void
    {
        // Si bastara con una, esto devolveria los dos Kingston.
        $this->assertSame([], $this->search($this->tenantA, 'kingston logitech'));
    }

    public function test_el_porcentaje_deja_de_ser_un_comodin(): void
    {
        // Antes esto devolvia el catalogo entero: el `%` del visitante entraba
        // crudo en el LIKE.
        $this->assertSame([], $this->search($this->tenantA, '%'));
        $this->assertSame([], $this->search($this->tenantA, 'King%ton'));
    }

    public function test_el_guion_bajo_deja_de_comodinear_una_letra(): void
    {
        $this->assertSame([], $this->search($this->tenantA, 'NV_-1TB'));
        $this->assertSame(['SSD NV2 1TB'], $this->search($this->tenantA, 'NV2-1TB'));
    }

    public function test_busca_por_el_sku_de_una_variante(): void
    {
        $producto = $this->makeProduct($this->tenantA, 'Memoria Fury 32GB', brand: 'Kingston', sku: 'FURY-32');

        $variante = new ProductVariant([
            'product_id' => $producto->id,
            'options'    => [['name' => 'Capacidad', 'value' => '32 GB']],
            'sku'        => 'KF432C16BB/16',
            'price'      => 300,
            'stock'      => 2,
        ]);
        $variante->tenant_id = $this->tenantA->id;
        $variante->save();

        $this->assertSame(['Memoria Fury 32GB'], $this->search($this->tenantA, 'KF432C16BB/16'));
    }

    public function test_el_resultado_mas_parecido_va_primero(): void
    {
        // Sin relevancia, estos tres salen en el orden que el dueño arrastro
        // (`sort_order`), que con una busqueda puesta no significa nada.
        $this->makeProduct($this->tenantA, 'Soporte para Kingston', brand: 'Genérico', sku: 'SOP-1');
        $this->makeProduct($this->tenantA, 'Kingston', brand: 'Kingston', sku: 'EXACTO');
        $this->makeProduct($this->tenantA, 'Kingston Fury Renegade', brand: 'Kingston', sku: 'REN-1');

        $encontrados = $this->search($this->tenantA, 'Kingston');

        // 1) el que se llama exactamente asi, 2) el que empieza por ahi,
        // 3) el que lo lleva dentro, 4) los que salieron solo por la marca.
        $this->assertSame('Kingston', $encontrados[0]);
        $this->assertSame('Kingston Fury Renegade', $encontrados[1]);
        $this->assertSame('Soporte para Kingston', $encontrados[2]);
    }

    public function test_el_orden_que_pide_el_comprador_manda_sobre_la_relevancia(): void
    {
        $this->makeProduct($this->tenantA, 'Kingston', brand: 'Kingston', sku: 'EXACTO');

        $respuesta = $this->getJson("/api/public/{$this->tenantA->slug}/products?search=kingston&sort=name");
        $respuesta->assertOk();

        $this->assertSame(
            ['Kingston', 'Memoria Fury Beast 16GB', 'SSD NV2 1TB'],
            array_column($respuesta->json('data'), 'name'),
        );
    }

    public function test_una_busqueda_con_muchas_palabras_solo_mira_las_primeras(): void
    {
        $this->makeProduct($this->tenantA, 'Placa ROG Strix B550-F Gaming WiFi II', brand: 'Asus', sku: 'B550FG');

        // Siete palabras: la septima ("ii") se descarta por el tope, asi que el
        // producto sale igual. El tope existe para que una frase pegada no se
        // convierta en veinte subconsultas, no para acertar menos.
        $encontrados = $this->search($this->tenantA, 'placa rog strix b550-f gaming wifi ii');

        $this->assertSame(['Placa ROG Strix B550-F Gaming WiFi II'], $encontrados);
    }

    public function test_los_espacios_de_sobra_no_cuentan_como_palabras(): void
    {
        $this->assertCount(2, $this->search($this->tenantA, '   kingston   '));
    }

    /**
     * El panel y la exportacion buscan con las mismas reglas que el catalogo
     * (INF-6). Antes el panel no miraba la marca y la exportacion tampoco, asi
     * que la misma palabra daba tres resultados distintos segun donde se
     * escribiera — y el CSV no traia lo que el dueño veia en pantalla.
     */
    public function test_el_panel_busca_por_marca_igual_que_la_tienda(): void
    {
        $admin = new User([
            'name' => 'Dueña', 'email' => 'duenia@tienda-a.test', 'password' => 'password123',
            'role' => 'admin', 'is_active' => true,
        ]);
        $admin->tenant_id = $this->tenantA->id;
        $admin->save();

        $cabeceras = [
            'Authorization' => 'Bearer '.$admin->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tenantA->slug,
        ];

        $enElPanel = $this->withHeaders($cabeceras)->getJson('/api/products?search=kingston')->assertOk();
        $this->assertCount(2, $enElPanel->json('data'));

        $this->app['auth']->forgetGuards();

        $csv = $this->withHeaders($cabeceras)
            ->get('/api/products/export?search=kingston')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Memoria Fury Beast 16GB', $csv);
        $this->assertStringContainsString('SSD NV2 1TB', $csv);
        $this->assertStringNotContainsString('Procesador Ryzen 5', $csv);
    }
}
