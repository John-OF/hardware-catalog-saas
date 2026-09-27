/**
 * Formato de precios en la moneda de cada tienda (OWN-1).
 *
 * Antes el `$` estaba incrustado en cada componente (con un `S/` suelto en el
 * modal de cuenta que ni siquiera coincidía), así que una tienda que vende en
 * soles o pesos no podía usar el producto.
 *
 * OJO: esta lista es la copia de cliente de `saas-hardware-api/config/currencies.php`,
 * que es quien valida el campo y con la que el servidor escribe los precios de
 * la cotización en PDF y de los correos (`App\Support\Money`). Si se añade o
 * quita una moneda, o se cambia su formato, hay que tocar los dos sitios:
 * `FormatoDeMonedaTest` (PHPUnit) compara las dos copias y falla si se separan.
 *
 * El formato va escrito en la tabla y no se le pide a `Intl` (FUN-23). Con
 * `Intl` el panel escribía `$ 9.000.000,50` y la cotización que recibía el
 * cliente, `$9,000,000.50`: en 10 de las 15 monedas el mismo precio salía de
 * dos formas. Y cada navegador trae su propia versión de ICU, así que ni
 * siquiera `Intl` contra `Intl` estaba garantizado. Los separadores reproducen
 * lo que pintaba `Intl` en cada país; los símbolos son los de la etiqueta que el
 * dueño elige (`Intl` pintaba `$` para UYU y DOP, y `Bs.S` para VES).
 */

/**
 * Moneda ofrecida en Configuración, y cómo se escribe.
 *
 * `patron`: `¤` es el símbolo, `#` el número, y un espacio se pinta como espacio
 * que no se parte (U+00A0), para que el símbolo no quede solo al final de una
 * línea.
 */
type CurrencyConfig = {
  label: string;
  simbolo: string;
  decimales: number;
  miles: string;
  decimal: string;
  patron: string;
};

export const CURRENCIES: Record<string, CurrencyConfig> = {
  USD: { label: 'Dólar estadounidense (US$)', simbolo: '$', decimales: 2, miles: ',', decimal: '.', patron: '¤#' },
  PEN: { label: 'Sol peruano (S/)', simbolo: 'S/', decimales: 2, miles: ',', decimal: '.', patron: '¤ #' },
  MXN: { label: 'Peso mexicano ($)', simbolo: '$', decimales: 2, miles: ',', decimal: '.', patron: '¤#' },
  COP: { label: 'Peso colombiano ($)', simbolo: '$', decimales: 0, miles: '.', decimal: ',', patron: '¤ #' },
  CLP: { label: 'Peso chileno ($)', simbolo: '$', decimales: 0, miles: '.', decimal: ',', patron: '¤#' },
  ARS: { label: 'Peso argentino ($)', simbolo: '$', decimales: 2, miles: '.', decimal: ',', patron: '¤ #' },
  BOB: { label: 'Boliviano (Bs)', simbolo: 'Bs', decimales: 2, miles: '.', decimal: ',', patron: '¤ #' },
  BRL: { label: 'Real brasileño (R$)', simbolo: 'R$', decimales: 2, miles: '.', decimal: ',', patron: '¤ #' },
  UYU: { label: 'Peso uruguayo ($U)', simbolo: '$U', decimales: 2, miles: '.', decimal: ',', patron: '¤ #' },
  PYG: { label: 'Guaraní paraguayo (₲)', simbolo: '₲', decimales: 0, miles: '.', decimal: ',', patron: '¤ #' },
  VES: { label: 'Bolívar venezolano (Bs.)', simbolo: 'Bs.', decimales: 2, miles: '.', decimal: ',', patron: '¤ #' },
  GTQ: { label: 'Quetzal guatemalteco (Q)', simbolo: 'Q', decimales: 2, miles: ',', decimal: '.', patron: '¤ #' },
  DOP: { label: 'Peso dominicano (RD$)', simbolo: 'RD$', decimales: 2, miles: ',', decimal: '.', patron: '¤#' },
  CRC: { label: 'Colón costarricense (₡)', simbolo: '₡', decimales: 2, miles: '\u00A0', decimal: ',', patron: '¤#' },
  EUR: { label: 'Euro (€)', simbolo: '€', decimales: 2, miles: '.', decimal: ',', patron: '# ¤' },
};

export const DEFAULT_CURRENCY = 'USD';

/** El espacio que no se parte. */
const ESPACIO = '\u00A0';

/**
 * Lo mismo que `number_format()` de PHP, que es lo que usa el servidor: redondeo
 * del medio hacia fuera, y antes, a 15 cifras significativas, como hace PHP
 * (sin eso, 1.005 saldría 1.00 aquí y 1.01 en la cotización).
 */
function numberFormat(valor: number, decimales: number, decimal: string, miles: string): string {
  const factor = 10 ** decimales;
  const redondeado = Math.round(Number((Math.abs(valor) * factor).toPrecision(15))) / factor;
  const [entero, fraccion] = redondeado.toFixed(decimales).split('.');
  const conMiles = entero.replace(/\B(?=(\d{3})+(?!\d))/g, miles);
  const signo = valor < 0 && redondeado !== 0 ? '-' : '';

  return signo + conMiles + (decimales > 0 ? decimal + fraccion : '');
}

/**
 * Formatea un importe en la moneda de la tienda, igual que `Money::format()`
 * del servidor: el formato es el de la moneda, no el del navegador, así que un
 * precio en soles se ve "S/ 45.99" aunque el comprador lo tenga en inglés.
 *
 * @param amount el precio; se acepta string porque la API devuelve los decimales
 *               como texto (`total: "1028.98"`) para no perder precisión.
 */
export function formatMoney(amount: number | string | null | undefined, currency?: string | null): string {
  const value = typeof amount === 'string' ? parseFloat(amount) : amount;
  const safeValue = Number.isFinite(value as number) ? (value as number) : 0;
  const code = (currency ?? DEFAULT_CURRENCY).toUpperCase();
  const config = CURRENCIES[code];

  // Moneda desconocida: el código delante en vez de inventarse un símbolo.
  if (!config) {
    return `${code} ${numberFormat(safeValue, 2, '.', ',')}`;
  }

  const numero = numberFormat(Math.abs(safeValue), config.decimales, config.decimal, config.miles);
  // El signo delante de todo, y un cero redondeado no lo lleva.
  const signo = safeValue < 0 && /[1-9]/.test(numero) ? '-' : '';

  return signo + config.patron.replace(/[¤# ]/g, (marca) =>
    marca === '¤' ? config.simbolo : marca === '#' ? numero : ESPACIO,
  );
}
