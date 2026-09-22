import type { PriceTier, Product, ProductVariant } from '../types';

/**
 * Precio por mayor (MOD-15): el espejo de `App\Support\PreciosPorCantidad`.
 *
 * **Quien manda es el servidor**, aquí como con el envío (MOD-1) y el cupón
 * (MOD-4): del navegador solo viaja la cantidad. Esto existe para que el
 * comprador vea el mismo número ANTES de confirmar, y las dos reglas que hay que
 * mantener iguales a las de allá son estas dos:
 *
 * 1. Manda el tramo de `min` más alto que la cantidad alcanza.
 * 2. **Nunca se cobra más que el precio normal.** Si la oferta está por debajo
 *    del precio por mayor, gana la oferta: quien compra diez no puede pagar más
 *    por unidad que quien compra una.
 */

/** El tramo que le toca a una cantidad, o `null` si no llega a ninguno. */
export const tramoPara = (tramos: PriceTier[] | null | undefined, cantidad: number): PriceTier | null => {
  if (!tramos?.length) return null;

  return tramos.reduce<PriceTier | null>(
    (elegido, tramo) => (cantidad >= tramo.min && (!elegido || tramo.min > elegido.min) ? tramo : elegido),
    null,
  );
};

/** Lo que cuesta cada unidad al llevarse `cantidad`. */
export const precioPorCantidad = (
  precioNormal: number,
  tramos: PriceTier[] | null | undefined,
  cantidad: number,
): number => {
  const tramo = tramoPara(tramos, cantidad);

  return tramo ? Math.min(precioNormal, Number(tramo.price)) : precioNormal;
};

/**
 * Los tramos que de verdad se cobran en una línea: los de la variante elegida si
 * la hay (MOD-5), porque con variantes los de la ficha son solo un resumen.
 */
export const tramosDeVenta = (product: Product, variant?: ProductVariant | null): PriceTier[] | null =>
  (variant ?? product).price_tiers ?? null;

/**
 * Desde cuántas unidades empieza a haber precio por mayor, para anunciarlo en la
 * ficha y en la tarjeta sin tener que pintar la tabla entera.
 */
export const desdeCuantasUnidades = (tramos: PriceTier[] | null | undefined): number | null =>
  tramos?.length ? Math.min(...tramos.map((t) => t.min)) : null;
