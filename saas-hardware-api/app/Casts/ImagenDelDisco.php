<?php

namespace App\Casts;

use App\Support\ImagenesDelDisco;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Una columna de imagen: en la base, la ruta dentro del disco; en PHP y en la
 * API, la URL completa (`TEC-15`, ver `App\Support\ImagenesDelDisco`).
 *
 * Como cast y no en `ImageService`, igual que `SanitizedHtml`: a estas columnas
 * se escribe desde la subida, desde el duplicado de productos y desde el
 * formulario de la tienda, y todo sigue trabajando con URL completas.
 */
class ImagenDelDisco implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return ImagenesDelDisco::url($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return ImagenesDelDisco::ruta($value);
    }
}
