<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Quien ve el costo de compra y la utilidad (MOD-6).
 *
 * `Product`, `ProductVariant` y `OrderItem` esconden el costo en su `$hidden`:
 * no sale en ninguna respuesta mientras nadie lo pida. Este ayudante es el
 * unico sitio que lo pide, y solo para quien tiene el permiso `costos.ver`: un
 * admin, o un rol del equipo que lo tenga marcado (MOD-21).
 *
 * Por que un permiso propio y no parte de "ver productos": el costo de compra
 * es lo que la tienda le paga al proveedor, el dato con el que se negocia. El
 * vendedor que atiende el mostrador no lo necesita para vender, y por eso el
 * rol de fabrica «Colaborador» no lo trae.
 *
 * Se llama en el ULTIMO momento, sobre lo que se va a serializar, y no antes de
 * guardar nada: `makeVisible`/`append` solo cambian como se convierte a JSON.
 */
class Costos
{
    public static function usuarioPuedeVerlos(): bool
    {
        return Permisos::usuarioPuede('costos.ver');
    }

    /**
     * Ensena el costo en lo que se le pasa -un modelo, una coleccion o un
     * paginador- y lo devuelve tal cual para poder encadenarlo en el `return`.
     *
     * Baja tambien a las relaciones cargadas, que es donde esta el costo de
     * verdad: las variantes de un producto (MOD-5) y las lineas de un pedido.
     *
     * @template T
     *
     * @param  T  $datos
     * @return T
     */
    public static function mostrar(mixed $datos): mixed
    {
        if (! self::usuarioPuedeVerlos()) {
            return $datos;
        }

        foreach (self::modelos($datos) as $modelo) {
            if ($modelo instanceof Product) {
                $modelo->makeVisible('cost');

                if ($modelo->relationLoaded('variants')) {
                    $modelo->variants->each->makeVisible('cost');
                }

                continue;
            }

            if ($modelo instanceof ProductVariant) {
                $modelo->makeVisible('cost');

                continue;
            }

            if ($modelo instanceof Order) {
                if ($modelo->relationLoaded('items')) {
                    $modelo->items->each->makeVisible('unit_cost');
                }

                // Los tres salen calculados de las lineas; ver `Order`.
                $modelo->append(['costo_total', 'utilidad', 'lineas_sin_costo']);
            }
        }

        return $datos;
    }

    /**
     * Los modelos que hay dentro de lo que sea que nos han pasado.
     *
     * @return iterable<Model>
     */
    private static function modelos(mixed $datos): iterable
    {
        if ($datos instanceof Model) {
            return [$datos];
        }

        // Un paginador NO es una coleccion: los modelos estan en `items()`.
        if ($datos instanceof Paginator) {
            return $datos->items();
        }

        if ($datos instanceof Collection || is_array($datos)) {
            return collect($datos)->filter(fn ($x) => $x instanceof Model);
        }

        return [];
    }
}
