<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

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
            'name'            => 'sometimes|string|max:200',
            'whatsapp_number' => 'sometimes|string|max:20',
            'primary_color'   => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo_url'        => 'sometimes|nullable|url|max:500',
            'logo'            => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'custom_domain'   => 'sometimes|nullable|string|max:100|unique:tenants,custom_domain,' . $tenant->id,

            // Moneda de la tienda (OWN-1). La whitelist sale de config/currencies.php,
            // que es la misma lista que ofrece el selector de Configuración.
            'currency'        => ['sometimes', 'string', Rule::in(array_keys(config('currencies')))],

            // Archivos subidos opcionales (alternativa a pegar la URL)
            'banner'          => 'nullable|image|mimes:jpeg,png,webp|max:5120',
            // Sin SVG (TEC-7): un .svg puede llevar <script> dentro y se sirve
            // desde el mismo origen que la tienda, así que aceptarlo es aceptar
            // que un dueño suba JS ejecutable. Los formatos de favicon de verdad
            // son png e ico.
            'favicon'         => 'nullable|file|mimes:png,ico|max:512',

            // Personalización visual de la tienda (theme JSON)
            'theme'               => 'sometimes|nullable|array',
            'theme.hero_title'    => 'nullable|string|max:120',
            'theme.hero_subtitle' => 'nullable|string|max:240',
            'theme.banner_url'    => 'nullable|url|max:500',
            'theme.accent_color'  => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.color_mode'    => 'nullable|in:dark,light',
            'theme.layout'        => 'nullable|in:grid,compact,list',
            'theme.font'          => 'nullable|in:sans,serif,mono,heading',
            'theme.sections'      => 'nullable|string',

            // Branding de la pestaña del navegador (título y favicon)
            'theme.page_title'    => 'nullable|string|max:60',
        ]);

        if (isset($data['theme'])) {
            $data['theme'] = array_merge($tenant->theme ?? [], $data['theme']);
        }

        if ($request->hasFile('logo')) {
            $urls = $this->imageService->uploadProductImage($request->file('logo'), $tenant->slug . '/logo');
            $data['logo_url'] = $urls['image_url'];
        }

        // El banner se procesa como imagen normal (WebP); el favicon se guarda
        // tal cual. Ambos viven dentro del JSON theme, así que partimos del
        // theme enviado (o el actual) y le inyectamos la URL resultante.
        if ($request->hasFile('banner')) {
            $urls = $this->imageService->uploadProductImage($request->file('banner'), $tenant->slug . '/banner');
            $data['theme'] = array_merge($data['theme'] ?? $tenant->theme ?? [], ['banner_url' => $urls['image_url']]);
        }

        if ($request->hasFile('favicon')) {
            $url = $this->imageService->uploadFavicon($request->file('favicon'), $tenant->slug);
            $data['theme'] = array_merge($data['theme'] ?? $tenant->theme ?? [], ['favicon_url' => $url]);
        }

        // Las claves de archivo no son columnas del modelo: quitarlas antes de guardar.
        unset($data['logo'], $data['banner'], $data['favicon']);

        $tenant->update($data);

        // Invalidar la caché pública del tenant para que el branding se refleje al instante
        Cache::forget("tenant:{$tenant->slug}");

        return response()->json($tenant->fresh());
    }
}
