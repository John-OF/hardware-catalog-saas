<?php

use Illuminate\Support\Facades\Route;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Page;

if (!function_exists('isCrawler')) {
    function isCrawler() {
        $userAgent = request()->header('User-Agent');
        if (empty($userAgent)) {
            return false;
        }
        $crawlers = [
            'facebookexternalhit',
            'twitterbot',
            'whatsapp',
            'discordbot',
            'googlebot',
            'bingbot',
            'telegrambot',
            'slackbot',
        ];
        foreach ($crawlers as $crawler) {
            if (str_contains(strtolower($userAgent), $crawler)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('fallbackToSpa')) {
    function fallbackToSpa() {
        // config() y no env(): con config:cache activo env() devuelve null en
        // runtime y el crawler acabaria redirigido al fallback equivocado.
        $frontendUrl = config('app.frontend_url');
        $frontendUrl = rtrim($frontendUrl, '/');
        $path = request()->getRequestUri();
        return redirect($frontendUrl . $path);
    }
}

if (!function_exists('fallbackToSpaConSlug')) {
    /**
     * Como fallbackToSpa(), pero para las rutas de dominio propio (FUN-9).
     *
     * La diferencia importa: un humano que llega por SU dominio y no es un
     * crawler tiene que salir hacia `FRONTEND_URL`, que no es su dominio. Sin
     * el slug delante, esa URL no tiene forma de saber a que tienda pertenece
     * -no hay dominio propio que la identifique en el host de FRONTEND_URL-,
     * asi que aqui se redirige con el slug y no con la ruta pedida tal cual.
     * Es el mismo par que resuelve `StoreUrl::forTenant()`, al reves.
     */
    function fallbackToSpaConSlug(string $slug, string $pathSinDominio) {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        return redirect($frontendUrl . '/' . $slug . $pathSinDominio);
    }
}

if (!function_exists('tenantPorDominioVerificado')) {
    /**
     * La tienda de un dominio propio, o null (FUN-9).
     *
     * Las mismas dos condiciones que `Tenant::scopePublica()` mas
     * `conDominioVerificado()` (FUN-6): sin domino VERIFICADO -no basta con que
     * la columna tenga un valor- este host no identifica a nadie todavia, y
     * servirle una vista previa seria hacer publico el catalogo de una tienda
     * cuyo dueno nunca demostro ser dueno de ese dominio.
     */
    function tenantPorDominioVerificado(string $host): ?Tenant {
        return Tenant::publica()->conDominioVerificado()->where('custom_domain', $host)->first();
    }
}

if (!function_exists('urlPublicaDe')) {
    /**
     * La URL que hay que indexar de una tienda (INF-4).
     *
     * Con dominio propio es su host; con slug, la del frontend y NO la de la
     * API que esta contestando: es la que la gente comparte y a la que sale
     * redirigido cualquier humano, asi que indexar la de la API seria mandar a
     * la gente a una URL que la echa a otro sitio.
     */
    function urlPublicaDe(Tenant $tenant): string {
        return rtrim((string) \App\Support\StoreUrl::forTenant($tenant), '/');
    }
}

if (!function_exists('robotsDeLaPlataforma')) {
    /**
     * El robots.txt del host de la aplicacion (INF-4).
     *
     * Este host NO es donde vive ninguna tienda -las de slug viven en
     * FRONTEND_URL y las de dominio propio en el suyo-, asi que aqui no hay un
     * sitemap global que anunciar: la plataforma no es un directorio de tiendas.
     *
     * **OJO:** Laravel trae un `public/robots.txt` estatico y el servidor web lo
     * sirve ANTES de llegar a ninguna ruta. Se borro por eso: con el ahi, esta
     * ruta no se ejecutaria nunca en un servidor de verdad -y el dominio propio
     * tampoco recibiria el suyo-. La suite no lo vio, porque en pruebas no hay
     * archivos estaticos; se descubrio sirviendolo con `artisan serve`.
     */
    function robotsDeLaPlataforma() {
        return response("User-agent: *\nDisallow: /api/\nAllow: /\n")
            ->header('Content-Type', 'text/plain');
    }
}

if (!function_exists('sitemapDe')) {
    /** El XML del sitemap de una tienda (INF-4). */
    function sitemapDe(Tenant $tenant, string $base) {
        // AUD-4: el helper lee productos y paginas, y aqui no hay middleware que
        // resuelva la tienda; sin esto el sitemap saldria vacio sin decir nada.
        $tenant->makeCurrent();

        return response()
            ->view('sitemap', ['urls' => \App\Support\Seo::urlsDelSitemap($tenant, $base)])
            ->header('Content-Type', 'application/xml');
    }
}

Route::get('/', function () {
    return view('welcome');
});

/*
| robots.txt del host de la aplicacion (INF-4).
|
| Este host NO es donde vive ninguna tienda -las de slug viven en FRONTEND_URL y
| las de dominio propio en el suyo-, asi que aqui no hay un sitemap global que
| anunciar: la plataforma no es un directorio de tiendas. Lo unico que dice es
| que la API no se rastrea, que es lo que de verdad sobra en el indice.
*/
Route::get('/robots.txt', function () {
    return robotsDeLaPlataforma();
});

/*
| El sitemap de una tienda por slug (INF-4).
|
| Va aqui y no en la raiz porque una tienda sin dominio propio COMPARTE host con
| la plataforma, y `robots.txt` y `sitemap.xml` son del host y no de un path: no
| puede tener los suyos en la raiz sin pisar los de las demas. Se envia a mano en
| Search Console, que es justo para lo que existe esa pantalla.
|
| Antes que `/{slug}` a proposito: si no, ese comodin se lo traga como si
| "sitemap.xml" fuera el slug de una tienda.
*/
Route::get('/{slug}/sitemap.xml', function ($slug) {
    $tenant = Tenant::publica()->where('slug', $slug)->first();

    if (!$tenant) {
        abort(404);
    }

    return sitemapDe($tenant, urlPublicaDe($tenant));
});

// FUN-5: las cuatro rutas de crawler resuelven la tienda con `Tenant::publica()`,
// que es "activa Y publicada". Antes ponian `where('is_active', true)` a mano, y
// con la verificacion del correo esa condicion pasa a ser dos: si aqui se hubiera
// quedado la mitad, una tienda sin verificar seguiria generando vista previa al
// compartirla en WhatsApp mientras su catalogo devuelve 404. La regla vive entera
// en el scope del modelo justamente para que no se pueda copiar a medias.

// Ruta para producto (Open Graph Crawler Check)
Route::get('/{slug}/product/{productId}', function ($slug, $productId) {
    if (isCrawler()) {
        $tenant = Tenant::publica()->where('slug', $slug)->first();
        if (!$tenant) {
            abort(404);
        }
        // AUD-4: el scope de BelongsToTenant falla en cerrado, y aqui no hay
        // middleware que resuelva la tienda —estas rutas las pide un crawler,
        // no el frontend—, asi que sin esto la consulta no devolveria nada y la
        // vista previa de WhatsApp seria un 404. Ademas de arreglarlo, deja la
        // red debajo: el filtro por tenant_id de abajo pasa a ser el segundo.
        $tenant->makeCurrent();

        $product = Product::where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->where('is_active', true)
            ->where('status', 'published')
            ->first();
            
        if (!$product) {
            abort(404);
        }
        
        $base = urlPublicaDe($tenant);

        return view('catalog_og', \App\Support\Seo::producto($tenant, $product->load('images'), $base.'/product/'.$product->id, $base));
    }
    
    return fallbackToSpa();
});

// Ruta para página informativa (Open Graph Crawler Check)
//
// FUN-7: el path es `/p/` y no `/page/` porque tiene que ser EL MISMO que la
// aplicación pone en el enlace (`StoreFooter.tsx`, buildPath(`/p/${slug}`)); si
// no coinciden, esta ruta no la pide nadie y compartir una página informativa se
// queda sin vista previa. Estuvo así desde que se añadió el Open Graph y no se
// notó porque el test la pedía por el path equivocado, el mismo que registraba
// esta línea. Al tocar la URL pública de una página hay que cambiar las dos.
Route::get('/{slug}/p/{pageSlug}', function ($slug, $pageSlug) {
    if (isCrawler()) {
        $tenant = Tenant::publica()->where('slug', $slug)->first();
        if (!$tenant) {
            abort(404);
        }
        // Mismo motivo que en la ruta de producto (AUD-4).
        $tenant->makeCurrent();

        $page = Page::where('tenant_id', $tenant->id)
            ->where('slug', $pageSlug)
            ->where('is_active', true)
            ->first();
            
        if (!$page) {
            abort(404);
        }
        
        $base = urlPublicaDe($tenant);

        return view('catalog_og', \App\Support\Seo::pagina($tenant, $page, $base.'/p/'.$page->slug, $base));
    }
    
    return fallbackToSpa();
});

// Ruta para armador de PC (Open Graph Crawler Check)
//
// FUN-7, mismo caso que la de arriba: la aplicación enlaza `/builder`
// (`CatalogPage.tsx`, getPublicPath('/builder')), no `/pc-builder`.
Route::get('/{slug}/builder', function ($slug) {
    if (isCrawler()) {
        $tenant = Tenant::publica()->where('slug', $slug)->first();
        if (!$tenant) {
            abort(404);
        }
        
        // Mismo motivo que en las otras (AUD-4): el helper lee paginas y
        // productos, y sin tienda resuelta el scope no devolveria nada.
        $tenant->makeCurrent();

        $base = urlPublicaDe($tenant);

        return view('catalog_og', \App\Support\Seo::armador($tenant, $base.'/builder', $base));
    }
    
    return fallbackToSpa();
});

// Ruta para catálogo de tienda (Open Graph Crawler Check)
Route::get('/{slug}', function ($slug) {
    if (isCrawler()) {
        $tenant = Tenant::publica()->where('slug', $slug)->first();
        if (!$tenant) {
            abort(404);
        }

        $tenant->makeCurrent();

        return view('catalog_og', \App\Support\Seo::catalogo($tenant, urlPublicaDe($tenant)));
    }

    return fallbackToSpa();
});

/*
| Las mismas cuatro vistas previas, para tiendas con DOMINIO PROPIO (FUN-9).
|
| Hasta aquí las cuatro rutas de arriba son `/{slug}/...`: con dominio propio no
| hay slug -la tienda se sirve en `midominio.com/product/123`-, así que ninguna
| las encontraba y compartir un enlace de una tienda con dominio propio no
| generaba vista previa en absoluto. Aquí no hace falta el slug en el path
| porque el dominio YA identifica la tienda: `Route::domain('{tenantDominio}')`
| lo captura como parámetro de ruta.
|
| El `where()` excluye el host de la propia aplicación A PROPÓSITO: sin él, este
| comodín intentaría resolver TAMBIÉN las peticiones a la app -la raíz '/', que
| ya sirve la vista welcome de arriba, por ejemplo- porque `{tenantDominio}`
| coincide con cualquier host que llegue. Ningún tenant puede verificar de
| verdad el dominio de la plataforma -no controla su DNS, que es justo lo que
| exige `FUN-6`-, así que la exclusión no le quita nada a nadie real.
|
| Se resuelve el tenant ANTES de mirar si quien pide es un crawler, a diferencia
| de las cuatro rutas de arriba: aquí hace falta el `slug` en los dos casos, no
| solo para la vista previa sino también para el redirect de un humano (ver
| `fallbackToSpaConSlug`), así que hay una sola búsqueda y un solo 404 para
| "este dominio no es de nadie" en vez de repetirla en cada rama.
*/
Route::group([
    'domain' => '{tenantDominio}',
    'where'  => [
        'tenantDominio' => '^(?!' . preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST), '/') . '$).+$',
    ],
], function () {
        /*
        | robots.txt y sitemap.xml de un dominio propio (INF-4).
        |
        | Aqui SI van en la raiz, y es la diferencia que hace valioso el dominio
        | propio para SEO: los dos son del host, no de un path, asi que una
        | tienda con dominio propio puede tener los suyos de verdad -y el
        | `Sitemap:` del robots.txt es lo que hace que un buscador lo encuentre
        | solo, sin que nadie lo envie a mano-. Una tienda por slug comparte host
        | con la plataforma y no puede.
        */
        Route::get('/robots.txt', function ($tenantDominio) {
            $tenant = tenantPorDominioVerificado($tenantDominio);

            // Un host que no es de ninguna tienda -un alias de la propia app,
            // una IP, un staging- recibe el robots.txt de la plataforma en vez
            // de un 404: es lo que de verdad es, y un 404 aqui deja al buscador
            // sin ninguna instruccion.
            if (!$tenant) {
                return robotsDeLaPlataforma();
            }

            $base = urlPublicaDe($tenant);

            // En una sola linea con \n a proposito: partida en varias, la cadena
            // se lleva los finales de linea del archivo -CRLF en Windows- y el
            // robots.txt sale con \r\n. Se vio sirviendolo de verdad, no en la
            // suite.
            return response("User-agent: *\nAllow: /\n\nSitemap: {$base}/sitemap.xml\n")
                ->header('Content-Type', 'text/plain');
        });

        Route::get('/sitemap.xml', function ($tenantDominio) {
            $tenant = tenantPorDominioVerificado($tenantDominio);

            if (!$tenant) {
                abort(404);
            }

            return sitemapDe($tenant, urlPublicaDe($tenant));
        });

        Route::get('/product/{productId}', function ($tenantDominio, $productId) {
            $tenant = tenantPorDominioVerificado($tenantDominio);
            if (!$tenant) {
                abort(404);
            }

            if (!isCrawler()) {
                return fallbackToSpaConSlug($tenant->slug, '/product/' . $productId);
            }

            // Mismo motivo que en la ruta con slug (AUD-4): sin middleware que
            // resuelva la tienda, el scope de BelongsToTenant no dejaria ver el
            // producto.
            $tenant->makeCurrent();

            $product = Product::where('tenant_id', $tenant->id)
                ->where('id', $productId)
                ->where('is_active', true)
                ->where('status', 'published')
                ->first();

            if (!$product) {
                abort(404);
            }

            $base = urlPublicaDe($tenant);

            return view('catalog_og', \App\Support\Seo::producto($tenant, $product->load('images'), $base.'/product/'.$product->id, $base));
        });

        Route::get('/p/{pageSlug}', function ($tenantDominio, $pageSlug) {
            $tenant = tenantPorDominioVerificado($tenantDominio);
            if (!$tenant) {
                abort(404);
            }

            if (!isCrawler()) {
                return fallbackToSpaConSlug($tenant->slug, '/p/' . $pageSlug);
            }

            $tenant->makeCurrent();

            $page = Page::where('tenant_id', $tenant->id)
                ->where('slug', $pageSlug)
                ->where('is_active', true)
                ->first();

            if (!$page) {
                abort(404);
            }

            $base = urlPublicaDe($tenant);

            return view('catalog_og', \App\Support\Seo::pagina($tenant, $page, $base.'/p/'.$page->slug, $base));
        });

        Route::get('/builder', function ($tenantDominio) {
            $tenant = tenantPorDominioVerificado($tenantDominio);
            if (!$tenant) {
                abort(404);
            }

            if (!isCrawler()) {
                return fallbackToSpaConSlug($tenant->slug, '/builder');
            }

            $tenant->makeCurrent();

            $base = urlPublicaDe($tenant);

            return view('catalog_og', \App\Support\Seo::armador($tenant, $base.'/builder', $base));
        });

        Route::get('/', function ($tenantDominio) {
            $tenant = tenantPorDominioVerificado($tenantDominio);
            if (!$tenant) {
                // Dos motivos MUY distintos para no encontrar tienda, y no es
                // el mismo 404 para los dos (FUN-6): si alguien SÍ pidió este
                // dominio y no lo ha verificado (o la tienda está suspendida),
                // sigue sin servir nada -es justo lo que impide la
                // verificación-, así que 404 de verdad.
                if (Tenant::where('custom_domain', $tenantDominio)->exists()) {
                    abort(404);
                }

                // Nadie tiene este host como dominio propio: probablemente un
                // alias de la propia app que el `where` del grupo no cubría
                // -una IP, un www., un dominio de staging-. A diferencia de
                // las otras tres rutas de este grupo, la raíz SÍ tenía ya un
                // dueño antes de FUN-9 (`Route::get('/', ...)` de arriba, que
                // pinta `welcome`), y sin esto perdería esa vista por defecto.
                // El `abort(404)` de las otras tres sigue siendo correcto
                // porque, sin slug, nunca hubo ninguna ruta anterior que las
                // sirviera.
                return view('welcome');
            }

            if (!isCrawler()) {
                return fallbackToSpaConSlug($tenant->slug, '');
            }

            $tenant->makeCurrent();

            return view('catalog_og', \App\Support\Seo::catalogo($tenant, urlPublicaDe($tenant)));
        });
    });
