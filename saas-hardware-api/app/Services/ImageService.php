<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
     * Borra del almacenamiento las fotos de producto que ya no use nadie (TEC-14).
     *
     * **Hay que llamarlo DESPUÉS de haber cambiado la base**, con las URL que
     * acaban de quedar sueltas: la foto principal que se reemplazó, la galería o
     * las variantes de un producto borrado, la foto de una variante quitada.
     *
     * Antes cada sitio borraba el archivo en cuanto su fila dejaba de apuntarle,
     * sin mirar si otra fila apuntaba al mismo. Y eso pasa siempre tras duplicar
     * un producto: la copia comparte las URL del original —foto principal,
     * galería y variantes—, no los archivos. Borrar uno de los dos, o cambiarle la
     * foto, dejaba al otro con las imágenes rotas. Aquí se mira primero: si alguna
     * fila de productos, galería o variantes sigue usando la URL, el archivo se
     * queda.
     *
     * Se prefirió esto a copiar los archivos al duplicar por dos motivos: arregla
     * también los duplicados que ya existían, y no multiplica el espacio usado en
     * el almacenamiento.
     *
     * Se busca en todas las tiendas y no solo en la actual: la ruta lleva el slug
     * de la tienda, así que una URL no puede estar en otra, y no depender de tener
     * tienda resuelta evita el fallo en cerrado de AUD-4 —que aquí sería borrar un
     * archivo en uso por creer que nadie lo usa—.
     *
     * @param  array<int, string|null>  $urls
     */
    /**
     * Todas las URL de fotos de unos productos: principal, galería y variantes.
     * Para pasárselas después a `borrarSiNadieLasUsa()` (TEC-14).
     *
     * Vive aquí y no en `ProductController` porque desde `MOD-8` quien borra las
     * fotos de un producto ya no es el controlador —que ahora solo lo manda a la
     * papelera— sino `TrashController`, al vaciarla, y el comando que la purga.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Product>  $productos  con `images` y `variants` cargadas
     * @return array<int, string|null>
     */
    public function fotosDe(\Illuminate\Support\Collection $productos): array
    {
        return $productos->flatMap(fn ($p) => [
            $p->image_url,
            $p->thumbnail_url,
            ...$p->images->flatMap(fn ($img) => [$img->image_url, $img->thumbnail_url]),
            ...$p->variants->flatMap(fn ($v) => [$v->image_url, $v->thumbnail_url]),
        ])->all();
    }

    public function borrarSiNadieLasUsa(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));

        if ($urls === []) {
            return;
        }

        $enUso = collect();

        // Una consulta por tabla, sea cual sea el número de URL: un borrado en
        // lote de cincuenta productos cuesta lo mismo que uno (AUD-23).
        foreach (['products', 'product_images', 'product_variants'] as $tabla) {
            DB::table($tabla)
                ->where(fn ($q) => $q->whereIn('image_url', $urls)->orWhereIn('thumbnail_url', $urls))
                ->get(['image_url', 'thumbnail_url'])
                ->each(function ($fila) use (&$enUso) {
                    $enUso->push($fila->image_url, $fila->thumbnail_url);
                });
        }

        foreach (array_diff($urls, $enUso->unique()->all()) as $url) {
            $this->borrarArchivo($url);
        }
    }

    private function borrarArchivo(string $url): void
    {
        $path = parse_url($url, PHP_URL_PATH);
        // Si la URL es local, quitar /storage/ para obtener el path correcto
        $relativePath = ltrim((string) $path, '/');
        if (str_starts_with($relativePath, 'storage/')) {
            $relativePath = substr($relativePath, 8);
        }

        Storage::disk($this->disco())->delete($relativePath);
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
