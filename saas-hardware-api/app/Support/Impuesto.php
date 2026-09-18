<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Tenant;

/**
 * El impuesto de una venta (`MOD-2`).
 *
 * Vive aparte porque lo piden tres sitios —el checkout público, la venta de
 * mostrador y el PDF de la cotización— y porque el cálculo tiene dos formas
 * según cómo cobre la tienda. Escrito tres veces, acabaría dando tres totales.
 *
 * **El invariante que lo hace manejable:** `total` es siempre lo que paga el
 * cliente y `tax_amount` es cuánto de ese total es impuesto. Cambie lo que
 * cambie la configuración, quien pinta un pedido resta y ya está, sin preguntar
 * en qué modo se vendió.
 *
 * - **Incluido** (lo normal en el retail de la región): el precio del catálogo
 *   es el que se paga. El impuesto se saca hacia atrás y `total` no se toca —por
 *   eso encender el impuesto no sube el catálogo de precio de golpe—.
 * - **Sumado**: los precios son netos y el impuesto se añade al final. Aquí el
 *   comprador sí ve un número en el catálogo y paga otro, y por eso el checkout
 *   enseña el desglose antes de confirmar.
 *
 * **La base incluye el envío.** Es lo que se cobra, y separarlo obligaría a
 * explicar en la cotización por qué una línea lleva impuesto y la otra no.
 */
class Impuesto
{
    /**
     * Qué impuesto le toca a una venta de esta tienda.
     *
     * @param  float  $base  lo cobrado antes de decidir el impuesto: líneas + envío
     * @return array{tax_name: string|null, tax_rate: float|null, tax_included: bool|null, tax_amount: float|null, total: float}
     *         `total` es lo que se guarda en el pedido; los `tax_*`, su foto.
     */
    public static function paraVenta(Tenant $tenant, float $base): array
    {
        $sinImpuesto = [
            'tax_name'     => null,
            'tax_rate'     => null,
            'tax_included' => null,
            'tax_amount'   => null,
            'total'        => round($base, 2),
        ];

        // Una tasa de 0 con el impuesto encendido es lo mismo que no cobrarlo, y
        // guardar `0.00` haría que el PDF imprimiera una línea "IGV 0,00" que no
        // dice nada.
        if (! $tenant->tax_enabled || (float) $tenant->tax_rate <= 0) {
            return $sinImpuesto;
        }

        $tasa = (float) $tenant->tax_rate;
        $incluido = (bool) $tenant->tax_included;

        $impuesto = $incluido
            // Hacia atrás: de un total que ya lo lleva dentro. No es `base * tasa
            // / 100`, que es el error clásico y cobra de más.
            ? round($base * $tasa / (100 + $tasa), 2)
            : round($base * $tasa / 100, 2);

        return [
            'tax_name'     => (string) $tenant->tax_name,
            'tax_rate'     => $tasa,
            'tax_included' => $incluido,
            'tax_amount'   => $impuesto,
            'total'        => $incluido ? round($base, 2) : round($base + $impuesto, 2),
        ];
    }

    /**
     * Lo que de un importe ya cobrado es impuesto, según cómo se vendió ESE pedido.
     *
     * Lo usan la utilidad y los reportes: con los precios llevando el impuesto
     * dentro, lo que cobró la tienda por el producto no es el precio de venta,
     * porque parte de ese dinero es del fisco. Medir el margen contra el precio
     * con impuesto lo infla un 18% y ese número se toma para decidir precios.
     *
     * Devuelve 0 cuando el pedido se vendió sin impuesto o cuando se sumó al
     * final: ahí las líneas ya son netas y no hay nada que descontar.
     */
    public static function dentroDe(Order $pedido, float $importe): float
    {
        if ($pedido->tax_rate === null || ! $pedido->tax_included) {
            return 0.0;
        }

        $tasa = (float) $pedido->tax_rate;

        return $tasa > 0 ? round($importe * $tasa / (100 + $tasa), 2) : 0.0;
    }

    /**
     * La expresión SQL que descuenta el impuesto de las líneas de un pedido.
     *
     * Existe para que los reportes (`App\Support\Reportes`) midan el margen
     * contra lo mismo que `Order::getUtilidadAttribute()` mide un pedido suelto:
     * si una pantalla descuenta el impuesto y la otra no, el dueño ve dos
     * márgenes distintos de las mismas ventas.
     *
     * Escrita con aritmética a secas —sin ninguna función de fecha ni de
     * formato— para que valga igual en MySQL y en SQLite, que es donde corre la
     * suite. `orders.tax_included` guarda 1/0 en los dos motores.
     *
     * **El `* 1.0` no es decorativo**: SQLite divide enteros como enteros, así
     * que una tasa sin división exacta se truncaba **en la suite**, mientras que
     * en MySQL el resultado era correcto. Producción no llegó a estar mal; lo
     * que estaba mal era el test, que con cifras redondas acertaba por
     * casualidad. Se descubrió al añadir la expresión equivalente de `MOD-4`,
     * donde 200/1000 daba directamente cero y el fallo sí se veía.
     *
     * @param  string  $columna  la que hay que dejar neta, ya cualificada
     */
    public static function expresionNeta(string $columna): string
    {
        return "(case
            when orders.tax_rate is null or orders.tax_included = 0 then {$columna}
            else {$columna} - ({$columna} * orders.tax_rate * 1.0 / (100 + orders.tax_rate))
        end)";
    }
}
