/**
 * Prefijos telefónicos de los formularios públicos (PUB-4).
 *
 * Antes cada formulario traía `+593` fijo y su propia copia de la lista, así
 * que en una tienda peruana el comprador corregía el prefijo en cada pantalla.
 */

export interface CountryCode {
  code: string;
  /** Lo que se ve en el selector, p. ej. "PE +51". */
  label: string;
}

/**
 * Orden pensado para el selector. La derivación no depende de este orden: se
 * hace por prefijo más largo, así que "+593" gana sobre "+59" aunque estuviera
 * después.
 */
export const COUNTRY_CODES: CountryCode[] = [
  { code: '+51', label: 'PE +51' },
  { code: '+593', label: 'EC +593' },
  { code: '+57', label: 'CO +57' },
  { code: '+52', label: 'MX +52' },
  { code: '+56', label: 'CL +56' },
  { code: '+54', label: 'AR +54' },
  { code: '+591', label: 'BO +591' },
  { code: '+58', label: 'VE +58' },
  { code: '+595', label: 'PY +595' },
  { code: '+598', label: 'UY +598' },
  { code: '+502', label: 'GT +502' },
  { code: '+506', label: 'CR +506' },
  { code: '+55', label: 'BR +55' },
  { code: '+34', label: 'ES +34' },
  { code: '+1', label: 'US +1' },
];

/** Se usa cuando la tienda no tiene un WhatsApp del que deducir el país. */
export const FALLBACK_COUNTRY_CODE = '+51';

/**
 * Deduce el prefijo de la tienda a partir de su número de WhatsApp.
 *
 * El número del tenant puede venir como "+51999888777", "51999888777" o con
 * espacios, así que se normaliza antes de comparar. Se prueba por prefijo más
 * largo primero: si no, "+51" se comería a "+512..." y sobre todo "+1" se
 * comería a casi todo.
 */
export function deriveCountryCode(whatsappNumber?: string | null): string {
  if (!whatsappNumber) return FALLBACK_COUNTRY_CODE;

  const soloDigitos = whatsappNumber.replace(/[^0-9]/g, '');
  if (!soloDigitos) return FALLBACK_COUNTRY_CODE;

  const porLongitud = [...COUNTRY_CODES].sort((a, b) => b.code.length - a.code.length);
  const encontrado = porLongitud.find(({ code }) => soloDigitos.startsWith(code.slice(1)));

  return encontrado?.code ?? FALLBACK_COUNTRY_CODE;
}

/**
 * Separa un teléfono guardado ("+51999888777") en prefijo y número.
 *
 * Lo usan los formularios que autocompletan con los datos del cliente logueado.
 */
export function splitPhone(fullPhone?: string | null): { code: string; number: string } {
  if (!fullPhone) return { code: '', number: '' };

  const porLongitud = [...COUNTRY_CODES].sort((a, b) => b.code.length - a.code.length);
  const encontrado = porLongitud.find(({ code }) => fullPhone.startsWith(code));

  if (!encontrado) return { code: '', number: fullPhone };

  return { code: encontrado.code, number: fullPhone.slice(encontrado.code.length) };
}
