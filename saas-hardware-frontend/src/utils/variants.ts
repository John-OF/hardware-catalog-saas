import type { Product, ProductVariant } from '../types';

/**
 * Variantes de producto (MOD-5): lo que comparten catálogo, ficha, carrito,
 * armador y panel.
 *
 * Un producto sin variantes se comporta como siempre. Con variantes, el precio y
 * el stock del producto son un resumen (el de la más barata y la suma), así que
 * todo lo que COBRA o DESCUENTA tiene que mirar la variante elegida.
 */

export const tieneVariantes = (product: Pick<Product, 'variants'>): boolean =>
  (product.variants?.length ?? 0) > 0;

/** El precio que se cobra: el de oferta cuando existe. Mismo criterio que el backend. */
export const precioVisible = (item: { price: number | string; sale_price: number | string | null }): number =>
  Number(item.sale_price !== null && item.sale_price !== undefined ? item.sale_price : item.price);

/** Precio, oferta y stock que valen para una línea: los de la variante si la hay. */
export const datosDeVenta = (product: Product, variant?: ProductVariant | null) => {
  const fuente = variant ?? product;

  return {
    price: Number(fuente.price),
    sale_price: fuente.sale_price !== null && fuente.sale_price !== undefined ? Number(fuente.sale_price) : null,
    stock: fuente.stock,
    precio: precioVisible(fuente),
  };
};

/**
 * Si el precio de un producto con variantes es "desde": solo cuando no todas
 * cuestan lo mismo. Con tres colores al mismo precio, "Desde $90" confunde.
 */
export const precioEsDesde = (product: Product): boolean => {
  if (!tieneVariantes(product)) return false;

  const precios = new Set(product.variants!.map((v) => precioVisible(v)));

  return precios.size > 1;
};

/** Clave de una línea del carrito: el mismo producto en 16 GB y en 32 GB son dos líneas. */
export const claveDeLinea = (productId: string, variantId?: string | null): string =>
  variantId ? `${productId}:${variantId}` : productId;

/** "Kingston Fury (16 GB)", para el mensaje de WhatsApp y los resúmenes. */
export const nombreConVariante = (productName: string, variantName?: string | null): string =>
  variantName ? `${productName} (${variantName})` : productName;
