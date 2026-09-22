<?php

namespace App\Http\Requests\Concerns;

use App\Support\PreciosPorCantidad;
use Illuminate\Validation\Validator;

/**
 * Los tramos de precio por cantidad que llegan en el formulario de producto
 * (`MOD-15`).
 *
 * Compartido por alta y edicion, y aplicado dos veces: a la ficha
 * (`price_tiers`) y a cada variante (`variants.*.price_tiers`), porque con
 * variantes el precio vive en la variante (`MOD-5`) y el precio por mayor con el.
 *
 * `App\Support\PreciosPorCantidad::normalizar()` ya tira lo que no entiende al
 * guardar, asi que esto no existe para proteger la base: existe para que el dueño
 * se entere. Un tramo que se descarta en silencio es peor que uno rechazado — el
 * dueño cree que puso un precio por mayor, la tienda cobra el de siempre, y lo
 * descubre el comprador.
 */
trait ValidaTramosDePrecio
{
    /**
     * El formulario de producto es multipart (lleva fotos), asi que los tramos de
     * la ficha llegan como un string JSON, igual que `specs` y `variants`. Los de
     * cada variante viajan ya dentro del JSON de `variants` y no necesitan esto.
     *
     * `price_tiers` ausente significa "no los toques" y `[]` significa "quitalos
     * todos", la misma convencion que `variants`.
     */
    protected function decodificarTramos(): void
    {
        if (is_string($this->price_tiers)) {
            $this->merge([
                'price_tiers' => json_decode($this->price_tiers, true) ?? [],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function reglasDeTramos(string $prefijo = ''): array
    {
        $campo = $prefijo === '' ? 'price_tiers' : $prefijo.'.price_tiers';

        return [
            $campo => 'nullable|array|max:'.PreciosPorCantidad::MAXIMO_DE_TRAMOS,
            $campo.'.*.min' => 'required|integer|min:'.PreciosPorCantidad::MINIMO_DE_UN_TRAMO.'|max:100000',
            $campo.'.*.price' => 'required|numeric|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesDeTramos(): array
    {
        return [
            'price_tiers.max' => 'Un producto admite como mucho '.PreciosPorCantidad::MAXIMO_DE_TRAMOS.' tramos de precio por cantidad.',
            'price_tiers.*.min.required' => 'Cada tramo necesita desde cuántas unidades se aplica.',
            'price_tiers.*.min.min' => 'Un tramo empieza como pronto en '.PreciosPorCantidad::MINIMO_DE_UN_TRAMO.' unidades: para una sola está el precio de oferta.',
            'price_tiers.*.price.required' => 'Cada tramo necesita su precio por unidad.',
            'variants.*.price_tiers.max' => 'Cada variante admite como mucho '.PreciosPorCantidad::MAXIMO_DE_TRAMOS.' tramos de precio por cantidad.',
            'variants.*.price_tiers.*.min.required' => 'Cada tramo de una variante necesita desde cuántas unidades se aplica.',
            'variants.*.price_tiers.*.min.min' => 'Un tramo empieza como pronto en '.PreciosPorCantidad::MINIMO_DE_UN_TRAMO.' unidades.',
            'variants.*.price_tiers.*.price.required' => 'Cada tramo de una variante necesita su precio por unidad.',
        ];
    }

    /**
     * Las tres reglas que no caben en una regla de campo, sobre la ficha y sobre
     * cada variante.
     */
    protected function comprobarTramos(Validator $validator): void
    {
        $this->comprobarUnaListaDeTramos(
            $validator,
            'price_tiers',
            $this->input('price_tiers'),
            $this->input('price'),
        );

        $variantes = $this->input('variants');

        if (! is_array($variantes)) {
            return;
        }

        foreach ($variantes as $posicion => $variante) {
            $this->comprobarUnaListaDeTramos(
                $validator,
                "variants.{$posicion}.price_tiers",
                $variante['price_tiers'] ?? null,
                $variante['price'] ?? null,
            );
        }
    }

    /**
     * - **Sin cantidades repetidas**: dos tramos "desde 10" son dos precios para
     *   la misma compra, y el que se guardara sería el azar del orden.
     * - **Más unidades, menos precio**: un tramo que sube el precio al comprar más
     *   no se llega a cobrar nunca —`precioPara()` nunca pasa del precio normal—,
     *   así que dejarlo guardar es dejar al dueño creyendo que hizo algo.
     * - **Por debajo del precio normal**: por lo mismo. Se compara contra `price`
     *   y no contra `sale_price` a propósito: la oferta se quita y se pone, y no
     *   tendría sentido que quitar una oferta de tres días invalidara el precio
     *   por mayor que el dueño negoció con un cliente.
     */
    private function comprobarUnaListaDeTramos(Validator $validator, string $campo, mixed $tramos, mixed $precioBase): void
    {
        if (! is_array($tramos) || $tramos === []) {
            return;
        }

        // Se guarda la posición original junto al valor porque el mensaje de
        // error tiene que señalar la fila que el dueño escribió, y el orden en
        // que la escribió no es el orden en que se comparan.
        $validos = [];

        foreach ($tramos as $posicion => $tramo) {
            if (! is_array($tramo) || ! isset($tramo['min'], $tramo['price'])) {
                continue;
            }

            if (! is_numeric($tramo['min']) || ! is_numeric($tramo['price'])) {
                continue;
            }

            $validos[] = [
                'posicion' => $posicion,
                'min' => (int) $tramo['min'],
                'price' => (float) $tramo['price'],
            ];
        }

        $vistas = [];

        foreach ($validos as $tramo) {
            if (isset($vistas[$tramo['min']])) {
                $validator->errors()->add(
                    "{$campo}.{$tramo['posicion']}.min",
                    "Hay dos tramos que empiezan en {$tramo['min']} unidades.",
                );
            }

            $vistas[$tramo['min']] = true;

            if (is_numeric($precioBase) && $tramo['price'] >= (float) $precioBase) {
                $validator->errors()->add(
                    "{$campo}.{$tramo['posicion']}.price",
                    'El precio de un tramo tiene que ser menor que el precio normal del producto.',
                );
            }
        }

        // "A más unidades, menos precio" solo significa algo sobre la lista
        // ordenada por cantidad: el formulario los manda en el orden en que el
        // dueño los escribió, que puede ser cualquiera.
        usort($validos, fn (array $a, array $b) => $a['min'] <=> $b['min']);

        for ($i = 1; $i < count($validos); $i++) {
            if ($validos[$i]['min'] === $validos[$i - 1]['min']) {
                // Repetido: ya tiene su propio error, y compararlos aquí añadiría
                // un segundo mensaje sobre la misma fila.
                continue;
            }

            if ($validos[$i]['price'] >= $validos[$i - 1]['price']) {
                $validator->errors()->add(
                    "{$campo}.{$validos[$i]['posicion']}.price",
                    'Cada tramo tiene que costar menos que el anterior: a más unidades, menos precio.',
                );
            }
        }
    }
}
