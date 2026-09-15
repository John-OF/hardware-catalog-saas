import { describe, expect, it } from 'vitest';
import { claveDeLinea, datosDeVenta, nombreConVariante, precioEsDesde, precioVisible, tieneVariantes } from './variants';
import { unaRamConVariantes, unaVariante, unProducto } from '../test/fixtures';

describe('variants', () => {
  it('precioVisible convierte a número los decimales que la API manda como texto', () => {
    expect(precioVisible({ price: '120.00', sale_price: '99.90' })).toBe(99.9);
    expect(precioVisible({ price: '120.00', sale_price: null })).toBe(120);
  });

  it('una oferta a 0 sigue siendo oferta (no cae al precio regular)', () => {
    expect(precioVisible({ price: 50, sale_price: 0 })).toBe(0);
  });

  it('datosDeVenta usa la variante cuando hay una elegida', () => {
    const { producto, de32 } = unaRamConVariantes();

    expect(datosDeVenta(producto, de32)).toEqual({ price: 120, sale_price: 99.9, stock: 3, precio: 99.9 });
  });

  it('datosDeVenta sin variante usa el producto', () => {
    expect(datosDeVenta(unProducto({ price: 200, sale_price: null, stock: 7 }))).toEqual({
      price: 200,
      sale_price: null,
      stock: 7,
      precio: 200,
    });
  });

  it('tieneVariantes: lista vacía o ausente es "sin variantes"', () => {
    expect(tieneVariantes(unProducto())).toBe(false);
    expect(tieneVariantes(unProducto({ variants: [] }))).toBe(false);
    expect(tieneVariantes(unaRamConVariantes().producto)).toBe(true);
  });

  it('"Desde" solo cuando las variantes no cuestan lo mismo', () => {
    expect(precioEsDesde(unaRamConVariantes().producto)).toBe(true);

    const mismoPrecio = unProducto({
      variants: [
        unaVariante({ id: 'negro', price: '90.00' }),
        unaVariante({ id: 'blanco', price: 90 }),
      ],
    });
    expect(precioEsDesde(mismoPrecio)).toBe(false);
    expect(precioEsDesde(unProducto())).toBe(false);
  });

  it('claveDeLinea y nombreConVariante', () => {
    expect(claveDeLinea('p-1')).toBe('p-1');
    expect(claveDeLinea('p-1', null)).toBe('p-1');
    expect(claveDeLinea('p-1', 'v-1')).toBe('p-1:v-1');
    expect(nombreConVariante('Kingston Fury', '16 GB')).toBe('Kingston Fury (16 GB)');
    expect(nombreConVariante('Kingston Fury', null)).toBe('Kingston Fury');
  });
});
