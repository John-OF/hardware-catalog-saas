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

Route::get('/', function () {
    return view('welcome');
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
        
        $title = "{$product->name} | {$tenant->name}";
        $description = $product->description ? substr(strip_tags($product->description), 0, 160) : "Comprar {$product->name} en {$tenant->name}.";
        
        // Obtener imagen del producto
        $image = null;
        if ($product->images()->count() > 0) {
            $image = $product->images()->first()->image_url;
        } elseif ($product->image_url) {
            $image = $product->image_url;
        } elseif ($tenant->logo_url) {
            $image = $tenant->logo_url;
        }
        
        $url = request()->url();
        
        return view('catalog_og', compact('title', 'description', 'image', 'url'));
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
        
        $title = "{$page->title} | {$tenant->name}";
        $description = $page->content ? substr(strip_tags($page->content), 0, 160) : "Página informativa {$page->title} en {$tenant->name}.";
        $image = $tenant->logo_url;
        $url = request()->url();
        
        return view('catalog_og', compact('title', 'description', 'image', 'url'));
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
        
        $title = "Armador de PC compatible | {$tenant->name}";
        $description = "Arma tu computadora ideal paso a paso con compatibilidad de componentes garantizada en {$tenant->name}.";
        $image = $tenant->logo_url;
        $url = request()->url();
        
        return view('catalog_og', compact('title', 'description', 'image', 'url'));
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

        $title = $tenant->name;
        $description = ($tenant->theme['hero_subtitle'] ?? null) ?: "Catálogo oficial de {$tenant->name}.";
        $image = $tenant->logo_url;
        $url = request()->url();

        return view('catalog_og', compact('title', 'description', 'image', 'url'));
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

            $title = "{$product->name} | {$tenant->name}";
            $description = $product->description ? substr(strip_tags($product->description), 0, 160) : "Comprar {$product->name} en {$tenant->name}.";

            $image = null;
            if ($product->images()->count() > 0) {
                $image = $product->images()->first()->image_url;
            } elseif ($product->image_url) {
                $image = $product->image_url;
            } elseif ($tenant->logo_url) {
                $image = $tenant->logo_url;
            }

            $url = request()->url();

            return view('catalog_og', compact('title', 'description', 'image', 'url'));
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

            $title = "{$page->title} | {$tenant->name}";
            $description = $page->content ? substr(strip_tags($page->content), 0, 160) : "Página informativa {$page->title} en {$tenant->name}.";
            $image = $tenant->logo_url;
            $url = request()->url();

            return view('catalog_og', compact('title', 'description', 'image', 'url'));
        });

        Route::get('/builder', function ($tenantDominio) {
            $tenant = tenantPorDominioVerificado($tenantDominio);
            if (!$tenant) {
                abort(404);
            }

            if (!isCrawler()) {
                return fallbackToSpaConSlug($tenant->slug, '/builder');
            }

            $title = "Armador de PC compatible | {$tenant->name}";
            $description = "Arma tu computadora ideal paso a paso con compatibilidad de componentes garantizada en {$tenant->name}.";
            $image = $tenant->logo_url;
            $url = request()->url();

            return view('catalog_og', compact('title', 'description', 'image', 'url'));
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

            $title = $tenant->name;
            $description = ($tenant->theme['hero_subtitle'] ?? null) ?: "Catálogo oficial de {$tenant->name}.";
            $image = $tenant->logo_url;
            $url = request()->url();

            return view('catalog_og', compact('title', 'description', 'image', 'url'));
        });
    });
