<?php

namespace App\Casts;

use App\Support\ImagenesDelDisco;
use App\Support\MetodosDePago;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `tenants.payment_methods`: la lista de métodos de pago de la tienda (MOD-23).
 *
 * Al leer y al guardar pasa por `MetodosDePago::normalizar()`, así que siempre
 * es una lista con la misma forma —también lo guardado con los cuatro métodos
 * fijos de antes—. La imagen del QR de cada uno se guarda como ruta del disco y
 * se lee como URL completa, igual que `ImagenDelDisco` (`TEC-15`).
 */
class MetodosDePagoDeTienda implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return $this->conImagenes(MetodosDePago::normalizar(json_decode($value, true)), ImagenesDelDisco::url(...));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($this->conImagenes(MetodosDePago::normalizar($value), ImagenesDelDisco::ruta(...)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lista
     * @return array<int, array<string, mixed>>
     */
    private function conImagenes(array $lista, callable $como): array
    {
        return array_map(function (array $metodo) use ($como) {
            $metodo['qr_url'] = $metodo['qr_url'] !== null ? $como($metodo['qr_url']) : null;

            return $metodo;
        }, $lista);
    }
}
