<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Facetas de especificaciones del catalogo publico (PUB-2 / 8.2).
 *
 * Antes el frontend armaba los filtros con los 24 productos de la pagina
 * visible: las opciones cambiaban al paginar y no representaban el inventario.
 *
 * Lo que fija este test:
 *
 * 1. Las facetas cubren TODO el catalogo, no una pagina.
 * 2. No se cuelan specs de otra tienda, ni de borradores, ni de productos
 *    desactivados: seria una fuga de inventario en un filtro publico.
 * 3. Los valores salen en orden natural (8GB antes que 16GB).
 */
class CatalogFacetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);
    }

    private function makeProduct(Tenant $tenant, string $name, array $specs, array $overrides = []): Product
    {
        $product = new Product(array_merge([
            'name'      => $name,
            'price'     => 100,
            'stock'     => 5,
            'specs'     => $specs,
            'is_active' => true,
            'status'    => 'published',
        ], $overrides));
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    public function test_las_facetas_cubren_todo_el_catalogo_no_solo_una_pagina(): void
    {
        // 30 productos: mas de los 24 que caben en una pagina del catalogo.
        for ($i = 0; $i < 30; $i++) {
            $this->makeProduct($this->tenant, "Producto {$i}", ['Socket' => $i < 29 ? 'AM5' : 'LGA1700']);
        }

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertOk();

        // El socket del producto 30 esta fuera de la primera pagina y aun asi
        // tiene que aparecer como opcion de filtro.
        $this->assertEqualsCanonicalizing(['AM5', 'LGA1700'], $respuesta->json('specs.Socket'));
    }

    public function test_no_expone_specs_de_otra_tienda(): void
    {
        $otraTienda = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);

        $this->makeProduct($this->tenant, 'Mio', ['Socket' => 'AM5']);
        $this->makeProduct($otraTienda, 'Ajeno', ['Socket' => 'SOCKET-SECRETO']);

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertOk();

        $this->assertSame(['AM5'], $respuesta->json('specs.Socket'));
    }

    public function test_no_expone_specs_de_borradores_ni_de_desactivados(): void
    {
        $this->makeProduct($this->tenant, 'Publicado', ['Socket' => 'AM5']);
        $this->makeProduct($this->tenant, 'Borrador', ['Socket' => 'BORRADOR'], ['status' => 'draft']);
        $this->makeProduct($this->tenant, 'Desactivado', ['Socket' => 'OCULTO'], ['is_active' => false]);

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertOk();

        $this->assertSame(['AM5'], $respuesta->json('specs.Socket'));
    }

    public function test_puede_filtrarse_por_categoria(): void
    {
        // tenant_id se asigna a mano porque esta en $guarded, no en $fillable.
        $procesadores = new Category(['name' => 'Procesadores']);
        $procesadores->tenant_id = $this->tenant->id;
        $procesadores->save();

        $memorias = new Category(['name' => 'Memorias']);
        $memorias->tenant_id = $this->tenant->id;
        $memorias->save();

        $this->makeProduct($this->tenant, 'CPU', ['Socket' => 'AM5'], ['category_id' => $procesadores->id]);
        $this->makeProduct($this->tenant, 'RAM', ['Tipo' => 'DDR5'], ['category_id' => $memorias->id]);

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/facets?category_id={$procesadores->id}")
            ->assertOk();

        $this->assertArrayHasKey('Socket', $respuesta->json('specs'));
        $this->assertArrayNotHasKey('Tipo', $respuesta->json('specs'));
    }

    public function test_los_valores_van_en_orden_natural_y_sin_repetir(): void
    {
        $this->makeProduct($this->tenant, 'A', ['Memoria' => '16GB']);
        $this->makeProduct($this->tenant, 'B', ['Memoria' => '8GB']);
        $this->makeProduct($this->tenant, 'C', ['Memoria' => '32GB']);
        $this->makeProduct($this->tenant, 'D', ['Memoria' => '8GB']);

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertOk();

        // Alfabeticamente saldria 16GB, 32GB, 8GB, que es justo lo que confunde
        // al comprador.
        $this->assertSame(['8GB', '16GB', '32GB'], $respuesta->json('specs.Memoria'));
    }

    public function test_los_valores_numericos_vuelven_como_texto(): void
    {
        // PHP convierte las claves numericas de array a int. Si las facetas se
        // acumularan como claves, "24" volveria como numero y dejaria de casar
        // con el filtro, que compara contra texto.
        $this->makeProduct($this->tenant, 'CPU', ['Cores' => '24']);

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertOk();

        $this->assertSame(['24'], $respuesta->json('specs.Cores'));
        $this->assertIsString($respuesta->json('specs.Cores.0'));
    }

    public function test_ignora_claves_y_valores_vacios(): void
    {
        $this->makeProduct($this->tenant, 'Con basura', ['Socket' => 'AM5', 'Vacia' => '   ', '' => 'sin clave']);

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertOk();

        $this->assertSame(['Socket' => ['AM5']], $respuesta->json('specs'));
    }

    public function test_una_tienda_suspendida_no_expone_facetas(): void
    {
        $this->tenant->update(['is_active' => false]);

        $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertStatus(404);
    }
}
