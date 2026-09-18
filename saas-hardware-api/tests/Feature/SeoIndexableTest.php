<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que un buscador puede sacar de una tienda (`INF-4`).
 *
 * `SeoCrawlerTest` ya fija que un crawler recibe la vista y una persona el
 * redirect. Lo que se vigila aquí es lo otro, que es lo que estaba roto: que esa
 * vista **tenga contenido y enlaces**, que diga cuál es su URL canónica y que
 * lleve el JSON-LD que produce el resultado enriquecido. Antes era sólo
 * etiquetas `og:` y un `<h1>`: perfecto para WhatsApp y una página vacía para
 * Google.
 *
 * Lo más delicado, y por eso tiene sus propios casos:
 *
 * 1. **El canónico no es la URL que se pidió** cuando la tienda va por slug: el
 *    host que contesta es el de la API y el que la gente comparte es el del
 *    frontend.
 * 2. **El sitemap no puede listar borradores.** Mandar al buscador a un 404 lo
 *    paga la tienda entera en reputación de rastreo.
 *
 * Como en `SeoCrawlerTest`, aquí NO se enlaza `currentTenant` a propósito: un
 * crawler llega sin tienda resuelta, y el scope de `AUD-4` falla en cerrado.
 */
class SeoIndexableTest extends TestCase
{
    use RefreshDatabase;

    private const CRAWLER = ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];

    private Tenant $tienda;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://tienda.example']);

        $this->tienda = Tenant::create([
            'slug' => 'tiendaseo',
            'name' => 'Tienda SEO',
            'logo_url' => 'https://example.com/logo.png',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
            'currency' => 'PEN',
            'theme' => ['hero_subtitle' => 'Componentes de PC al mejor precio'],
        ]);
    }

    // -------------------------------------------------- contenido y enlaces

    public function test_el_catalogo_que_ve_el_crawler_lleva_productos_con_enlace_y_precio(): void
    {
        $uno = $this->producto('RTX 4070', 2800);
        $this->producto('Ryzen 7 7800X3D', 1400);

        $html = $this->get('/tiendaseo', self::CRAWLER)->assertOk()->getContent();

        // Lo que faltaba: enlaces. Sin ellos no hay nada que rastrear detrás.
        $this->assertStringContainsString('https://tienda.example/tiendaseo/product/'.$uno->id, $html);
        $this->assertStringContainsString('RTX 4070', $html);
        $this->assertStringContainsString('Ryzen 7 7800X3D', $html);
        // Y el precio en la moneda de la tienda (OWN-1), no un $ fijo.
        $this->assertStringContainsString('S/2,800.00', $html);
    }

    public function test_el_catalogo_no_enseña_borradores_ni_desactivados(): void
    {
        $this->producto('Visible', 100);
        $this->producto('Borrador', 100, estado: 'draft');
        $this->producto('Apagado', 100, activo: false);

        $html = $this->get('/tiendaseo', self::CRAWLER)->assertOk()->getContent();

        $this->assertStringContainsString('Visible', $html);
        $this->assertStringNotContainsString('Borrador', $html);
        $this->assertStringNotContainsString('Apagado', $html);
    }

    public function test_la_ficha_lleva_specs_y_un_enlace_de_vuelta_al_catalogo(): void
    {
        $producto = $this->producto('Ryzen 7 7800X3D', 1400, specs: ['Socket' => 'AM5', 'Núcleos' => '8']);

        $html = $this->get("/tiendaseo/product/{$producto->id}", self::CRAWLER)->assertOk()->getContent();

        // Las specs son lo que de verdad se busca en este rubro.
        $this->assertStringContainsString('Socket', $html);
        $this->assertStringContainsString('AM5', $html);
        // Sin el enlace de vuelta, la ficha es un callejón sin salida.
        $this->assertStringContainsString('href="https://tienda.example/tiendaseo"', $html);
    }

    public function test_las_paginas_informativas_se_enlazan_desde_todas_partes(): void
    {
        $this->pagina('Garantía', 'garantia');

        foreach (['/tiendaseo', '/tiendaseo/builder'] as $ruta) {
            $html = $this->get($ruta, self::CRAWLER)->assertOk()->getContent();
            $this->assertStringContainsString('https://tienda.example/tiendaseo/p/garantia', $html, $ruta);
        }
    }

    // ------------------------------------------------------------ canónicos

    public function test_con_slug_el_canonico_es_la_url_del_frontend_y_no_la_de_la_api(): void
    {
        // Quien contesta es el host de la API, pero a un humano se le redirige al
        // frontend: indexar la URL de la API sería indexar una que echa a la
        // gente a otro sitio.
        $html = $this->get('/tiendaseo', self::CRAWLER)->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://tienda.example/tiendaseo">', $html);
    }

    public function test_con_dominio_propio_el_canonico_es_su_propio_dominio(): void
    {
        $this->conDominioPropio('midominio.test');

        $html = $this->get('http://midominio.test/', self::CRAWLER)->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://midominio.test">', $html);
    }

    // -------------------------------------------------------------- JSON-LD

    public function test_la_ficha_lleva_el_json_ld_de_producto_con_precio_y_disponibilidad(): void
    {
        $producto = $this->producto('RTX 4070', 2800, marca: 'NVIDIA', stock: 3);

        $html = $this->get("/tiendaseo/product/{$producto->id}", self::CRAWLER)->assertOk()->getContent();
        $datos = $this->jsonLdDe($html, 'Product');

        $this->assertSame('RTX 4070', $datos['name']);
        $this->assertSame('NVIDIA', $datos['brand']['name']);
        $this->assertSame('2800.00', $datos['offers']['price']);
        $this->assertSame('PEN', $datos['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $datos['offers']['availability']);
        $this->assertSame('https://tienda.example/tiendaseo/product/'.$producto->id, $datos['offers']['url']);
    }

    public function test_el_json_ld_promete_el_precio_que_de_verdad_se_cobra(): void
    {
        // Prometer el precio de lista y cobrar el de oferta es lo que hace que
        // Google deje de enseñar los datos de una tienda.
        $producto = $this->producto('RTX 4070', 2800);
        $producto->update(['sale_price' => 2500]);

        $datos = $this->jsonLdDe(
            $this->get("/tiendaseo/product/{$producto->id}", self::CRAWLER)->getContent(),
            'Product',
        );

        $this->assertSame('2500.00', $datos['offers']['price']);
    }

    public function test_un_producto_agotado_se_declara_agotado(): void
    {
        $producto = $this->producto('RTX 4070', 2800, stock: 0);

        $datos = $this->jsonLdDe(
            $this->get("/tiendaseo/product/{$producto->id}", self::CRAWLER)->getContent(),
            'Product',
        );

        $this->assertSame('https://schema.org/OutOfStock', $datos['offers']['availability']);
    }

    public function test_el_catalogo_se_declara_como_tienda(): void
    {
        $datos = $this->jsonLdDe($this->get('/tiendaseo', self::CRAWLER)->getContent(), 'Store');

        $this->assertSame('Tienda SEO', $datos['name']);
        $this->assertSame('https://tienda.example/tiendaseo', $datos['url']);
    }

    // -------------------------------------------------------------- sitemap

    public function test_el_sitemap_lista_las_urls_publicas_de_la_tienda(): void
    {
        $producto = $this->producto('RTX 4070', 2800);
        $this->pagina('Garantía', 'garantia');

        $respuesta = $this->get('/tiendaseo/sitemap.xml')->assertOk();
        $xml = $respuesta->getContent();

        $this->assertStringContainsString('application/xml', (string) $respuesta->headers->get('content-type'));
        $this->assertStringContainsString('<loc>https://tienda.example/tiendaseo</loc>', $xml);
        $this->assertStringContainsString('<loc>https://tienda.example/tiendaseo/builder</loc>', $xml);
        $this->assertStringContainsString('<loc>https://tienda.example/tiendaseo/p/garantia</loc>', $xml);
        $this->assertStringContainsString("<loc>https://tienda.example/tiendaseo/product/{$producto->id}</loc>", $xml);
        // Un XML de verdad, no una página con cabecera de XML.
        $this->assertStringStartsWith('<?xml', trim($xml));
    }

    public function test_el_sitemap_no_lista_borradores(): void
    {
        $borrador = $this->producto('Borrador', 100, estado: 'draft');
        $oculto = $this->pagina('Interna', 'interna', activa: false);

        $xml = $this->get('/tiendaseo/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString($borrador->id, $xml);
        $this->assertStringNotContainsString($oculto->slug, $xml);
    }

    public function test_el_sitemap_de_una_tienda_que_no_existe_o_no_esta_publicada_es_404(): void
    {
        $this->get('/inventada/sitemap.xml')->assertNotFound();

        $this->tienda->update(['is_published' => false]);
        $this->get('/tiendaseo/sitemap.xml')->assertNotFound();
    }

    public function test_el_sitemap_no_lo_pide_un_crawler_y_aun_asi_responde(): void
    {
        // Search Console y los rastreadores lo piden sin declararse crawler: si
        // esto cayera en el redirect al SPA, el sitemap no existiría para nadie.
        $this->producto('RTX 4070', 2800);

        $this->get('/tiendaseo/sitemap.xml')->assertOk();
    }

    // --------------------------------------------------------- robots.txt

    public function test_el_host_de_la_aplicacion_no_deja_rastrear_la_api(): void
    {
        $respuesta = $this->get('/robots.txt')->assertOk();

        $this->assertStringContainsString('text/plain', (string) $respuesta->headers->get('content-type'));
        $this->assertStringContainsString('Disallow: /api/', $respuesta->getContent());
    }

    public function test_un_dominio_propio_sirve_su_robots_con_su_sitemap(): void
    {
        // Es la diferencia real que da el dominio propio para SEO: robots.txt y
        // sitemap.xml son del host, así que sólo ahí pueden estar en la raíz.
        $this->conDominioPropio('midominio.test');

        $robots = $this->get('http://midominio.test/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Sitemap: https://midominio.test/sitemap.xml', $robots);

        $xml = $this->get('http://midominio.test/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>https://midominio.test</loc>', $xml);
    }

    public function test_un_dominio_sin_verificar_no_sirve_el_sitemap_de_nadie(): void
    {
        // FUN-6: sin verificar, ese host no identifica a nadie todavía.
        $this->tienda->forceFill([
            'custom_domain' => 'sinverificar.test',
            'custom_domain_verified_at' => null,
        ])->save();

        $this->get('http://sinverificar.test/sitemap.xml')->assertNotFound();

        // El robots.txt sí responde, pero con el de la plataforma: un 404 aquí
        // dejaría al buscador sin ninguna instrucción, y este host es, de hecho,
        // un alias de la propia aplicación.
        $robots = $this->get('http://sinverificar.test/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Disallow: /api/', $robots);
        $this->assertStringNotContainsString('Sitemap:', $robots);
    }

    public function test_el_robots_no_lleva_finales_de_linea_de_windows(): void
    {
        // El `\n` va dentro de una cadena de una sola línea a propósito: escrita
        // en varias, se lleva los finales de línea del archivo —CRLF en
        // Windows— y el robots.txt sale con `\r\n`. Es válido, pero no es lo que
        // se escribió, y sólo se ve sirviéndolo de verdad.
        $this->conDominioPropio('midominio.test');

        foreach (['http://midominio.test/robots.txt', '/robots.txt'] as $url) {
            $this->assertStringNotContainsString(
                "\r",
                $this->get($url)->assertOk()->getContent(),
                $url,
            );
        }
    }

    public function test_el_robots_estatico_de_laravel_no_puede_volver(): void
    {
        // Laravel trae un `public/robots.txt` y el servidor web lo sirve ANTES de
        // llegar a ninguna ruta: con él ahí, ni el host de la aplicación ni un
        // dominio propio reciben el suyo. La suite no puede verlo —en pruebas no
        // hay archivos estáticos—, así que lo que se vigila es que el archivo no
        // esté.
        $this->assertFileDoesNotExist(
            public_path('robots.txt'),
            'Volvió el robots.txt estático: tapa las rutas de INF-4 en un servidor de verdad.',
        );
    }

    // ----------------------------------------------------------------- ayudas

    private function producto(
        string $nombre,
        float $precio,
        string $estado = 'published',
        bool $activo = true,
        ?string $marca = null,
        int $stock = 5,
        array $specs = [],
    ): Product {
        $producto = new Product([
            'name' => $nombre,
            'price' => $precio,
            'brand' => $marca,
            'stock' => $stock,
            'specs' => $specs ?: null,
            'is_active' => $activo,
            'status' => $estado,
        ]);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    private function pagina(string $titulo, string $slug, bool $activa = true): Page
    {
        $pagina = new Page([
            'title' => $titulo,
            'slug' => $slug,
            'content' => '<p>Contenido de prueba</p>',
            'is_active' => $activa,
        ]);
        $pagina->tenant_id = $this->tienda->id;
        $pagina->save();

        return $pagina;
    }

    private function conDominioPropio(string $dominio): void
    {
        $this->tienda->forceFill([
            'custom_domain' => $dominio,
            'custom_domain_verified_at' => now(),
        ])->save();
    }

    /**
     * El bloque JSON-LD de un `@type` concreto dentro del HTML.
     *
     * @return array<string, mixed>
     */
    private function jsonLdDe(string $html, string $tipo): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $coincidencias);

        foreach ($coincidencias[1] as $bloque) {
            $datos = json_decode(trim($bloque), true);

            $this->assertIsArray($datos, 'Un bloque JSON-LD no es JSON válido.');

            if (($datos['@type'] ?? null) === $tipo) {
                return $datos;
            }
        }

        $this->fail("No hay ningún bloque JSON-LD de tipo {$tipo}.");
    }
}
