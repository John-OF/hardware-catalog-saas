/**
 * Margen: lo que deja una venta (MOD-6).
 *
 * Vive aparte del panel porque lo usan tres pantallas —el formulario de
 * producto, el listado y el detalle de un pedido— y porque las reglas de qué
 * cuenta y qué no son del negocio, no de la pantalla.
 */

/**
 * Lo que se gana vendiendo una unidad, y qué parte del precio es.
 *
 * `null` cuando no se puede calcular: sin costo, sin precio, o con un precio de
 * cero (dividir por cero daría `Infinity`, y un porcentaje infinito en pantalla
 * es peor que no enseñar nada).
 *
 * **El porcentaje es sobre el PRECIO DE VENTA, no sobre el costo.** Es el
 * margen comercial, el que se compara entre productos y el que usan los
 * proveedores; el otro cálculo —sobre el costo— da números mucho más grandes y
 * haría creer al dueño que gana el doble de lo que gana.
 */
export interface Margen {
  /** Precio menos costo, por unidad. Puede ser negativo: se está vendiendo a pérdida. */
  utilidad: number;
  /** Qué porcentaje del precio es utilidad. */
  porcentaje: number;
}

export const margenDe = (precio: unknown, costo: unknown): Margen | null => {
  const p = aNumero(precio);
  const c = aNumero(costo);

  if (p === null || c === null || p <= 0) {
    return null;
  }

  const utilidad = redondear(p - c);

  return { utilidad, porcentaje: redondear((utilidad / p) * 100) };
};

/**
 * El precio que se compara con el costo: el de oferta manda cuando lo hay,
 * porque es el que se cobra de verdad.
 */
export const precioQueSeCobra = (precio: unknown, oferta: unknown): number | null => {
  const conOferta = aNumero(oferta);

  return conOferta !== null && conOferta > 0 ? conOferta : aNumero(precio);
};

/**
 * Los números llegan del backend como string ('120.00') o como number según de
 * dónde vengan, y del formulario como texto de un input. Todo lo que no sea un
 * número de verdad —vacío, null, undefined, letras— es "no se sabe", no cero.
 */
const aNumero = (valor: unknown): number | null => {
  if (valor === null || valor === undefined || valor === '') {
    return null;
  }

  const numero = typeof valor === 'number' ? valor : Number(valor);

  return Number.isFinite(numero) ? numero : null;
};

const redondear = (n: number): number => Math.round(n * 100) / 100;
