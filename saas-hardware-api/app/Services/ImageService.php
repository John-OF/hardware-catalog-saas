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
        $disk = $this->disco();
        $this->guardar($disk, $mainPath, $mainImage->toString());

        // Thumbnail: 400×400 px, recortado centrado
        $thumb = Image::decodePath($file->getRealPath())
            ->cover(width: 400, height: 400)
            ->encode(new WebpEncoder(quality: 80));

        $thumbPath = "{$folder}/{$filename}_thumb.webp";
        $this->guardar($disk, $thumbPath, $thumb->toString());

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

        $disk = $this->disco();
        $this->guardar($disk, $path, file_get_contents($file->getRealPath()));

        return Storage::disk($disk)->url($path);
    }

    /**
     * Elimina las imágenes anteriores del storage.
     */
    public function deleteProductImages(?string $imageUrl, ?string $thumbUrl): void
    {
        $disk = $this->disco();

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

    /**
     * Disco donde viven las imagenes. Estaba repetido en cada metodo; con la
     * guarda de TEC-10 el valor ya no puede ser una sorpresa en produccion,
     * pero en local sigue cayendo al disco publico a proposito.
     */
    private function disco(): string
    {
        return config('filesystems.default') === 'r2' ? 'r2' : 'public';
    }

    /**
     * TEC-10: escribir comprobando el resultado.
     *
     * El disco `r2` esta configurado con `'throw' => false` y `'report' => false`,
     * asi que un `put()` que falla -credenciales mal, bucket que no existe, corte
     * de red- devuelve `false` sin excepcion y sin dejar rastro en el log. Antes
     * nadie miraba ese valor: se seguia adelante, se pedia la URL y se devolvia,
     * y el producto acababa guardado con una `image_url` que apunta a un fichero
     * que nunca se escribio. El fallo aparecia despues, como una imagen rota en
     * el catalogo, sin nada que lo relacionase con la subida.
     */
    private function guardar(string $disk, string $path, string $contents): void
    {
        if (Storage::disk($disk)->put($path, $contents) === false) {
            throw new \RuntimeException(
                "No se pudo guardar la imagen en el disco '{$disk}' ({$path}). "
                .'Revisa las credenciales y el bucket del almacenamiento.'
            );
        }
    }
}
