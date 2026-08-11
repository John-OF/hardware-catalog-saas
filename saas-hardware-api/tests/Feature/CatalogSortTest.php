<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fija el orden del catálogo público (PUB-1).
 *
 * Ordenar por precio es EL comportamiento de compra en componentes, y tiene dos
 * trampas: el precio que cuenta es el de oferta cuando existe, y la respuesta va
 * cacheada, así que el orden tiene que formar parte de la clave de caché.
 */
class CatalogSortTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-orden',
            'name'            => 'Tienda Orden',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        // "Rebajada" tiene el precio de lista más alto pero el precio visible más
        // bajo: si el orden mirara `price` en vez del precio de oferta, saldría última.
        $this->makeProduct('Cara',     price: 300, salePrice: null, sortOrder: 1);
        $this->makeProduct('Barata',   price: 100, salePrice: null, sortOrder: 2);
        $this->makeProduct('Rebajada', price: 500, salePrice: 50,   sortOrder: 3);
    }

    private function makeProduct(string $name, float $price, ?float $salePrice, int $sortOrder): Product
    {
        $product = new Product([
            'name'       => $name,
            'price'      => $price,
            'sale_price' => $salePrice,
            'stock'      => 5,
            'sort_order' => $sortOrder,
            'status'     => 'published',
            'is_active'  => true,
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        return $product;
    }

    /** @return array<int, string> nombres en el orden devuelto por el catálogo */
    private function fetchNames(?string $sort = null): array
    {
        $url = "/api/public/{$this->tenant->slug}/products";
        if ($sort !== null) {
            $url .= '?sort=' . $sort;
        }

        $response = $this->getJson($url);
        $response->assertStatus(200);

        return array_column($response->json('data'), 'name');
    }

    public function test_por_defecto_respeta_el_orden_manual_del_dueno(): void
    {
        $this->assertSame(['Cara', 'Barata', 'Rebajada'], $this->fetchNames());
    }

    public function test_precio_ascendente_usa_el_precio_de_oferta(): void
    {
        // Rebajada (50) < Barata (100) < Cara (300), pese a que Rebajada
        // tiene el price más alto de las tres.
        $this->assertSame(['Rebajada', 'Barata', 'Cara'], $this->fetchNames('price_asc'));
    }

    public function test_precio_descendente_usa_el_precio_de_oferta(): void
    {
        $this->assertSame(['Cara', 'Barata', 'Rebajada'], $this->fetchNames('price_desc'));
    }

    public function test_orden_por_nombre(): void
    {
        $this->assertSame(['Barata', 'Cara', 'Rebajada'], $this->fetchNames('name'));
    }

    public function test_orden_por_novedad(): void
    {
        // Se crearon en orden Cara, Barata, Rebajada: la más nueva va primero.
        $this->assertSame(['Rebajada', 'Barata', 'Cara'], $this->fetchNames('newest'));
    }

    /**
     * Un `sort` desconocido no debe romper ni colarse en el SQL: cae al orden
     * por defecto.
     */
    public function test_un_sort_desconocido_cae_al_orden_por_defecto(): void
    {
        $this->assertSame(['Cara', 'Barata', 'Rebajada'], $this->fetchNames('precio; DROP TABLE products'));

        $this->assertDatabaseCount('products', 3);
    }

    /**
     * El caso que se escapa fácil: la respuesta va cacheada 5 minutos, así que si
     * `sort` no entra en la clave, pedir otro orden devuelve el anterior.
     */
    public function test_la_cache_distingue_entre_ordenes(): void
    {
        $asc = $this->fetchNames('price_asc');
        $desc = $this->fetchNames('price_desc');

        $this->assertSame(['Rebajada', 'Barata', 'Cara'], $asc);
        $this->assertSame(['Cara', 'Barata', 'Rebajada'], $desc);
        $this->assertNotSame($asc, $desc);

        // Y el orden por defecto tampoco queda contaminado por los anteriores.
        $this->assertSame(['Cara', 'Barata', 'Rebajada'], $this->fetchNames());
    }

    /**
     * Con precios repetidos hace falta un desempate estable o los productos
     * pueden repetirse o desaparecer al pasar de página.
     */
    public function test_el_orden_es_estable_con_precios_repetidos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeProduct("Empate {$i}", price: 100, salePrice: null, sortOrder: 10 + $i);
        }

        $primera = $this->fetchNames('price_asc');
        $segunda = $this->fetchNames('price_asc');

        $this->assertSame($primera, $segunda);
        $this->assertSame(count($primera), count(array_unique($primera)));
    }
}
