<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(private ImageService $imageService) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(app('currentTenant'));
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name'           => 'sometimes|string|max:200',
            'whatsapp_number'=> 'sometimes|string|max:20',
            'primary_color'  => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo'           => 'nullable|image|mimes:jpeg,png,webp|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $urls = $this->imageService->uploadProductImage($request->file('logo'), $tenant->slug . '/logo');
            $data['logo_url'] = $urls['image_url'];
        }

        $tenant->update($data);

        return response()->json($tenant);
    }
}
