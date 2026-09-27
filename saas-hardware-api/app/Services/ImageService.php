<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\ImagenesDelDisco;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\PngEncoder;
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
        // Como URL completa, que es lo que da el cast al leer; si alguien pasa
        // una ruta, también.
        $urls = array_values(array_unique(array_map(ImagenesDelDisco::url(...), array_filter($urls))));

        if ($urls === []) {
            return;
        }

        // TEC-15: en la base cada foto puede estar como ruta del disco (lo nuevo)
        // o como URL completa (lo de antes de migrar, o si la migración no ha
        // corrido). Se busca por las dos formas y lo encontrado se compara como
        // URL: buscar solo por una daría "nadie la usa" con la foto en uso, y
        // aquí eso es borrarla.
        $buscar = array_values(array_unique([...$urls, ...array_map(ImagenesDelDisco::ruta(...), $urls)]));

        $enUso = collect();

        // Una consulta por tabla, sea cual sea el número de URL: un borrado en
        // lote de cincuenta productos cuesta lo mismo que uno (AUD-23).
        foreach (['products', 'product_images', 'product_variants'] as $tabla) {
            DB::table($tabla)
                ->where(fn ($q) => $q->whereIn('image_url', $buscar)->orWhereIn('thumbnail_url', $buscar))
                ->get(['image_url', 'thumbnail_url'])
                ->each(function ($fila) use (&$enUso) {
                    $enUso->push(ImagenesDelDisco::url($fila->image_url), ImagenesDelDisco::url($fila->thumbnail_url));
                });
        }

        foreach (array_diff($urls, $enUso->unique()->all()) as $url) {
            $this->borrarArchivo($url);
        }
    }

    private function borrarArchivo(string $url): void
    {
        $ruta = ImagenesDelDisco::ruta($url);

        if (! ImagenesDelDisco::esRutaDelDisco($ruta)) {
            // Una URL de este disco con otro dominio (de antes de TEC-15, si el
            // dominio cambió): lo que va detrás de `/storage/` o del dominio.
            $ruta = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            if (str_starts_with($ruta, 'storage/')) {
                $ruta = substr($ruta, 8);
            }
        }

        Storage::disk($this->disco())->delete($ruta);
    }

    /**
     * El logo de la tienda como `data:` URI, para empotrarlo en el PDF de la
     * cotización (FUN-20). `null` si no hay uno que se pueda empotrar.
     *
     * dompdf tiene `enable_remote` apagado, así que un `<img src>` con la URL del
     * logo no se dibujaba nunca: el logo siempre es remoto (R2, o `/storage` en
     * local). Encender `enable_remote` NO es el arreglo: `logo_url` la escribe el
     * dueño, y el servidor iría a descargar lo que él pusiera —la metadata de la
     * nube incluida—. Aquí se lee del disco de imágenes por su propio cliente, sin
     * ninguna petición a una URL.
     *
     * Por eso solo vale un archivo de este disco y de la carpeta de logo de ESTA
     * tienda: un logo pegado desde otra web no sale (no se descarga), y una URL
     * que apunte al logo de otra tienda tampoco —se podría meter en el PDF propio
     * un archivo ajeno—. Se pasa a PNG y al doble del tamaño con que se imprime:
     * el PDF no engorda con la foto de 1200 px, y no se depende de que el GD de
     * dompdf lea WebP, sino del mismo motor que lo escribió al subirlo.
     *
     * Si algo falla, el PDF sale sin logo, como antes, en vez de no salir.
     */
    public function logoEmpotrable(Tenant $tenant): ?string
    {
        // La inversa exacta de la URL del disco (`ImagenesDelDisco::ruta()`), no
        // lo que venga detrás de cualquier dominio: para LEER algo a partir de una
        // URL que escribe el dueño, un logo de otra web no se convierte en una
        // ruta nuestra. Desde TEC-15 el logo subido se guarda como ruta, así que
        // un cambio de dominio del disco ya no lo deja fuera.
        $ruta = ImagenesDelDisco::ruta($tenant->logo_url);

        if (! ImagenesDelDisco::esRutaDelDisco($ruta) || ! str_starts_with($ruta, "products/{$tenant->slug}/logo/")) {
            return null;
        }

        try {
            $contenido = Storage::disk($this->disco())->get($ruta);

            if ($contenido === null) {
                return null;
            }

            $png = Image::decodeBinary($contenido)
                ->scaleDown(width: 360, height: 112)
                ->encode(new PngEncoder());

            return 'data:image/png;base64,'.base64_encode($png->toString());
        } catch (\Throwable $e) {
            Log::warning('No se pudo empotrar el logo en la cotización', [
                'tenant_id' => $tenant->id,
                'ruta' => $ruta,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Disco donde viven las imagenes. Vive en `ImagenesDelDisco` desde TEC-15,
     * porque lo necesita también el cast que arma la URL al leer.
     */
    private function disco(): string
    {
        return ImagenesDelDisco::disco();
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
