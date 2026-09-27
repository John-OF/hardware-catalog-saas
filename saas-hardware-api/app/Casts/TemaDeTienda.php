<?php

namespace App\Casts;

use App\Support\ImagenesDelDisco;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `tenants.theme`: el JSON de apariencia de la tienda, con la portada y el
 * favicon dentro (`banner_url`, `favicon_url`). Es el cast `array` de siempre,
 * y además esas dos claves se guardan como ruta del disco y se leen como URL
 * completa, igual que `ImagenDelDisco` (`TEC-15`).
 */
class TemaDeTienda implements CastsAttributes
{
    private const IMAGENES = ['banner_url', 'favicon_url'];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $tema = json_decode($value, true);

        return is_array($tema) ? $this->convertir($tema, ImagenesDelDisco::url(...)) : $tema;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(is_array($value) ? $this->convertir($value, ImagenesDelDisco::ruta(...)) : $value);
    }

    private function convertir(array $tema, callable $como): array
    {
        foreach (self::IMAGENES as $clave) {
            if (isset($tema[$clave]) && is_string($tema[$clave])) {
                $tema[$clave] = $como($tema[$clave]);
            }
        }

        return $tema;
    }
}
