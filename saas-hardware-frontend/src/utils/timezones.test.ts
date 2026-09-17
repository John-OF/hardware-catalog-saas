import { describe, expect, it } from 'vitest';
import { TIMEZONES, desplazamientoDe, etiquetaDeZona, zonaDeLaTienda } from './timezones';

/**
 * La lista de zonas y cómo se lee en el selector (MOD-13).
 *
 * El test de que esta lista no se separe de `config/timezones.php` vive en el
 * backend (`ZonaHorariaDeLaTiendaTest`), que es quien valida el campo. Aquí se
 * prueba lo que el backend no ve: cómo se le presenta al dueño.
 */
describe('timezones (MOD-13)', () => {
  it('los países dolarizados tienen su propia entrada', () => {
    // El hueco que encontró el dueño: la lista salió de las monedas, y estos
    // tres usan el dólar, así que no tenían moneda propia y desaparecieron.
    expect(TIMEZONES).toHaveProperty('America/Guayaquil');
    expect(TIMEZONES).toHaveProperty('America/Panama');
    expect(TIMEZONES).toHaveProperty('America/El_Salvador');
  });

  it('un país con varios husos lleva una entrada por huso', () => {
    // México va de UTC-8 a UTC-6: sólo su capital dejaría fuera a media Tijuana.
    expect(desplazamientoDe('America/Tijuana')).not.toBe(desplazamientoDe('America/Cancun'));
  });

  it('la etiqueta lleva el desplazamiento, para no tener que saber geografía', () => {
    expect(etiquetaDeZona('America/Guayaquil')).toBe('Ecuador (Guayaquil, Quito) — UTC-5');
  });

  it('dos países equivalentes se ven equivalentes, pero cada uno con su zona', () => {
    // Ecuador y Perú coinciden hoy en UTC-5; el selector lo deja ver. Lo que no
    // se hace es mandar a un ecuatoriano a elegir "Perú": si mañana uno de los
    // dos cambiara sus reglas, su tienda se movería sola.
    expect(desplazamientoDe('America/Guayaquil')).toBe(desplazamientoDe('America/Lima'));
    expect(TIMEZONES['America/Guayaquil']).not.toBe(TIMEZONES['America/Lima']);
  });

  it('UTC se queda sin sufijo, que ya lo lleva en el nombre', () => {
    expect(etiquetaDeZona('UTC')).toBe('UTC (sin desplazamiento)');
  });

  it('una zona desconocida no se usa a ciegas', () => {
    expect(zonaDeLaTienda('Marte/Olympus_Mons')).toBe('UTC');
    expect(desplazamientoDe('Marte/Olympus_Mons')).toBe('');
    // Y la etiqueta sigue saliendo, sin sufijo, en vez de romper el selector.
    expect(etiquetaDeZona('Marte/Olympus_Mons')).toBe('Marte/Olympus_Mons');
  });

  it('todas las zonas de la lista las entiende el navegador', () => {
    for (const zona of Object.keys(TIMEZONES)) {
      expect(() => new Intl.DateTimeFormat('es', { timeZone: zona }), zona).not.toThrow();
    }
  });
});
