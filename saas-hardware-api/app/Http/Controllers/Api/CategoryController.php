<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
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
}
