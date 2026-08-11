<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
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
}
