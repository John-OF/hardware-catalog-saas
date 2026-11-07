import { describe, expect, it } from 'vitest';
import { controlaStock, hayParaPedir, quedanPocas } from './stock';
import { unaVariante, unProducto } from '../test/fixtures';

describe('stock (MOD-22)', () => {
  it('un producto sin el campo lleva la cuenta, como todo lo de antes', () => {
    expect(controlaStock(unProducto())).toBe(true);
    expect(controlaStock(unProducto({ track_stock: true }))).toBe(true);
    expect(controlaStock(unProducto({ track_stock: false }))).toBe(false);
  });

  it('con control de stock, se pide si quedan unidades', () => {
    expect(hayParaPedir(unProducto({ stock: 3 }))).toBe(true);
    expect(hayParaPedir(unProducto({ stock: 0 }))).toBe(false);
    // Una venta de mostrador puede dejarlo en negativo.
    expect(hayParaPedir(unProducto({ stock: -2 }))).toBe(false);
  });

  it('sin control de stock se pide siempre, aunque el número guardado sea 0', () => {
    expect(hayParaPedir(unProducto({ track_stock: false, stock: 0 }))).toBe(true);
    expect(hayParaPedir(unProducto({ track_stock: false, stock: -4 }))).toBe(true);
  });

  it('con variante elegida manda su stock, salvo que el producto no lleve la cuenta', () => {
    const agotada = unaVariante({ stock: 0 });

    expect(hayParaPedir(unProducto({ stock: 10 }), agotada)).toBe(false);
    expect(hayParaPedir(unProducto({ stock: 10 }), unaVariante({ stock: 2 }))).toBe(true);
    expect(hayParaPedir(unProducto({ track_stock: false, stock: 0 }), agotada)).toBe(true);
  });

  it('"Pocas unidades" solo con control de stock y entre 1 y 4', () => {
    expect(quedanPocas(unProducto({ stock: 4 }))).toBe(true);
    expect(quedanPocas(unProducto({ stock: 5 }))).toBe(false);
    expect(quedanPocas(unProducto({ stock: 0 }))).toBe(false);
    expect(quedanPocas(unProducto({ track_stock: false, stock: 2 }))).toBe(false);
  });
});
