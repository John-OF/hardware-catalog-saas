<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicCatalogController extends Controller
{
    public function tenant(string $slug): JsonResponse
    {
        $tenant = Cache::remember("tenant:{$slug}", 300, function () use ($slug) {
            return Tenant::where('slug', $slug)
                ->where('is_active', true)
                ->select(['id', 'slug', 'name', 'logo_url', 'primary_color', 'whatsapp_number'])
                ->firstOrFail();
        });

        return response()->json($tenant);
    }

    public function products(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        // Obtener la versión de caché actual para el tenant (soporte de invalidación en driver file)
        $version = Cache::remember("tenant:{$slug}:cache_version", 86400, fn() => 1);

        // IMPORTANTE: usar only() para prevenir cache flooding con parámetros arbitrarios
        $cacheKey = "catalog:{$slug}:v{$version}:" . md5(json_encode($request->only([
            'category_id', 'search', 'in_stock', 'page'
        ])));

        $products = Cache::remember($cacheKey, 300, function () use ($tenant, $request) {
            return Product::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->with('category:id,name,icon')
                ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
                ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
                ->when($request->in_stock, fn($q) => $q->where('stock', '>', 0))
                ->orderByDesc('created_at')
                ->paginate(24);
        });

        return response()->json($products);
    }

    public function product(string $slug, string $productId): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $product = Product::where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->where('is_active', true)
            ->with('category')
            ->firstOrFail();

        return response()->json($product);
    }
}
