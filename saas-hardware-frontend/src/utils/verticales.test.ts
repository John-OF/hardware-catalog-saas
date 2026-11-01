import { describe, expect, it } from 'vitest';
import { MODULOS_DE_VERTICAL, tieneModulo } from './verticales';

describe('tieneModulo (MOD-19)', () => {
  it('lee lo que manda la API', () => {
    const general = { modulos: { tipo_de_componente: false, ficha_tecnica: false, armador: false } };
    const hardware = { modulos: { tipo_de_componente: true, ficha_tecnica: true, armador: true } };

    for (const modulo of MODULOS_DE_VERTICAL) {
      expect(tieneModulo(hardware, modulo)).toBe(true);
      expect(tieneModulo(general, modulo)).toBe(false);
    }
  });

  it('falla en cerrado: sin tienda, sin módulos o con un valor raro, no lo tiene', () => {
    expect(tieneModulo(null, 'armador')).toBe(false);
    expect(tieneModulo(undefined, 'armador')).toBe(false);
    expect(tieneModulo({}, 'armador')).toBe(false);
    expect(tieneModulo({ modulos: {} }, 'armador')).toBe(false);
    // Solo `true` cuenta: un "1" o un "true" de texto no encienden nada.
    expect(tieneModulo({ modulos: { armador: 'true' as unknown as boolean } }, 'armador')).toBe(false);
  });
});
