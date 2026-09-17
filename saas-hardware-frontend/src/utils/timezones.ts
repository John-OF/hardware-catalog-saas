/**
 * Zonas horarias que puede elegir una tienda (MOD-13).
 *
 * **El criterio es dónde hay tiendas, no qué moneda usan.** La primera versión
 * de esta lista salió de las monedas, y eso dejó fuera a Ecuador, Panamá y El
 * Salvador: los tres usan el dólar, así que no tenían entrada propia allí. Un
 * dueño de Guayaquil abría el selector y no encontraba su país.
 *
 * Y la salida no era que eligiera "Perú (Lima)" porque hoy coincidan en UTC-5:
 * Ecuador tiene su propio identificador IANA, y que los dos desplazamientos
 * coincidan es historia, no una garantía.
 *
 * OJO: esta lista es la copia de cliente de
 * `saas-hardware-api/config/timezones.php`, que es quien valida el campo. Las
 * dos tienen que tener las mismas claves y hay un test de backend que lo
 * comprueba; si se separan, el backend devuelve 422 al guardar.
 */
export const TIMEZONES: Record<string, string> = {
  UTC: 'UTC (sin desplazamiento)',

  'America/Argentina/Buenos_Aires': 'Argentina (Buenos Aires)',
  'America/La_Paz': 'Bolivia (La Paz)',
  'America/Manaus': 'Brasil (Manaos)',
  'America/Sao_Paulo': 'Brasil (São Paulo)',
  'America/Santiago': 'Chile (Santiago)',
  'America/Bogota': 'Colombia (Bogotá)',
  'America/Costa_Rica': 'Costa Rica',
  'America/Havana': 'Cuba (La Habana)',
  'America/Guayaquil': 'Ecuador (Guayaquil, Quito)',
  'Pacific/Galapagos': 'Ecuador (Galápagos)',
  'America/El_Salvador': 'El Salvador',
  'Europe/Madrid': 'España (Madrid)',
  'America/Guatemala': 'Guatemala',
  'America/Tegucigalpa': 'Honduras (Tegucigalpa)',
  'America/Cancun': 'México (Cancún)',
  'America/Mexico_City': 'México (Ciudad de México)',
  'America/Tijuana': 'México (Tijuana)',
  'America/Managua': 'Nicaragua (Managua)',
  'America/Panama': 'Panamá',
  'America/Asuncion': 'Paraguay (Asunción)',
  'America/Lima': 'Perú (Lima)',
  'America/Puerto_Rico': 'Puerto Rico',
  'America/Santo_Domingo': 'República Dominicana',
  'America/Montevideo': 'Uruguay (Montevideo)',
  'America/Caracas': 'Venezuela (Caracas)',
};

export const DEFAULT_TIMEZONE = 'UTC';

/**
 * La zona que hay que usar para pintar fechas del panel.
 *
 * Una tienda de antes de esta columna, o un `tenant` que aún no ha cargado,
 * cae en UTC: es como se comportaba el panel entero antes de MOD-13.
 */
export function zonaDeLaTienda(timezone?: string | null): string {
  return timezone && timezone in TIMEZONES ? timezone : DEFAULT_TIMEZONE;
}

/**
 * El desplazamiento actual de una zona, como "UTC-5".
 *
 * **Se calcula, no se escribe en la etiqueta.** Donde hay horario de verano
 * —Chile, Paraguay, España, México (Tijuana)— cambia dos veces al año, así que
 * un texto fijo mentiría media temporada. Calculado al vuelo siempre dice la
 * verdad de hoy.
 *
 * Existe porque saber geografía no puede ser requisito para configurar una
 * tienda: con el desplazamiento delante, quien no sepa en qué huso cae su país
 * lo reconoce igual, y se ve de un vistazo cuándo dos opciones son
 * equivalentes.
 *
 * Devuelve cadena vacía si el navegador no sabe resolverlo: la etiqueta del
 * país sigue siendo útil sin esto.
 */
export function desplazamientoDe(timezone: string): string {
  try {
    const parte = new Intl.DateTimeFormat('en-US', { timeZone: timezone, timeZoneName: 'shortOffset' })
      .formatToParts(new Date())
      .find((p) => p.type === 'timeZoneName')?.value;

    if (!parte) return '';

    // Intl devuelve "GMT-5", y "GMT" a secas cuando el desplazamiento es cero.
    return parte === 'GMT' ? 'UTC+0' : parte.replace('GMT', 'UTC');
  } catch {
    return '';
  }
}

/** "Ecuador (Guayaquil, Quito) — UTC-5", que es como se lee en el selector. */
export function etiquetaDeZona(timezone: string): string {
  const nombre = TIMEZONES[timezone] ?? timezone;

  if (timezone === DEFAULT_TIMEZONE) return nombre;

  const desplazamiento = desplazamientoDe(timezone);

  return desplazamiento ? `${nombre} — ${desplazamiento}` : nombre;
}
