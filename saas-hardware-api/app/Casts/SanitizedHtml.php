<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Mews\Purifier\Facades\Purifier;

/**
 * Sanitiza HTML enviado por el admin antes de guardarlo (SEC-3).
 *
 * Va como cast del modelo y no como regla del FormRequest a proposito: la
 * descripcion tambien se escribe desde el import CSV (ProductController::import)
 * y desde el duplicado de productos, que no pasan por StoreProductRequest.
 * Puesto aqui, cualquier via de escritura queda cubierta.
 *
 * La limpieza ocurre al escribir, no al leer, para que lo que quede en la base
 * ya sea seguro: asi el payload tampoco llega a un consumidor futuro de la API
 * que no sanitice por su cuenta.
 */
class SanitizedHtml implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return $value;
        }

        return Purifier::clean((string) $value, 'store_content');
    }
}
