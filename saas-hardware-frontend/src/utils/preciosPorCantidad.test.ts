import { describe, expect, it } from 'vitest';
import {
  desdeCuantasUnidades,
  precioPorCantidad,
  tramoPara,
  tramosDeVenta,
} from './preciosPorCantidad';
import type { PriceTier, Product, ProductVariant } from '../types';

/**
 * Precio por mayor en el navegador (MOD-15).
 *
 * Esto **no cobra**: cobra el servidor, y del navegador solo viaja la cantidad.
 * Lo que se vigila aquí es que el número que el comprador ve antes de confirmar
 * sea el mismo que le van a cobrar — si se separan, el carrito dice un total y
 * WhatsApp le llega otro.
 */

const tramos: PriceTier[] = [
  { min: 10, price: 90 },
  { min: 25, price: 80 },
];

describe('tramoPara', () => {
  it('manda el tramo más alto que la cantidad alcanza', () => {
    expect(tramoPara(tramos, 9)).toBeNull();
    expect(tramoPara(tramos, 10)).toEqual({ min: 10, price: 90 });
    expect(tramoPara(tramos, 24)).toEqual({ min: 10, price: 90 });
    expect(tramoPara(tramos, 25)).toEqual({ min: 25, price: 80 });
    expect(tramoPara(tramos, 1000)).toEqual({ min: 25, price: 80 });
  });

  it('no depende del orden en que lleguen', () => {
    // El backend los devuelve ordenados, pero apoyarse en eso deja el cálculo a
    // merced de que nadie cambie nunca el `ksort` de allá.
    expect(tramoPara([tramos[1], tramos[0]], 30)).toEqual({ min: 25, price: 80 });
  });

  it('sin tramos no hay ninguno', () => {
    expect(tramoPara(null, 50)).toBeNull();
    expect(tramoPara(undefined, 50)).toBeNull();
    expect(tramoPara([], 50)).toBeNull();
  });
});

describe('precioPorCantidad', () => {
  it('cobra el precio del tramo al alcanzarlo', () => {
    expect(precioPorCantidad(100, tramos, 9)).toBe(100);
    expect(precioPorCantidad(100, tramos, 10)).toBe(90);
    expect(precioPorCantidad(100, tramos, 25)).toBe(80);
  });

  it('nunca cobra más que el precio normal', () => {
    // Con una oferta de 70 por debajo del precio por mayor de 90, quien compra
    // diez no puede pagar más que quien compra una. Es la misma red que pone
    // `App\Support\PreciosPorCantidad` en el servidor.
    expect(precioPorCantidad(70, tramos, 10)).toBe(70);
  });

  it('sin tramos deja el precio como estaba', () => {
    expect(precioPorCantidad(100, null, 50)).toBe(100);
  });
});

describe('tramosDeVenta', () => {
  const producto = { price_tiers: [{ min: 5, price: 95 }] } as unknown as Product;
  const variante = { price_tiers: [{ min: 5, price: 50 }] } as unknown as ProductVariant;

  it('con variante elegida manda la variante', () => {
    // MOD-5: con variantes los de la ficha son el resumen de la más barata y no
    // son los que se cobran. Leerlos aquí enseñaría el precio de otra variante.
    expect(tramosDeVenta(producto, variante)).toEqual([{ min: 5, price: 50 }]);
  });

  it('sin variante, los de la ficha', () => {
    expect(tramosDeVenta(producto, null)).toEqual([{ min: 5, price: 95 }]);
  });

  it('devuelve null cuando no hay ninguno', () => {
    expect(tramosDeVenta({} as Product, null)).toBeNull();
  });
});

describe('desdeCuantasUnidades', () => {
  it('dice el escalón más bajo, para poder anunciarlo sin pintar la tabla', () => {
    expect(desdeCuantasUnidades(tramos)).toBe(10);
    expect(desdeCuantasUnidades(null)).toBeNull();
    expect(desdeCuantasUnidades([])).toBeNull();
  });
});
