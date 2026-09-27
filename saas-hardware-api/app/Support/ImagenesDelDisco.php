<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Qué se guarda en la base de una imagen, y cómo se vuelve a leer (`TEC-15`).
 *
 * Una imagen subida se guarda como su **ruta dentro del disco**
 * (`products/mi-tienda/<uuid>.webp`), no como la URL que da `Storage::url()`.
 * Con la URL, el dominio del almacén quedaba escrito en cada fila de productos,
 * galería, variantes y tienda: cambiar de bucket, de CDN o de `public` a `r2`
 * rompía todas las imágenes a la vez, sin una ruta de la que reconstruirlas.
 * Con la ruta, la URL se arma al leer con el disco de ese momento.
 *
 * **Lo que no es de este disco se guarda tal cual**: un logo pegado de otra web
 * y las URL absolutas de antes de esto, que se siguen leyendo igual aunque no se
 * haya migrado nada. Por eso una ruta del disco se reconoce por lo que no es:
 * ni lleva esquema (`https:`, `data:`) ni empieza por `/`.
 */
class ImagenesDelDisco
{
    /**
     * Disco donde viven las imágenes. Con la guarda de TEC-10 el valor ya no
     * puede ser una sorpresa en producción; en local cae al disco público a
     * propósito.
     */
    public static function disco(): string
    {
        return config('filesystems.default') === 'r2' ? 'r2' : 'public';
    }

    /** Lo guardado, como URL: una ruta del disco se arma; lo demás sale igual. */
    public static function url(?string $guardado): ?string
    {
        if (! self::esRutaDelDisco($guardado)) {
            return $guardado;
        }

        return Storage::disk(self::disco())->url($guardado);
    }

    /**
     * Una URL, como se guarda: la de este disco, como su ruta; cualquier otra,
     * igual. Es la inversa exacta de `url()` —la URL base del disco más la ruta—
     * y no "lo que venga detrás de cualquier dominio": una URL de otro sitio no
     * se convierte en una ruta nuestra.
     */
    public static function ruta(?string $url): ?string
    {
        if (blank($url)) {
            return $url;
        }

        $base = self::base();

        if ($base === null || ! str_starts_with($url, $base)) {
            return $url;
        }

        $ruta = substr($url, strlen($base));

        return $ruta === '' || str_contains($ruta, '..') ? $url : $ruta;
    }

    /** Si lo guardado es una ruta de este disco (y no una URL). */
    public static function esRutaDelDisco(?string $guardado): bool
    {
        return ! blank($guardado)
            && ! str_starts_with($guardado, '/')
            && ! preg_match('#^[a-z][a-z0-9+.-]*:#i', $guardado);
    }

    /**
     * La URL base del disco (`https://cdn.x/`, o `/storage/` en un disco sin
     * dominio), o null si no da ninguna.
     */
    private static function base(): ?string
    {
        $base = Storage::disk(self::disco())->url('');

        return $base === '' ? null : $base;
    }
}
