<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $mainImage = Image::read($file)
            ->scaleDown(width: 1200, height: 1200)
            ->toWebp(quality: 85);

        $mainPath = "{$folder}/{$filename}.webp";
        // En desarrollo, si no está configurado R2, guardar local
        $disk = config('filesystems.default') === 'r2' ? 'r2' : 'public';
        Storage::disk($disk)->put($mainPath, $mainImage->toString());

        // Thumbnail: 400×400 px, recortado centrado
        $thumb = Image::read($file)
            ->cover(width: 400, height: 400)
            ->toWebp(quality: 80);

        $thumbPath = "{$folder}/{$filename}_thumb.webp";
        Storage::disk($disk)->put($thumbPath, $thumb->toString());

        return [
            'image_url'     => Storage::disk($disk)->url($mainPath),
            'thumbnail_url' => Storage::disk($disk)->url($thumbPath),
        ];
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
