import { describe, expect, it } from 'vitest';
import { deriveCountryCode, FALLBACK_COUNTRY_CODE, splitPhone } from './phone';

describe('deriveCountryCode (PUB-4)', () => {
  it('saca el prefijo del WhatsApp de la tienda, con o sin + y espacios', () => {
    expect(deriveCountryCode('+51 999 888 777')).toBe('+51');
    expect(deriveCountryCode('51999888777')).toBe('+51');
    expect(deriveCountryCode('+593 99 123 4567')).toBe('+593');
  });

  // Hoy ningún prefijo de la lista empieza por otro, así que esto no ejercita el
  // orden por longitud de `deriveCountryCode`: solo fija que los parecidos no se cruzan.
  it('no confunde prefijos parecidos ni deja que +1 se coma a los demás', () => {
    expect(deriveCountryCode('+595981000000')).toBe('+595');
    expect(deriveCountryCode('+5025555')).toBe('+502');
    expect(deriveCountryCode('+12025550123')).toBe('+1');
  });

  it('sin número o sin prefijo conocido cae al de por defecto', () => {
    expect(deriveCountryCode(null)).toBe(FALLBACK_COUNTRY_CODE);
    expect(deriveCountryCode('   ')).toBe(FALLBACK_COUNTRY_CODE);
    expect(deriveCountryCode('+44 20 7946 0000')).toBe(FALLBACK_COUNTRY_CODE);
  });
});

describe('splitPhone', () => {
  it('separa prefijo y número', () => {
    expect(splitPhone('+593991234567')).toEqual({ code: '+593', number: '991234567' });
    expect(splitPhone('+51987654321')).toEqual({ code: '+51', number: '987654321' });
  });

  it('sin prefijo conocido deja el número entero y el prefijo vacío ("Otro")', () => {
    expect(splitPhone('987654321')).toEqual({ code: '', number: '987654321' });
    expect(splitPhone(undefined)).toEqual({ code: '', number: '' });
  });
});
