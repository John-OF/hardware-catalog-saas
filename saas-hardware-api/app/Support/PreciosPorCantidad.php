<?php

namespace App\Support;

/**
 * El precio por mayor de un producto o de una variante (`MOD-15`).
 *
 * Vive aparte porque lo piden cuatro sitios —el checkout público, la venta de
 * mostrador, la ficha que enseña la tabla y el importador CSV— y porque la regla
 * de qué tramo manda, escrita cuatro veces, acabaría dando cuatro precios.
 *
 * **Un tramo es un precio, no un descuento.** Comprando 10 de algo cuyo tramo
 * dice "10 o más: 90", las diez unidades valen 90; no valen 100 con un descuento
 * repartido después. De ahí sale lo que hace que esto encaje sin tocar nada más:
 * el precio del tramo entra en `order_items.unit_price` igual que entraba el de
 * oferta, así que `subtotal` sigue siendo precio × cantidad, la utilidad del
 * pedido sigue saliendo de `unit_cost`, los reportes siguen sumando `subtotal` y
 * el cupón (`MOD-4`) sigue repartiéndose en proporción sobre `items_subtotal`.
 * Ninguna de esas cuentas se entera de que existen los tramos.
 *
 * **El orden del precio no se reordena** (`MOD-4`):
 *
 *     productos (cada uno ya a su precio por cantidad) → descuento → + envío → impuesto → total
 *
 * El cupón y el precio por mayor **se acumulan**, y no hace falta decidir cuál
 * gana: el tramo fija a cuánto sale el producto y el cupón descuenta de lo que
 * el producto cueste. Es la misma relación que el cupón tiene ya con
 * `sale_price`, que nadie consideró un conflicto.
 *
 * **Todas las unidades de la línea van al mismo precio** (tramo plano), no las
 * primeras a un precio y el resto a otro (tramo marginal). Es lo que se anuncia
 * en este mercado —"a partir de 10 unidades, S/ 90 cada una"— y es lo que deja
 * que la línea tenga un solo `unit_price`. Un tramo marginal necesitaría dos
 * precios en la misma línea, y eso rompe el snapshot del que cuelga el margen.
 */
class PreciosPorCantidad
{
    /**
     * Cuántos tramos puede tener un producto o una variante.
     *
     * No es un tope de plan —vender al por mayor no es una función que se venda
     * aparte— sino un techo técnico, como `ProductVariant::MAXIMO_POR_PRODUCTO`:
     * cinco escalones son más de los que nadie negocia de verdad, y sin ningún
     * tope la columna queda abierta a que un script la llene.
     */
    public const MAXIMO_DE_TRAMOS = 5;

    /**
     * La cantidad mínima más baja que admite un tramo.
     *
     * Un tramo "desde 1 unidad" no es un precio por mayor: es el precio, y para
     * eso está `sale_price`. Aceptarlo dejaría dos sitios distintos diciendo lo
     * mismo, y el día que no coincidieran nadie sabría cuál manda.
     */
    public const MINIMO_DE_UN_TRAMO = 2;

    /**
     * Lo que cuesta CADA unidad al llevarse `$cantidad`.
     *
     * **Nunca más que el precio normal.** Si el dueño pone una oferta por debajo
     * de su propio precio por mayor, quien compra diez no puede acabar pagando
     * más que quien compra una: sería el mundo al revés, y el comprador lo vería
     * antes que el dueño. El `min()` es la red que lo impide pase lo que pase con
     * la configuración; la validación del formulario solo evita llegar ahí.
     *
     * @param  float  $precioNormal  el que ya se cobraba: el de oferta si lo hay
     * @param  array<int, array{min: int|string, price: float|int|string}>|null  $tramos
     */
    public static function precioPara(float $precioNormal, ?array $tramos, int $cantidad): float
    {
        $tramo = self::tramoPara($tramos, $cantidad);

        if ($tramo === null) {
            return round($precioNormal, 2);
        }

        return round(min($precioNormal, (float) $tramo['price']), 2);
    }

    /**
     * El tramo que le toca a una cantidad, o `null` si no llega a ninguno.
     *
     * Manda el de `min` más alto de entre los que la cantidad alcanza, que con la
     * lista normalizada (ascendente por `min`, descendente por precio) es también
     * el más barato. Devuelve el tramo entero y no solo el precio porque la ficha
     * y el carrito necesitan además desde cuántas unidades se aplica, para
     * poder decirlo.
     *
     * @param  array<int, array{min: int|string, price: float|int|string}>|null  $tramos
     * @return array{min: int, price: float}|null
     */
    public static function tramoPara(?array $tramos, int $cantidad): ?array
    {
        $elegido = null;

        foreach (self::normalizar($tramos) as $tramo) {
            if ($cantidad >= $tramo['min']) {
                $elegido = $tramo;
            }
        }

        return $elegido;
    }

    /**
     * Deja una lista de tramos como se guarda y se lee: limpia, ordenada y sin
     * repetidos.
     *
     * Se llama en los dos extremos —al guardar y al leer— a propósito. Al guardar
     * porque la columna es JSON y nadie más la puede obligar a tener forma; al
     * leer porque una fila escrita antes de una regla nueva, o a mano en la base,
     * no puede hacer que el catálogo cobre cualquier cosa.
     *
     * Se tira lo que no se entiende en vez de fallar: un tramo mal formado en el
     * JSON de un producto no puede tumbar el catálogo entero. Lo que sí se
     * rechaza, y con mensaje, es lo que llega del formulario — eso lo hace
     * `App\Http\Requests\Concerns\ValidaTramosDePrecio`.
     *
     * Con dos tramos para la misma cantidad gana el más barato: es el único
     * desempate que no le cobra de más al comprador.
     *
     * @param  array<int, mixed>|null  $tramos
     * @return array<int, array{min: int, price: float}>
     */
    public static function normalizar(?array $tramos): array
    {
        if (empty($tramos)) {
            return [];
        }

        $limpios = [];

        foreach ($tramos as $tramo) {
            if (! is_array($tramo) || ! isset($tramo['min'], $tramo['price'])) {
                continue;
            }

            if (! is_numeric($tramo['min']) || ! is_numeric($tramo['price'])) {
                continue;
            }

            $min = (int) $tramo['min'];
            $precio = round((float) $tramo['price'], 2);

            if ($min < self::MINIMO_DE_UN_TRAMO || $precio < 0) {
                continue;
            }

            // El más barato gana el empate; ver arriba.
            if (! isset($limpios[$min]) || $precio < $limpios[$min]) {
                $limpios[$min] = $precio;
            }
        }

        ksort($limpios);

        $salida = [];

        foreach (array_slice($limpios, 0, self::MAXIMO_DE_TRAMOS, true) as $min => $precio) {
            $salida[] = ['min' => $min, 'price' => $precio];
        }

        return $salida;
    }

    /**
     * Lo mismo, pero devolviendo `null` cuando no queda nada.
     *
     * La columna distingue "sin tramos" (`null`) de "una lista vacía" (`[]`), y
     * guardar `[]` dejaría en la base una forma más que después hay que recordar
     * comprobar en todos los sitios que leen.
     *
     * @param  array<int, mixed>|null  $tramos
     * @return array<int, array{min: int, price: float}>|null
     */
    public static function paraGuardar(?array $tramos): ?array
    {
        $normalizados = self::normalizar($tramos);

        return $normalizados === [] ? null : $normalizados;
    }

    /**
     * Los tramos en una celda de CSV: `10:90|25:85` (`MOD-7`, `MOD-12`).
     *
     * Texto compacto y no JSON porque esta celda la edita una persona en Excel, y
     * porque unas comillas dentro de un CSV son justo lo que rompe los archivos
     * que la gente arma a mano. `|` separa tramos por lo mismo que en la columna
     * de variantes: el `;` ya separa columnas en media Europa y en los CSV que
     * exporta este panel.
     *
     * @param  array<int, mixed>|null  $tramos
     */
    public static function aTexto(?array $tramos): string
    {
        return collect(self::normalizar($tramos))
            ->map(fn (array $t) => $t['min'].':'.rtrim(rtrim(number_format($t['price'], 2, '.', ''), '0'), '.'))
            ->implode('|');
    }

    /**
     * Y al revés: lo que trae la celda del CSV.
     *
     * Tolerante a propósito —espacios, separador `;`, tramos sueltos ilegibles—
     * porque lo que llega es lo que alguien escribió en una hoja de cálculo. Lo
     * que no se entiende se tira en `normalizar()`; una celda entera ilegible deja
     * el producto sin tramos, que es lo mismo que no poner la columna.
     *
     * @return array<int, array{min: int, price: float}>|null
     */
    public static function desdeTexto(?string $texto): ?array
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return null;
        }

        $tramos = [];

        foreach (preg_split('/[|;]/', $texto) as $trozo) {
            $partes = explode(':', trim($trozo), 2);

            if (count($partes) !== 2) {
                continue;
            }

            $tramos[] = ['min' => trim($partes[0]), 'price' => trim($partes[1])];
        }

        return self::paraGuardar($tramos);
    }
}
