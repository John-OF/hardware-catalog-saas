import { describe, expect, it } from 'vitest';
import {
  agregarVariantesAlFormulario,
  problemaDeVariantes,
  varianteVacia,
  variantesDesdeProducto,
  type VarianteEnFormulario,
} from './variantesEnFormulario';
import { unaRamConVariantes, unaVariante } from '../test/fixtures';

const fila = (datos: Partial<VarianteEnFormulario>): VarianteEnFormulario => ({
  ...varianteVacia(['Capacidad']),
  valores: ['16 GB'],
  price: '60',
  stock: '5',
  ...datos,
});

describe('problemaDeVariantes', () => {
  it('sin variantes no hay nada que revisar', () => {
    expect(problemaDeVariantes([], [])).toBeNull();
  });

  it('una lista correcta pasa', () => {
    expect(problemaDeVariantes(['Capacidad'], [fila({}), fila({ valores: ['32 GB'], price: '120', sale_price: '99' })])).toBeNull();
  });

  it('pide nombre para cada opción', () => {
    expect(problemaDeVariantes([' '], [fila({})])).toMatch(/nombre a cada opción/);
  });

  it('pide el valor de cada opción', () => {
    expect(problemaDeVariantes(['Capacidad'], [fila({ valores: [''] })])).toBe('A la variante 1 le falta el valor de alguna opción.');
  });

  it('pide precio y stock (el fallo que encontró la prueba a mano de MOD-5)', () => {
    expect(problemaDeVariantes(['Capacidad'], [fila({ price: '' })])).toBe('A la variante 1 le falta el precio.');
    expect(problemaDeVariantes(['Capacidad'], [fila({}), fila({ valores: ['32 GB'], stock: '' })])).toBe('A la variante 2 le falta el stock.');
  });

  it('acepta precio y stock a 0', () => {
    expect(problemaDeVariantes(['Capacidad'], [fila({ price: '0', stock: '0' })])).toBeNull();
  });

  it('rechaza una oferta igual o mayor que el precio', () => {
    expect(problemaDeVariantes(['Capacidad'], [fila({ price: '60', sale_price: '60' })])).toMatch(/oferta debe ser menor/);
  });

  it('rechaza dos variantes con las mismas opciones, sin importar mayúsculas ni espacios', () => {
    expect(problemaDeVariantes(['Capacidad'], [fila({ valores: ['16 GB'] }), fila({ valores: [' 16 gb '] })])).toBe(
      'La variante 2 repite las mismas opciones que otra.',
    );
  });
});

describe('agregarVariantesAlFormulario', () => {
  it('manda la lista en JSON, con oferta vacía como null y las fotos por posición', () => {
    const foto = new File(['x'], 'rojo.png', { type: 'image/png' });
    const formData = new FormData();

    agregarVariantesAlFormulario(formData, [' Capacidad '], [
      fila({ id: 'v-16', valores: [' 16 GB '], sku: '  ', sale_price: '' }),
      fila({ valores: ['32 GB'], price: '120', sale_price: '99.9', low_stock_threshold: '', imagen: foto }),
    ]);

    expect(JSON.parse(formData.get('variants') as string)).toEqual([
      {
        id: 'v-16',
        options: [{ name: 'Capacidad', value: '16 GB' }],
        sku: null,
        price: '60',
        sale_price: null,
        price_tiers: [],
        stock: '5',
        low_stock_threshold: '5',
        remove_image: false,
      },
      {
        options: [{ name: 'Capacidad', value: '32 GB' }],
        sku: null,
        price: '120',
        sale_price: '99.9',
        price_tiers: [],
        stock: '5',
        low_stock_threshold: '5',
        remove_image: false,
      },
    ]);
    expect(formData.get('variant_images[0]')).toBeNull();
    expect((formData.get('variant_images[1]') as File).name).toBe('rojo.png');
  });

  it('manda la lista vacía: es lo que le dice al backend que quite las variantes', () => {
    const formData = new FormData();
    agregarVariantesAlFormulario(formData, [], []);

    expect(formData.get('variants')).toBe('[]');
  });
});

describe('variantesDesdeProducto', () => {
  it('saca los ejes de las opciones y deja vacío el valor que le falte a una variante', () => {
    const { de16 } = unaRamConVariantes();
    const conColor = unaVariante({
      id: 'v-rgb',
      options: [{ name: 'Capacidad', value: '32 GB' }, { name: 'Color', value: 'RGB' }],
      sale_price: '55.5',
    });

    const { ejes, filas } = variantesDesdeProducto([de16, conColor]);

    expect(ejes).toEqual(['Capacidad', 'Color']);
    expect(filas[0].valores).toEqual(['16 GB', '']);
    expect(filas[1].valores).toEqual(['32 GB', 'RGB']);
    expect(filas[0].sale_price).toBe('');
    expect(filas[1].sale_price).toBe('55.5');
    // Y al volver a guardar sin tocar, el formulario pide el valor que falta.
    expect(problemaDeVariantes(ejes, filas)).toBe('A la variante 1 le falta el valor de alguna opción.');
  });
});
