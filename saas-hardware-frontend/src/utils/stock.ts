import type { Product, ProductVariant } from '../types';

/**
 * Control de stock opcional (MOD-22): lo que comparten catálogo, ficha, carrito,
 * armador y panel.
 *
 * Un producto puede no llevar la cuenta de sus unidades —un servicio, un
 * encargo, algo digital—. Entonces se pide siempre, no se enseña el número, no
 * hay "Avísame cuando llegue" y atender un pedido no descuenta nada. Lo decide
 * la ficha y vale para todas sus variantes.
 *
 * Nada de esto se calcula a mano en una pantalla (`product.stock > 0`): con la
 * cuenta apagada el número que quedó guardado no dice nada, y un 0 enseñaría
 * "Agotado" en algo que se puede pedir.
 */

/**
 * Si la tienda lleva la cuenta de las unidades de este producto. Ausente cuenta
 * como sí, que es lo que era todo antes de esto (y lo que dice el backend).
 */
export const controlaStock = (producto: Pick<Product, 'track_stock'>): boolean =>
  producto.track_stock !== false;

/** Si lo elegido se puede pedir: sin control, siempre; con él, si quedan unidades. */
export const hayParaPedir = (
  producto: Pick<Product, 'track_stock' | 'stock'>,
  variante?: Pick<ProductVariant, 'stock'> | null,
): boolean => !controlaStock(producto) || (variante ?? producto).stock > 0;

/** Si se enseña "Pocas unidades": solo con control de stock y entre 1 y 4. */
export const quedanPocas = (producto: Pick<Product, 'track_stock' | 'stock'>): boolean =>
  controlaStock(producto) && producto.stock > 0 && producto.stock < 5;
