<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function index(): JsonResponse
    {
        // BelongsToTenant scope resolverá sólo las páginas de este tenant
        $pages = Page::orderBy('created_at', 'desc')->get();
        return response()->json($pages);
    }

    public function store(Request $request): JsonResponse
    {
        PlanGate::ensureCanCreate('pages');

        $tenant = app('currentTenant');

        $data = $request->validate([
            'title'     => 'required|string|max:200',
            'slug'      => [
                'required',
                'string',
                'max:200',
                'alpha_dash',
                Rule::unique('pages')->where(fn ($q) => $q->where('tenant_id', $tenant->id))
            ],
            'content'   => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $page = Page::create($data);

        return response()->json($page, 201);
    }

    public function show(Page $page): JsonResponse
    {
        return response()->json($page);
    }

    public function update(Request $request, Page $page): JsonResponse
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'title'     => 'sometimes|string|max:200',
            'slug'      => [
                'sometimes',
                'string',
                'max:200',
                'alpha_dash',
                Rule::unique('pages')->ignore($page->id)->where(fn ($q) => $q->where('tenant_id', $tenant->id))
            ],
            'content'   => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $page->update($data);

        return response()->json($page);
    }

    public function destroy(Page $page): JsonResponse
    {
        $page->delete();
        return response()->json(null, 204);
    }
}
