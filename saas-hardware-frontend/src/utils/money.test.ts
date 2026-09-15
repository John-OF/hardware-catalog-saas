import { describe, expect, it } from 'vitest';
import { formatMoney } from './money';

// Intl separa simbolo e importe con espacios duros (U+00A0 o U+202F segun el
// locale); `\s` los incluye a los dos.
const normalizar = (texto: string) => texto.replace(/\s/g, ' ');

describe('formatMoney (OWN-1)', () => {
  it('formatea en la moneda de la tienda, no con un $ fijo', () => {
    expect(normalizar(formatMoney(45.99, 'PEN'))).toBe('S/ 45.99');
    expect(formatMoney(1028.98, 'USD')).toBe('$1,028.98');
  });

  it('acepta el decimal como texto, que es como lo manda la API', () => {
    expect(formatMoney('1028.98', 'USD')).toBe('$1,028.98');
  });

  it('las monedas sin decimales no los enseñan', () => {
    expect(normalizar(formatMoney(15000, 'CLP'))).toBe('$15.000');
  });

  it('un importe inválido o ausente sale como 0 en vez de "NaN"', () => {
    expect(formatMoney(null, 'USD')).toBe('$0.00');
    expect(formatMoney('abc', 'USD')).toBe('$0.00');
  });

  it('sin moneda usa USD y acepta el código en minúsculas', () => {
    expect(formatMoney(10)).toBe('$10.00');
    expect(formatMoney(10, 'usd')).toBe('$10.00');
  });

  it('un código que Intl no acepta no rompe la pantalla', () => {
    expect(formatMoney(12.5, 'XX')).toBe('XX 12.50');
  });
});
