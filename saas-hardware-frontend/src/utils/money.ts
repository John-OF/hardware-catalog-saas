/**
 * Formato de precios en la moneda de cada tienda (OWN-1).
 *
 * Antes el `$` estaba incrustado en cada componente (con un `S/` suelto en el
 * modal de cuenta que ni siquiera coincidía), así que una tienda que vende en
 * soles o pesos no podía usar el producto.
 *
 * OJO: esta lista es la copia de cliente de `saas-hardware-api/config/currencies.php`,
 * que es quien valida el campo. Si se añade o quita una moneda hay que tocar los
 * dos sitios, o el backend devolverá 422 al guardar.
 */

/** Moneda ofrecida en Configuración. `locale` es solo para dar formato. */
type CurrencyConfig = { label: string; locale: string };

export const CURRENCIES: Record<string, CurrencyConfig> = {
  USD: { label: 'Dólar estadounidense (US$)', locale: 'en-US' },
  PEN: { label: 'Sol peruano (S/)', locale: 'es-PE' },
  MXN: { label: 'Peso mexicano ($)', locale: 'es-MX' },
  COP: { label: 'Peso colombiano ($)', locale: 'es-CO' },
  CLP: { label: 'Peso chileno ($)', locale: 'es-CL' },
  ARS: { label: 'Peso argentino ($)', locale: 'es-AR' },
  BOB: { label: 'Boliviano (Bs)', locale: 'es-BO' },
  BRL: { label: 'Real brasileño (R$)', locale: 'pt-BR' },
  UYU: { label: 'Peso uruguayo ($U)', locale: 'es-UY' },
  PYG: { label: 'Guaraní paraguayo (₲)', locale: 'es-PY' },
  VES: { label: 'Bolívar venezolano (Bs.)', locale: 'es-VE' },
  GTQ: { label: 'Quetzal guatemalteco (Q)', locale: 'es-GT' },
  DOP: { label: 'Peso dominicano (RD$)', locale: 'es-DO' },
  CRC: { label: 'Colón costarricense (₡)', locale: 'es-CR' },
  EUR: { label: 'Euro (€)', locale: 'es-ES' },
};

export const DEFAULT_CURRENCY = 'USD';

/**
 * Formatea un importe en la moneda de la tienda.
 *
 * Se usa el locale nativo de cada moneda, no el del navegador: un precio en
 * soles debe verse "S/ 45.99" aunque el comprador tenga el navegador en inglés.
 * `Intl` también resuelve solo los decimales (CLP, COP y PYG no llevan).
 *
 * @param amount el precio; se acepta string porque la API devuelve los decimales
 *               como texto (`total: "1028.98"`) para no perder precisión.
 */
export function formatMoney(amount: number | string | null | undefined, currency?: string | null): string {
  const value = typeof amount === 'string' ? parseFloat(amount) : amount;
  const safeValue = Number.isFinite(value as number) ? (value as number) : 0;
  const code = (currency ?? DEFAULT_CURRENCY).toUpperCase();
  const config = CURRENCIES[code];

  try {
    return new Intl.NumberFormat(config?.locale ?? 'es', {
      style: 'currency',
      currency: code,
      // Sin esto, en varios locales sale "PEN 45,99" en vez de "S/ 45.99".
      currencyDisplay: 'narrowSymbol',
    }).format(safeValue);
  } catch {
    // Código desconocido o navegador sin soporte de narrowSymbol.
    return `${code} ${safeValue.toFixed(2)}`;
  }
}
