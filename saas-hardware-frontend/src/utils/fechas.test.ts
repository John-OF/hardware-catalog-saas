import { describe, expect, it } from 'vitest';
import { diasDeCalendarioHasta, formatearFecha, formatearFechaHora } from './fechas';
import { zonaDeLaTienda } from './timezones';

/**
 * Fechas del panel en la hora de la tienda (MOD-13).
 *
 * Lo que importa aquí no es el formato sino **qué día sale**: el mismo instante
 * tiene que caer en días distintos según la zona, que es justo lo que el panel
 * hacía mal cuando dejaba el formato al reloj del navegador.
 */
describe('fechas (MOD-13)', () => {
  // 2026-09-16 01:00 UTC = 2026-09-15 20:00 en Lima.
  const laVentaDeLaNoche = '2026-09-16T01:00:00Z';

  it('el mismo instante cae en un día distinto según la zona de la tienda', () => {
    expect(formatearFecha(laVentaDeLaNoche, 'America/Lima')).toContain('15');
    expect(formatearFecha(laVentaDeLaNoche, 'UTC')).toContain('16');
  });

  it('con hora, enseña la del mostrador y no la del servidor', () => {
    expect(formatearFechaHora(laVentaDeLaNoche, 'America/Lima')).toContain('20:00');
    expect(formatearFechaHora(laVentaDeLaNoche, 'UTC')).toContain('1:00');
  });

  it('una zona positiva empuja al día siguiente', () => {
    // 23:30 UTC ya es del 16 en Madrid.
    expect(formatearFecha('2026-09-15T23:30:00Z', 'Europe/Madrid')).toContain('16');
  });

  it('sin zona cae en UTC, que es como se comportaba el panel antes', () => {
    expect(formatearFecha(laVentaDeLaNoche, null)).toBe(formatearFecha(laVentaDeLaNoche, 'UTC'));
    expect(formatearFecha(laVentaDeLaNoche, undefined)).toBe(formatearFecha(laVentaDeLaNoche, 'UTC'));
  });

  it('una zona que no está en la lista no se usa a ciegas', () => {
    // El backend valida contra la misma lista; si llegara otra cosa —una fila
    // tocada a mano— se cae a UTC en vez de dejar que Intl lance.
    expect(zonaDeLaTienda('Marte/Olympus_Mons')).toBe('UTC');
    expect(formatearFecha(laVentaDeLaNoche, 'Marte/Olympus_Mons')).toContain('16');
  });

  it('una fecha ausente o inválida deja el hueco vacío, no "Invalid Date"', () => {
    expect(formatearFecha(null, 'America/Lima')).toBe('');
    expect(formatearFecha(undefined, 'America/Lima')).toBe('');
    expect(formatearFecha('', 'America/Lima')).toBe('');
    expect(formatearFecha('no es una fecha', 'America/Lima')).toBe('');
  });
});

describe('diasDeCalendarioHasta (INF-23)', () => {
  const ahora = new Date('2026-11-01T23:00:00Z');

  it('cuenta fechas y no horas', () => {
    // Del 1 por la noche al 5 a última hora son cuatro días y casi un día más:
    // se lee «en 4 días», no «en 5».
    expect(diasDeCalendarioHasta('2026-11-05T23:59:59Z', 'UTC', ahora)).toBe(4);
    expect(diasDeCalendarioHasta('2026-11-02T00:30:00Z', 'UTC', ahora)).toBe(1);
    expect(diasDeCalendarioHasta('2026-11-01T23:59:00Z', 'UTC', ahora)).toBe(0);
  });

  it('los días son los de la tienda', () => {
    // A las 23:00 UTC del 1, en Guayaquil (UTC-5) son las 18:00 del 1; a las
    // 02:00 UTC del 2, siguen siendo las 21:00 del 1: vence hoy, no mañana.
    expect(diasDeCalendarioHasta('2026-11-02T02:00:00Z', 'America/Guayaquil', ahora)).toBe(0);
    expect(diasDeCalendarioHasta('2026-11-02T02:00:00Z', 'UTC', ahora)).toBe(1);
  });

  it('sin fecha, o con una que no se entiende, no hay cuenta', () => {
    expect(diasDeCalendarioHasta(null, 'UTC', ahora)).toBeNull();
    expect(diasDeCalendarioHasta('no es una fecha', 'UTC', ahora)).toBeNull();
  });
});
