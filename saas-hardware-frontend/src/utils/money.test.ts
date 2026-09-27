import { describe, expect, it } from 'vitest';
import { CURRENCIES, formatMoney } from './money';

/**
 * `·` en los esperados es el espacio que no se parte (U+00A0): se escribe así
 * para que se vea, y aquí se convierte.
 */
const visible = (texto: string) => texto.replace(/·/g, String.fromCharCode(0xa0));

describe('formatMoney (OWN-1)', () => {
  it('formatea en la moneda de la tienda, no con un $ fijo', () => {
    expect(formatMoney(45.99, 'PEN')).toBe(visible('S/·45.99'));
    expect(formatMoney(1028.98, 'USD')).toBe('$1,028.98');
  });

  it('acepta el decimal como texto, que es como lo manda la API', () => {
    expect(formatMoney('1028.98', 'USD')).toBe('$1,028.98');
  });

  it('las monedas sin decimales no los enseñan', () => {
    expect(formatMoney(15000, 'CLP')).toBe('$15.000');
  });

  it('un importe inválido o ausente sale como 0 en vez de "NaN"', () => {
    expect(formatMoney(null, 'USD')).toBe('$0.00');
    expect(formatMoney('abc', 'USD')).toBe('$0.00');
  });

  it('sin moneda usa USD y acepta el código en minúsculas', () => {
    expect(formatMoney(10)).toBe('$10.00');
    expect(formatMoney(10, 'usd')).toBe('$10.00');
  });

  it('un código desconocido no rompe la pantalla ni se inventa un símbolo', () => {
    expect(formatMoney(12.5, 'XX')).toBe('XX 12.50');
    expect(formatMoney(1028.98, 'XYZ')).toBe('XYZ 1,028.98');
  });
});

/**
 * `FUN-23`: el panel y la cotización en PDF escriben el precio igual. Estos
 * casos son **exactamente** los de `FormatoDeMonedaTest` (PHPUnit), que prueba
 * `Money::format()` del servidor: si se cambia uno, el otro. Que las dos copias
 * de la tabla digan lo mismo lo vigila ese mismo test.
 */
describe('formatMoney escribe como el servidor (FUN-23)', () => {
  it('cada moneda de la lista', () => {
    const esperados: Record<string, string> = {
      USD: '$1,234,567.50',
      PEN: 'S/·1,234,567.50',
      MXN: '$1,234,567.50',
      COP: '$·1.234.568',
      CLP: '$1.234.568',
      ARS: '$·1.234.567,50',
      BOB: 'Bs·1.234.567,50',
      BRL: 'R$·1.234.567,50',
      UYU: '$U·1.234.567,50',
      PYG: '₲·1.234.568',
      VES: 'Bs.·1.234.567,50',
      GTQ: 'Q·1,234,567.50',
      DOP: 'RD$1,234,567.50',
      CRC: '₡1·234·567,50',
      EUR: '1.234.567,50·€',
    };

    expect(Object.keys(esperados)).toEqual(Object.keys(CURRENCIES));
    for (const [moneda, esperado] of Object.entries(esperados)) {
      expect(formatMoney(1234567.5, moneda), moneda).toBe(visible(esperado));
    }
  });

  it('el redondeo y el signo', () => {
    // Sin decimales se redondea el medio hacia fuera, como `number_format()`.
    expect(formatMoney(1028.98, 'CLP')).toBe('$1.029');
    expect(formatMoney(0.5, 'PYG')).toBe(visible('₲·1'));
    // `number_format()` redondea 1.005 a 1.01; `toFixed()` a secas daría 1.00.
    expect(formatMoney(1.005, 'USD')).toBe('$1.01');
    // El signo va delante de todo, y un cero redondeado no lo lleva.
    expect(formatMoney(-5, 'PEN')).toBe(visible('-S/·5.00'));
    expect(formatMoney(-5, 'EUR')).toBe(visible('-5,00·€'));
    expect(formatMoney(-0.4, 'CLP')).toBe('$0');
    // Lo que devuelve la API: el decimal como texto.
    expect(formatMoney('1028.98', 'ARS')).toBe(visible('$·1.028,98'));
  });

  it('el espacio entre símbolo e importe no se parte', () => {
    expect(formatMoney(45.99, 'PEN').charCodeAt(2)).toBe(0xa0);
  });
});
