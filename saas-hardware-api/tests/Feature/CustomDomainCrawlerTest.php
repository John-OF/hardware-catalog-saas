<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vista previa al compartir para tiendas con DOMINIO PROPIO (FUN-9).
 *
 * Las cuatro rutas de `SeoCrawlerTest` son `/{slug}/...`: con dominio propio no
 * hay slug, así que ninguna las encontraba y compartir el enlace de una tienda
 * con dominio propio no generaba vista previa en absoluto. Aquí, mismas cuatro
 * vistas, resueltas por el HOST de la petición en vez de por el slug.
 */
class CustomDomainCrawlerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'            => 'tiendademo',
            'name'            => 'Tienda de Prueba',
            'logo_url'        => 'https://example.com/logo.png',
            'whatsapp_number' => '1234567890',
            'is_active'       => true,
            'custom_domain'   => 'midominio.com',
            'plan'            => 'pro',
            'theme'           => ['hero_subtitle' => 'Bienvenidos a la tienda'],
        ]);
        $this->tenant->forceFill(['custom_domain_verified_at' => now()])->save();
    }

    private function comoCrawler(string $path, string $userAgent = 'facebookexternalhit/1.1')
    {
        return $this->withHeaders(['User-Agent' => $userAgent])
            ->get('http://midominio.com'.$path);
    }

    public function test_crawler_recibe_la_vista_previa_del_catalogo_por_dominio_propio(): void
    {
        $this->comoCrawler('/')
            ->assertOk()
            ->assertViewIs('catalog_og')
            ->assertViewHas('title', 'Tienda de Prueba')
            ->assertViewHas('description', 'Bienvenidos a la tienda');
    }

    public function test_crawler_recibe_la_vista_previa_de_un_producto_por_dominio_propio(): void
    {
        $product = new Product([
            'name' => 'Intel Core i9', 'description' => 'Procesador de alto rendimiento.',
            'price' => 599.99, 'stock' => 10, 'status' => 'published', 'is_active' => true,
            'image_url' => 'https://example.com/i9.png',
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        $this->comoCrawler("/product/{$product->id}", 'twitterbot')
            ->assertOk()
            ->assertViewIs('catalog_og')
            ->assertViewHas('title', 'Intel Core i9 | Tienda de Prueba');
    }

    public function test_crawler_recibe_la_vista_previa_de_una_pagina_por_dominio_propio(): void
    {
        $page = new Page([
            'title' => 'Quiénes Somos', 'slug' => 'quienes-somos',
            'content' => 'Somos una empresa líder.', 'is_active' => true,
        ]);
        $page->tenant_id = $this->tenant->id;
        $page->save();

        $this->comoCrawler('/p/quienes-somos', 'googlebot')
            ->assertOk()
            ->assertViewIs('catalog_og')
            ->assertViewHas('title', 'Quiénes Somos | Tienda de Prueba');
    }

    public function test_crawler_recibe_la_vista_previa_del_armador_por_dominio_propio(): void
    {
        $this->comoCrawler('/builder', 'discordbot')
            ->assertOk()
            ->assertViewIs('catalog_og')
            ->assertViewHas('title', 'Armador de PC compatible | Tienda de Prueba');
    }

    /**
     * A diferencia del caso con slug, aquí un humano no puede quedarse en la
     * ruta pedida tal cual -sin slug, FRONTEND_URL no sabría de qué tienda se
     * trata-, así que sale con el slug puesto (`fallbackToSpaConSlug`).
     */
    public function test_un_humano_se_redirige_al_frontend_con_el_slug(): void
    {
        $this->get('http://midominio.com/builder')
            ->assertRedirect('http://localhost:5173/tiendademo/builder');
    }

    public function test_un_dominio_sin_verificar_no_genera_vista_previa(): void
    {
        $this->tenant->forceFill(['custom_domain_verified_at' => null])->save();

        $this->comoCrawler('/')->assertStatus(404);
    }

    public function test_un_dominio_que_no_es_de_nadie_da_404_en_las_rutas_sin_raiz(): void
    {
        $this->withHeaders(['User-Agent' => 'facebookexternalhit'])
            ->get('http://dominio-que-no-existe.com/builder')
            ->assertStatus(404);
    }

    /**
     * La raíz es el único de los cuatro paths que YA tenía dueño antes de
     * FUN-9 (`Route::get('/', fn () => view('welcome'))`). Un host que no es
     * ni la app ni ningún dominio verificado -una IP, un alias, un dominio que
     * no existe- tiene que seguir cayendo en esa vista, no en un 404 nuevo.
     */
    public function test_un_dominio_que_no_es_de_nadie_en_la_raiz_cae_en_welcome(): void
    {
        $this->withHeaders(['User-Agent' => 'facebookexternalhit'])
            ->get('http://dominio-que-no-existe.com/')
            ->assertOk()
            ->assertViewIs('welcome');
    }

    /**
     * La ruta comodín de dominio propio no puede tragarse las peticiones a la
     * propia aplicación: la raíz sin dominio propio sigue siendo la vista
     * `welcome`, y el catálogo por slug sigue funcionando igual que siempre.
     */
    public function test_el_dominio_de_la_propia_app_no_lo_captura_esta_ruta(): void
    {
        $this->get('/')->assertOk()->assertViewIs('welcome');

        $this->withHeaders(['User-Agent' => 'facebookexternalhit'])
            ->get('/tiendademo')
            ->assertOk()
            ->assertViewIs('catalog_og');
    }
}
