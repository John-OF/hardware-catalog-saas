<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * La regla `exists` acotada a la tienda resuelta (ACC-5).
 *
 * `exists:categories,id` consulta la tabla a pelo y NO pasa por el scope de
 * `BelongsToTenant`, asi que un id de otra tienda la superaba: un producto de A
 * se guardaba con una categoria de B -sus ids son publicos en el catalogo-, y en
 * lote o al reordenar un 422 frente a un 200 decia si un id existia en otra
 * tienda. Toda validacion de un id de una tabla con `tenant_id` va por aqui,
 * nunca con `exists:` a secas.
 *
 * Falla en cerrado, como el scope (AUD-4): sin tienda resuelta, ningun id
 * existe.
 */
class DeLaTienda
{
    public static function existe(string $tabla, string $columna = 'id'): Exists
    {
        return Rule::exists($tabla, $columna)->where(function ($consulta) {
            if (! app()->bound('currentTenant')) {
                $consulta->whereRaw('1 = 0');

                return;
            }

            $consulta->where('tenant_id', app('currentTenant')->id);
        });
    }
}
