import { zonaDeLaTienda } from './timezones';

/**
 * Fechas del panel, pintadas en la hora de la tienda (MOD-13).
 *
 * **Por qué no vale `new Date(iso).toLocaleDateString()` a secas**, que es lo
 * que había antes: eso pinta la fecha en la zona del NAVEGADOR. Coincide con la
 * de la tienda mientras el dueño esté en su local con su portátil, y deja de
 * coincidir en cuanto viaja, contrata a alguien en otro país o mira el panel
 * desde un servidor. Dos personas del mismo equipo veían fechas distintas para
 * el mismo pedido, y ninguna de las dos tenía por qué ser la de la tienda.
 *
 * Ahora la zona la manda la tienda y el navegador solo la obedece.
 *
 * El API devuelve los timestamps en UTC con su `Z`, así que `Date` los entiende
 * sin ayuda; lo único que hay que decirle es en qué zona escribirlos.
 */

/** "15 sep 2026" */
export function formatearFecha(iso: string | null | undefined, timezone?: string | null): string {
  return formatear(iso, timezone, { dateStyle: 'medium' });
}

/** "15 sep 2026, 20:00" — para cuando la hora importa (un pedido, una línea de actividad). */
export function formatearFechaHora(iso: string | null | undefined, timezone?: string | null): string {
  return formatear(iso, timezone, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Cuántos días de calendario de la tienda faltan hasta `iso`: 0 si es hoy, 1 si
 * es mañana (INF-23). Cuenta fechas y no horas: el 1 de noviembre por la tarde,
 * algo que vence el 5 a última hora vence «en 4 días», aunque sean cuatro días y
 * unas horas. `null` si no hay fecha o no se entiende.
 *
 * `ahora` se pasa desde fuera porque leer el reloj durante el render es impuro.
 */
export function diasDeCalendarioHasta(iso: string | null | undefined, timezone: string | null | undefined, ahora: Date): number | null {
  if (!iso) return null;

  const fin = new Date(iso);
  if (Number.isNaN(fin.getTime())) return null;

  // `en-CA` escribe AAAA-MM-DD: la fecha de cada instante en la zona de la tienda.
  const dia = (fecha: Date) => {
    try {
      return new Intl.DateTimeFormat('en-CA', { timeZone: zonaDeLaTienda(timezone) }).format(fecha);
    } catch {
      return new Intl.DateTimeFormat('en-CA').format(fecha);
    }
  };

  return Math.round((Date.parse(dia(fin)) - Date.parse(dia(ahora))) / (1000 * 60 * 60 * 24));
}

/**
 * Devuelve cadena vacía si no hay fecha, en vez de "Invalid Date".
 *
 * Varias columnas del panel son opcionales —`ultima_compra` de un cliente que
 * no ha comprado, por ejemplo—, y cada pantalla decide qué poner en su lugar.
 */
function formatear(
  iso: string | null | undefined,
  timezone: string | null | undefined,
  opciones: Intl.DateTimeFormatOptions,
): string {
  if (!iso) return '';

  const fecha = new Date(iso);

  if (Number.isNaN(fecha.getTime())) return '';

  try {
    return new Intl.DateTimeFormat('es', { ...opciones, timeZone: zonaDeLaTienda(timezone) }).format(fecha);
  } catch {
    // Un navegador sin esa zona en su base de datos de husos. Mejor la fecha en
    // la hora del navegador que un hueco en la tabla.
    return new Intl.DateTimeFormat('es', opciones).format(fecha);
  }
}
