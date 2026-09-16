import type { PaymentMethods } from '../types';

/**
 * Métodos de pago que la tienda le enseña al comprador (MOD-3).
 *
 * No es una pasarela: nada de esto cobra nada. Es el texto que se le muestra
 * al comprador en el checkout y en el mensaje de WhatsApp, para que sepa cómo
 * pagarle antes de coordinar el resto por chat.
 */

type ClaveDeMetodo = keyof PaymentMethods;

/** En el orden en que se enseñan: los dos digitales primero, luego el banco, luego efectivo. */
const ORDEN: ClaveDeMetodo[] = ['yape', 'plin', 'transferencia', 'efectivo'];

const ETIQUETAS: Record<ClaveDeMetodo, string> = {
  yape: 'Yape',
  plin: 'Plin',
  transferencia: 'Transferencia bancaria',
  efectivo: 'Efectivo contra entrega',
};

export interface MetodoDePagoInfo {
  clave: ClaveDeMetodo;
  etiqueta: string;
  /** "987654321 · Ana Dueña", o "" cuando el método no tiene nada más que decir (efectivo). */
  detalle: string;
}

function detalleDe(clave: ClaveDeMetodo, datos: NonNullable<PaymentMethods[ClaveDeMetodo]>): string {
  if (clave === 'transferencia') {
    const d = datos as NonNullable<PaymentMethods['transferencia']>;
    const cuenta = [d.bank, d.account_number].filter(Boolean).join(' ');
    return [cuenta, d.holder_name].filter(Boolean).join(' · ');
  }

  if (clave === 'yape' || clave === 'plin') {
    const d = datos as NonNullable<PaymentMethods['yape']>;
    return [d.phone, d.holder_name].filter(Boolean).join(' · ');
  }

  return '';
}

/** Solo los métodos ENCENDIDOS (el backend ya filtra los apagados, esto es la segunda barrera). */
export function metodosDePagoActivos(paymentMethods?: PaymentMethods | null): MetodoDePagoInfo[] {
  if (!paymentMethods) return [];

  return ORDEN
    .filter((clave) => paymentMethods[clave]?.enabled)
    .map((clave) => ({
      clave,
      etiqueta: ETIQUETAS[clave],
      detalle: detalleDe(clave, paymentMethods[clave]!),
    }));
}
