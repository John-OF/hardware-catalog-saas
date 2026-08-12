<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;

class ImageService
{
    /**
     * Procesa y sube una imagen de producto o logo.
     * Devuelve array con 'image_url' y 'thumbnail_url'.
     */
    public function uploadProductImage(UploadedFile $file, string $tenantSlug): array
    {
        $filename = Str::uuid()->toString();
        $folder   = "products/{$tenantSlug}";

        // Imagen principal: máx 1200×1200 px, WebP calidad 85
        $mainImage = Image::decodePath($file->getRealPath())
            ->scaleDown(width: 1200, height: 1200)
            ->encode(new WebpEncoder(quality: 85));

        $mainPath = "{$folder}/{$filename}.webp";
        // En desarrollo, si no está configurado R2, guardar local
        $disk = config('filesystems.default') === 'r2' ? 'r2' : 'public';
        Storage::disk($disk)->put($mainPath, $mainImage->toString());

        // Thumbnail: 400×400 px, recortado centrado
        $thumb = Image::decodePath($file->getRealPath())
            ->cover(width: 400, height: 400)
            ->encode(new WebpEncoder(quality: 80));

        $thumbPath = "{$folder}/{$filename}_thumb.webp";
        Storage::disk($disk)->put($thumbPath, $thumb->toString());

        return [
            'image_url'     => Storage::disk($disk)->url($mainPath),
            'thumbnail_url' => Storage::disk($disk)->url($thumbPath),
        ];
    }

    /**
     * Sube un favicon SIN pasar por el pipeline de WebP/escalado: un favicon
     * debe conservar su formato original (.ico/.png) y ser pequeño.
     * Devuelve la URL pública del archivo.
     */
    public function uploadFavicon(UploadedFile $file, string $tenantSlug): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');

        // Segunda barrera además de la validación del controlador (TEC-7): la
        // extensión decide con qué Content-Type se sirve el archivo, y un .svg
        // servido desde el dominio de la tienda puede ejecutar JavaScript.
        if (! in_array($ext, ['png', 'ico'], true)) {
            $ext = 'png';
        }

        $path = "branding/{$tenantSlug}/favicon-" . Str::uuid()->toString() . ".{$ext}";

        $disk = config('filesystems.default') === 'r2' ? 'r2' : 'public';
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        return Storage::disk($disk)->url($path);
    }

    /**
     * Elimina las imágenes anteriores del storage.
     */
    public function deleteProductImages(?string $imageUrl, ?string $thumbUrl): void
    {
        $disk = config('filesystems.default') === 'r2' ? 'r2' : 'public';

        foreach ([$imageUrl, $thumbUrl] as $url) {
            if ($url) {
                $path = parse_url($url, PHP_URL_PATH);
                // Si la URL es local, quitar /storage/ para obtener el path correcto
                $relativePath = ltrim($path, '/');
                if (str_starts_with($relativePath, 'storage/')) {
                    $relativePath = substr($relativePath, 8);
                }
                Storage::disk($disk)->delete($relativePath);
            }
        }
    }
}
