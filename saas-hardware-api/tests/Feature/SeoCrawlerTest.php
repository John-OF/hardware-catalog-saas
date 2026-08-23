<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoCrawlerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'tiendademo',
            'name' => 'Tienda de Prueba',
            'logo_url' => 'https://example.com/logo.png',
            'whatsapp_number' => '1234567890',
            'is_active' => true,
            'theme' => [
                'hero_subtitle' => 'Bienvenidos a la tienda',
                'page_title' => 'Tienda Demo'
            ]
        ]);

        // A proposito NO se enlaza aqui `currentTenant`. Antes se hacia, y eso
        // tapaba el fallo: en produccion un crawler llega sin tienda resuelta, y
        // con el scope de AUD-4 fallando en cerrado la vista previa habria sido
        // un 404. Que estos tests corran sin tienda enlazada es justo lo que hace
        // que sirvan de red.
    }

    public function test_crawler_receives_tenant_page_og_view(): void
    {
        $response = $this->withHeaders([
            'User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_voiced_ghost.html)'
        ])->get('/tiendademo');

        $response->assertStatus(200);
        $response->assertViewIs('catalog_og');
        $response->assertViewHas('title', 'Tienda de Prueba');
        $response->assertViewHas('description', 'Bienvenidos a la tienda');
        $response->assertViewHas('image', 'https://example.com/logo.png');
    }

    public function test_crawler_receives_product_page_og_view(): void
    {
        // `tenant_id` se asigna a mano y no por `create()`: no es fillable, antes
        // se lo ponia el hook `creating` desde la tienda que enlazaba el setUp.
        $product = new Product([
            'name' => 'Intel Core i9',
            'description' => 'Procesador de alto rendimiento para gaming.',
            'price' => 599.99,
            'stock' => 10,
            'status' => 'published',
            'is_active' => true,
            'image_url' => 'https://example.com/i9.png'
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        $response = $this->withHeaders([
            'User-Agent' => 'twitterbot'
        ])->get("/tiendademo/product/{$product->id}");

        $response->assertStatus(200);
        $response->assertViewIs('catalog_og');
        $response->assertViewHas('title', 'Intel Core i9 | Tienda de Prueba');
        $response->assertViewHas('description', 'Procesador de alto rendimiento para gaming.');
        $response->assertViewHas('image', 'https://example.com/i9.png');
    }

    public function test_crawler_receives_custom_page_og_view(): void
    {
        $page = new Page([
            'title' => 'Quiénes Somos',
            'slug' => 'quienes-somos',
            'content' => 'Somos una empresa líder en componentes de PC.',
            'is_active' => true
        ]);
        $page->tenant_id = $this->tenant->id;
        $page->save();

        $response = $this->withHeaders([
            'User-Agent' => 'googlebot'
        ])->get("/tiendademo/page/quienes-somos");

        $response->assertStatus(200);
        $response->assertViewIs('catalog_og');
        $response->assertViewHas('title', 'Quiénes Somos | Tienda de Prueba');
        $response->assertViewHas('description', 'Somos una empresa líder en componentes de PC.');
        $response->assertViewHas('image', 'https://example.com/logo.png');
    }

    public function test_crawler_receives_pc_builder_og_view(): void
    {
        $response = $this->withHeaders([
            'User-Agent' => 'discordbot'
        ])->get('/tiendademo/pc-builder');

        $response->assertStatus(200);
        $response->assertViewIs('catalog_og');
        $response->assertViewHas('title', 'Armador de PC compatible | Tienda de Prueba');
        $response->assertViewHas('description', 'Arma tu computadora ideal paso a paso con compatibilidad de componentes garantizada en Tienda de Prueba.');
        $response->assertViewHas('image', 'https://example.com/logo.png');
    }

    public function test_non_crawler_is_redirected_to_spa(): void
    {
        $response = $this->get('/tiendademo/pc-builder');

        $response->assertStatus(302);
        // Debe redirigir al frontend URL (por defecto http://localhost:5173/tiendademo/pc-builder)
        $response->assertRedirect('http://localhost:5173/tiendademo/pc-builder');
    }
}
