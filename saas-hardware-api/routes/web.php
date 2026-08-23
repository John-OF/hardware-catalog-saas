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

Route::get('/', function () {
    return view('welcome');
});

// Ruta para producto (Open Graph Crawler Check)
Route::get('/{slug}/product/{productId}', function ($slug, $productId) {
    if (isCrawler()) {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();
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
Route::get('/{slug}/page/{pageSlug}', function ($slug, $pageSlug) {
    if (isCrawler()) {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();
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
Route::get('/{slug}/pc-builder', function ($slug) {
    if (isCrawler()) {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();
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
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();
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
