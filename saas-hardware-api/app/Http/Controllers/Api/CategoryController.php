<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        PlanGate::ensureCanCreate('categories');

        // No pasar 'id' manualmente — HasUuids + newUniqueId() genera UUID v7 automáticamente
        $category = Category::create($request->validated());

        return response()->json($category, 201);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json($category);
    }

    public function update(StoreCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());
        return response()->json($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();
        return response()->json(null, 204);
    }

    public function reorder(\Illuminate\Http\Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|uuid|exists:categories,id',
        ]);

        $tenant = app('currentTenant');

        foreach ($data['ids'] as $index => $id) {
            Category::where('id', $id)
                ->where('tenant_id', $tenant->id)
                ->update(['sort_order' => $index]);
        }

        // Invalidar caché pública
        \Illuminate\Support\Facades\Cache::forget("tenant:{$tenant->slug}:public_categories");
        \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");

        return response()->json(['message' => 'Categorías reordenadas correctamente']);
    }
}
