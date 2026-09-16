import { describe, expect, it } from 'vitest';
import { margenDe, precioQueSeCobra } from './margen';

describe('margenDe (MOD-6)', () => {
  it('calcula la utilidad y el porcentaje sobre el precio de venta', () => {
    expect(margenDe(200, 120)).toEqual({ utilidad: 80, porcentaje: 40 });
  });

  it('acepta los números como texto, que es como llegan del backend y del formulario', () => {
    expect(margenDe('200.00', '120.00')).toEqual({ utilidad: 80, porcentaje: 40 });
  });

  it('sin costo no hay margen: null, no cero', () => {
    // Cero diría "no ganas nada"; null dice "no se sabe", que es lo que pasa.
    expect(margenDe(200, null)).toBeNull();
    expect(margenDe(200, undefined)).toBeNull();
    expect(margenDe(200, '')).toBeNull();
  });

  it('no divide por cero cuando el precio es cero', () => {
    expect(margenDe(0, 120)).toBeNull();
  });

  it('avisa de la venta a pérdida con una utilidad negativa', () => {
    expect(margenDe(100, 150)).toEqual({ utilidad: -50, porcentaje: -50 });
  });

  it('redondea a dos decimales', () => {
    expect(margenDe(99.99, 33.33)).toEqual({ utilidad: 66.66, porcentaje: 66.67 });
  });
});

describe('precioQueSeCobra', () => {
  it('manda la oferta cuando la hay, porque es lo que paga el cliente', () => {
    expect(precioQueSeCobra(200, 150)).toBe(150);
  });

  it('sin oferta, el precio normal', () => {
    expect(precioQueSeCobra(200, null)).toBe(200);
    expect(precioQueSeCobra('200', '')).toBe(200);
  });

  it('una oferta de cero no cuenta como oferta', () => {
    expect(precioQueSeCobra(200, 0)).toBe(200);
  });
});
