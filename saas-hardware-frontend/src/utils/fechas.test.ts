import { describe, expect, it } from 'vitest';
import { formatearFecha, formatearFechaHora } from './fechas';
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
