import type { PaymentMethod } from '../types';

/**
 * Métodos de pago que la tienda le enseña al comprador (MOD-3, MOD-23).
 *
 * No es una pasarela: nada de esto cobra nada. Es el texto que se le muestra
 * al comprador en el checkout y en el mensaje de WhatsApp, para que sepa cómo
 * pagarle antes de coordinar el resto por chat.
 *
 * Desde MOD-23 son una lista que escribe la tienda (nombre, datos,
 * instrucciones y un QR opcional), en el orden que ella elige.
 */

export interface MetodoDePagoInfo {
  clave: string;
  etiqueta: string;
  /** "987654321 · Ana Dueña", o "" cuando el método no tiene nada más que decir (efectivo). */
  detalle: string;
  instrucciones: string;
  qr: string | null;
}

/**
 * Solo los métodos ENCENDIDOS y con nombre, en su orden. El backend ya filtra
 * los apagados para el catálogo; esto es la segunda barrera, y la que usa el
 * panel, que sí recibe la lista entera. Cualquier cosa que no sea una lista
 * (una respuesta de antes de MOD-23) cuenta como ninguno.
 */
export function metodosDePagoActivos(metodos?: PaymentMethod[] | null): MetodoDePagoInfo[] {
  if (!Array.isArray(metodos)) return [];

  return metodos
    .filter((m) => m.enabled && m.name?.trim())
    .map((m) => ({
      clave: m.id,
      etiqueta: m.name.trim(),
      detalle: m.details?.trim() ?? '',
      instrucciones: m.instructions?.trim() ?? '',
      qr: m.qr_url ?? null,
    }));
}
