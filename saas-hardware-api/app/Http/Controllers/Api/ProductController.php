<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private ImageService $imageService) {}

    public function index(Request $request): JsonResponse
    {
        $products = Product::with('category')
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->active_only, fn($q) => $q->where('is_active', true))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $tenant = app('currentTenant');
            $urls   = $this->imageService->uploadProductImage($request->file('image'), $tenant->slug);
            $data   = array_merge($data, $urls);
        }

        // No pasar 'id' — HasUuids genera UUID v7 automáticamente
        $product = Product::create($data);

        return response()->json($product->load('category'), 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product->load('category'));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            // Eliminar imágenes anteriores
            $this->imageService->deleteProductImages($product->image_url, $product->thumbnail_url);

            $tenant = app('currentTenant');
            $urls   = $this->imageService->uploadProductImage($request->file('image'), $tenant->slug);
            $data   = array_merge($data, $urls);
        }

        $product->update($data);

        return response()->json($product->load('category'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->imageService->deleteProductImages($product->image_url, $product->thumbnail_url);
        $product->delete();

        return response()->json(null, 204);
    }
}
