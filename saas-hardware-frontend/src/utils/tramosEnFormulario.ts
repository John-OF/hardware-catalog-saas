import type { PriceTier } from '../types';

/**
 * Los tramos de precio por mayor mientras se editan en el formulario del panel
 * (MOD-15). Va aparte de `<EditorDeTramos>` para que ese archivo solo exporte el
 * componente (la recarga en caliente de Vite lo necesita), igual que
 * `variantesEnFormulario.ts`.
 */

/** Lo mismo que el backend (`PreciosPorCantidad::MAXIMO_DE_TRAMOS`). */
export const MAXIMO_DE_TRAMOS = 5;

/** Lo mismo que el backend (`PreciosPorCantidad::MINIMO_DE_UN_TRAMO`). */
export const MINIMO_DE_UN_TRAMO = 2;

/** Un tramo mientras se edita. Los números van como texto, igual que el resto del formulario. */
export interface TramoEnFormulario {
  /** Clave local para React: las filas nuevas no tienen nada que las distinga. */
  clave: string;
  min: string;
  price: string;
}

const nuevaClave = () => `tramo-${Math.random().toString(36).slice(2, 10)}`;

export const tramoVacio = (): TramoEnFormulario => ({ clave: nuevaClave(), min: '', price: '' });

/** De lo guardado a lo que edita el formulario. */
export const tramosDesdeProducto = (tramos: PriceTier[] | null | undefined): TramoEnFormulario[] =>
  (tramos ?? []).map((t) => ({ clave: nuevaClave(), min: String(t.min), price: String(t.price) }));

/** Y al revés, listo para el JSON que viaja en el FormData. Las filas a medias no se mandan. */
export const tramosParaGuardar = (filas: TramoEnFormulario[]): PriceTier[] =>
  filas
    .filter((f) => f.min !== '' && f.price !== '')
    .map((f) => ({ min: Number(f.min), price: Number(f.price) }))
    .sort((a, b) => a.min - b.min);

/**
 * Qué falta para poder guardar, en palabras del dueño, o `null`.
 *
 * Las mismas tres reglas que comprueba el backend
 * (`ValidaTramosDePrecio::comprobarTramos`). Se repiten aquí y no se espera al
 * 422 porque el error del servidor llega apuntando a `price_tiers.1.price`, y el
 * formulario no tiene dónde enseñar eso: el dueño vería un mensaje sobre una
 * fila que no sabe cuál es.
 *
 * `precioBase` es el precio normal del producto o de la variante. Se compara
 * contra él y no contra la oferta a propósito, igual que el backend: la oferta
 * se quita y se pone, y no tendría sentido que quitar una de tres días
 * invalidara el precio por mayor que el dueño negoció con un cliente.
 */
export const problemaDeTramos = (filas: TramoEnFormulario[], precioBase: string): string | null => {
  const puestos = filas.filter((f) => f.min !== '' || f.price !== '');

  for (const [i, fila] of puestos.entries()) {
    const n = i + 1;

    if (fila.min === '' || fila.price === '') {
      return `Al tramo ${n} le falta la cantidad o el precio.`;
    }

    if (Number(fila.min) < MINIMO_DE_UN_TRAMO) {
      return `El tramo ${n} tiene que empezar en ${MINIMO_DE_UN_TRAMO} unidades o más: para una sola está el precio de oferta.`;
    }

    if (Number(fila.price) < 0) {
      return `El precio del tramo ${n} no puede ser negativo.`;
    }

    if (precioBase !== '' && Number(fila.price) >= Number(precioBase)) {
      return `El precio del tramo ${n} tiene que ser menor que el precio normal (${precioBase}).`;
    }
  }

  const ordenados = [...puestos].sort((a, b) => Number(a.min) - Number(b.min));

  for (let i = 1; i < ordenados.length; i++) {
    if (Number(ordenados[i].min) === Number(ordenados[i - 1].min)) {
      return `Hay dos tramos que empiezan en ${ordenados[i].min} unidades.`;
    }

    if (Number(ordenados[i].price) >= Number(ordenados[i - 1].price)) {
      return 'Cada tramo tiene que costar menos que el anterior: a más unidades, menos precio.';
    }
  }

  return null;
};
