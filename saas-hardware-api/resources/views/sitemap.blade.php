{{--
    El sitemap de una tienda (`INF-4`).

    Sin `@php` ni lógica: las URL llegan ya resueltas de `App\Support\Seo`, que es
    quien sabe qué está publicado y qué no. Un sitemap que liste un borrador manda
    al buscador a un 404, y eso lo paga la tienda entera en reputación de rastreo.

    Sin `<priority>` ni `<changefreq>`: Google dice desde hace años que los
    ignora, y ponerlos sólo añade líneas que alguien tendrá que mantener.
--}}
<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@if (! empty($url['lastmod']))
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endif
    </url>
@endforeach
</urlset>
