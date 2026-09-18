<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Lo que se le sirve a un buscador (`INF-4`).
 *
 * **Qué estaba mal.** A Googlebot se le devolvía `catalog_og.blade.php`, que era
 * sólo etiquetas `og:` y un `<h1>`: sin catálogo, sin precios y **sin un solo
 * enlace**. Para WhatsApp está bien —lo único que lee es el `og:`—; para un
 * buscador es una página vacía y, peor, un callejón sin salida: sin enlaces no
 * hay nada que rastrear detrás. Una tienda vive del tráfico orgánico, así que
 * esto no era una mejora de forma.
 *
 * **Qué hace este helper.** Arma los datos de esas vistas en un solo sitio —los
 * pedían ocho closures casi idénticas en `routes/web.php`, cuatro con slug y
 * cuatro con dominio propio (`FUN-9`)—, incluidos el `canonical`, el JSON-LD y
 * la lista de enlaces que hacía falta.
 *
 * **La URL canónica no siempre es la que se pidió**, y es lo más delicado de
 * aquí:
 *
 * - **Con dominio propio** la tienda se sirve en su host, así que el canónico es
 *   la propia URL pedida.
 * - **Con slug** quien contesta es el host de la API, pero el enlace que la gente
 *   comparte —y al que sale redirigido cualquier humano— es el del frontend
 *   (`StoreUrl::forTenant()`). Poner como canónico el host de la API indexaría
 *   una URL que a una persona la echa a otro sitio.
 */
class Seo
{
    /**
     * Cuántos productos entran en la página del catálogo que ve el crawler.
     *
     * No es paginación: es el arranque del rastreo. Con estos enlaces el
     * buscador llega a las fichas, y de ahí al resto por el sitemap, que sí las
     * lleva todas.
     */
    private const PRODUCTOS_EN_PORTADA = 60;

    /** Tope de URL del sitemap. El estándar admite 50.000; esto es lo que cabe sin paginarlo. */
    public const MAXIMO_URLS_SITEMAP = 5000;

    /**
     * La portada del catálogo.
     *
     * @param  string  $canonical  la URL pública de la tienda
     * @return array<string, mixed>
     */
    public static function catalogo(Tenant $tenant, string $canonical): array
    {
        $productos = self::productosPublicados($tenant)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->limit(self::PRODUCTOS_EN_PORTADA)
            ->get();

        $base = rtrim($canonical, '/');

        return [
            'title' => $tenant->name,
            'description' => ($tenant->theme['hero_subtitle'] ?? null) ?: "Catálogo oficial de {$tenant->name}.",
            'image' => $tenant->logo_url,
            'url' => $canonical,
            'canonical' => $canonical,
            'tienda' => $tenant,
            'enlaces' => self::enlacesDe($tenant, $productos, $base),
            'paginas' => self::paginasDe($tenant, $base),
            'jsonLd' => [
                self::tiendaComoJsonLd($tenant, $canonical),
                self::listaComoJsonLd($productos, $base),
            ],
        ];
    }

    /**
     * La ficha de un producto: lo único con datos que Google puede enseñar
     * enriquecidos —precio, disponibilidad, marca— y por eso lo que más paga.
     *
     * @return array<string, mixed>
     */
    public static function producto(Tenant $tenant, Product $producto, string $canonical, string $baseTienda): array
    {
        $descripcion = $producto->description
            ? mb_substr(trim(strip_tags($producto->description)), 0, 160)
            : "Comprar {$producto->name} en {$tenant->name}.";

        return [
            'title' => "{$producto->name} | {$tenant->name}",
            'description' => $descripcion,
            'image' => self::imagenDe($producto, $tenant),
            'url' => $canonical,
            'canonical' => $canonical,
            'tienda' => $tenant,
            'producto' => $producto,
            // Un enlace de vuelta al catálogo: sin él, la ficha sigue siendo un
            // callejón sin salida para el rastreo.
            'enlaces' => [['url' => rtrim($baseTienda, '/'), 'texto' => "Ver todo el catálogo de {$tenant->name}"]],
            'paginas' => self::paginasDe($tenant, rtrim($baseTienda, '/')),
            'jsonLd' => [self::productoComoJsonLd($tenant, $producto, $canonical)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pagina(Tenant $tenant, Page $pagina, string $canonical, string $baseTienda): array
    {
        return [
            'title' => "{$pagina->title} | {$tenant->name}",
            'description' => $pagina->content
                ? mb_substr(trim(strip_tags($pagina->content)), 0, 160)
                : "Página informativa {$pagina->title} en {$tenant->name}.",
            'image' => $tenant->logo_url,
            'url' => $canonical,
            'canonical' => $canonical,
            'tienda' => $tenant,
            // El contenido va tal cual: ya viene saneado al guardar (SEC-3), y es
            // texto que el dueño escribió para que se lea.
            'cuerpo' => $pagina->content,
            'enlaces' => [['url' => rtrim($baseTienda, '/'), 'texto' => "Ver todo el catálogo de {$tenant->name}"]],
            'paginas' => self::paginasDe($tenant, rtrim($baseTienda, '/')),
            'jsonLd' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function armador(Tenant $tenant, string $canonical, string $baseTienda): array
    {
        return [
            'title' => "Armador de PC compatible | {$tenant->name}",
            'description' => "Arma tu computadora ideal paso a paso con compatibilidad de componentes garantizada en {$tenant->name}.",
            'image' => $tenant->logo_url,
            'url' => $canonical,
            'canonical' => $canonical,
            'tienda' => $tenant,
            'enlaces' => [['url' => rtrim($baseTienda, '/'), 'texto' => "Ver todo el catálogo de {$tenant->name}"]],
            'paginas' => self::paginasDe($tenant, rtrim($baseTienda, '/')),
            'jsonLd' => [],
        ];
    }

    /**
     * Todas las URL públicas de una tienda, para el sitemap.
     *
     * @return array<int, array{loc: string, lastmod: string|null}>
     */
    public static function urlsDelSitemap(Tenant $tenant, string $base): array
    {
        $base = rtrim($base, '/');

        $urls = [
            ['loc' => $base, 'lastmod' => $tenant->updated_at?->toAtomString()],
            ['loc' => $base.'/builder', 'lastmod' => null],
        ];

        foreach (self::paginasDe($tenant, $base) as $pagina) {
            $urls[] = ['loc' => $pagina['url'], 'lastmod' => $pagina['lastmod']];
        }

        $productos = self::productosPublicados($tenant)
            ->orderByDesc('updated_at')
            ->limit(self::MAXIMO_URLS_SITEMAP)
            ->get(['id', 'updated_at']);

        foreach ($productos as $producto) {
            $urls[] = [
                'loc' => $base.'/product/'.$producto->id,
                'lastmod' => $producto->updated_at?->toAtomString(),
            ];
        }

        return $urls;
    }

    /**
     * Los productos que el catálogo público enseña.
     *
     * Las mismas dos condiciones que `PublicCatalogController::products` —activo
     * Y publicado—: un sitemap que liste un borrador manda al buscador a un 404,
     * y eso lo paga la tienda entera en reputación de rastreo.
     *
     * `withoutTenant()` con el `tenant_id` a mano por AUD-4: aquí no hay
     * middleware que resuelva la tienda, y el fallo en cerrado devolvería un
     * sitemap vacío sin decir nada.
     */
    private static function productosPublicados(Tenant $tenant)
    {
        return Product::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('status', 'published');
    }

    /**
     * @param  Collection<int, Product>  $productos
     * @return array<int, array{url: string, texto: string}>
     */
    private static function enlacesDe(Tenant $tenant, Collection $productos, string $base): array
    {
        return $productos->map(fn (Product $p) => [
            'url' => $base.'/product/'.$p->id,
            'texto' => $p->name,
            'precio' => Money::format($p->sale_price ?? $p->price, $tenant->currency),
        ])->all();
    }

    /**
     * @return array<int, array{url: string, texto: string, lastmod: string|null}>
     */
    private static function paginasDe(Tenant $tenant, string $base): array
    {
        return Page::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['slug', 'title', 'updated_at'])
            ->map(fn (Page $p) => [
                'url' => $base.'/p/'.$p->slug,
                'texto' => $p->title,
                'lastmod' => $p->updated_at?->toAtomString(),
            ])->all();
    }

    private static function imagenDe(Product $producto, Tenant $tenant): ?string
    {
        return $producto->image_url
            ?: ($producto->images->first()?->image_url ?: $tenant->logo_url);
    }

    /**
     * @return array<string, mixed>
     */
    private static function tiendaComoJsonLd(Tenant $tenant, string $url): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Store',
            'name' => $tenant->name,
            'url' => $url,
            'image' => $tenant->logo_url,
            'telephone' => $tenant->whatsapp_number,
        ]);
    }

    /**
     * @param  Collection<int, Product>  $productos
     * @return array<string, mixed>
     */
    private static function listaComoJsonLd(Collection $productos, string $base): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $productos->values()->map(fn (Product $p, int $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'url' => $base.'/product/'.$p->id,
                'name' => $p->name,
            ])->all(),
        ];
    }

    /**
     * El `Product` de schema.org, que es lo que produce el resultado enriquecido
     * —precio, moneda y disponibilidad debajo del enlace en Google—.
     *
     * `price` es el que de verdad se cobra (el de oferta cuando existe), el mismo
     * que calcula `OrderPricing`: prometer un precio en el buscador y cobrar otro
     * es lo que hace que Google deje de enseñar los datos de una tienda.
     *
     * @return array<string, mixed>
     */
    private static function productoComoJsonLd(Tenant $tenant, Product $producto, string $url): array
    {
        $precio = $producto->sale_price ?? $producto->price;

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $producto->name,
            'description' => $producto->description ? trim(strip_tags($producto->description)) : null,
            'sku' => $producto->sku,
            'image' => array_values(array_filter([self::imagenDe($producto, $tenant)])),
            'brand' => $producto->brand ? ['@type' => 'Brand', 'name' => $producto->brand] : null,
            'offers' => array_filter([
                '@type' => 'Offer',
                'url' => $url,
                'price' => number_format((float) $precio, 2, '.', ''),
                'priceCurrency' => strtoupper($tenant->currency ?: 'USD'),
                // Los dos valores que Google entiende. Con variantes (MOD-5),
                // `stock` es la suma, que es justo lo que hay que responder aquí:
                // si queda alguna, el producto se puede comprar.
                'availability' => (int) $producto->stock > 0
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'seller' => ['@type' => 'Organization', 'name' => $tenant->name],
            ]),
        ], fn ($valor) => $valor !== null && $valor !== [] && $valor !== '');
    }
}
