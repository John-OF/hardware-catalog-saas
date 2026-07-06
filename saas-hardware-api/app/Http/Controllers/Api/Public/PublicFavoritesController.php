<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicFavoritesController extends Controller
{
    /**
     * Listar todos los productos favoritos del cliente
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();
        
        $favorites = $request->user()
            ->favorites()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('status', 'published')
            ->with(['category', 'images'])
            ->get();

        return response()->json($favorites);
    }

    /**
     * Alternar (toggle) un producto en los favoritos del cliente
     */
    public function toggle(Request $request, string $slug, string $productId): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $product = Product::where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->where('is_active', true)
            ->where('status', 'published')
            ->firstOrFail();

        $user = $request->user();
        
        $hasFavorite = $user->favorites()->where('product_id', $product->id)->exists();

        if ($hasFavorite) {
            $user->favorites()->detach($product->id);
            $favorited = false;
            $message = 'Producto eliminado de favoritos.';
        } else {
            $user->favorites()->attach($product->id);
            $favorited = true;
            $message = 'Producto agregado a favoritos.';
        }

        return response()->json([
            'favorited' => $favorited,
            'message'   => $message,
        ]);
    }
}
