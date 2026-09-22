import { describe, expect, it } from 'vitest';
import {
  problemaDeTramos,
  tramosDesdeProducto,
  tramosParaGuardar,
  type TramoEnFormulario,
} from './tramosEnFormulario';

/**
 * Los tramos mientras se editan en el panel (MOD-15).
 *
 * Las reglas son las mismas que comprueba `ValidaTramosDePrecio` en el
 * servidor. Se repiten aquí para poder decir "el tramo 2" en vez de dejar que
 * el 422 hable de `price_tiers.1.price`, y por eso lo que importa es que las dos
 * listas de reglas no se separen.
 */

const tramo = (extra: Partial<TramoEnFormulario> = {}): TramoEnFormulario => ({
  clave: `t-${Math.random()}`,
  min: '10',
  price: '90',
  ...extra,
});

describe('problemaDeTramos', () => {
  it('acepta una lista vacía: el precio por mayor es opcional', () => {
    expect(problemaDeTramos([], '100')).toBeNull();
  });

  it('acepta tramos que bajan de precio al subir la cantidad', () => {
    expect(problemaDeTramos([tramo(), tramo({ min: '25', price: '80' })], '100')).toBeNull();
  });

  it('rechaza una fila a medias', () => {
    expect(problemaDeTramos([tramo({ price: '' })], '100')).toMatch(/le falta la cantidad o el precio/);
  });

  it('rechaza un tramo que empieza en una unidad', () => {
    // Un tramo "desde 1" no es precio por mayor: es el precio, y para eso está
    // la oferta. Dos sitios diciendo lo mismo acaban no coincidiendo.
    expect(problemaDeTramos([tramo({ min: '1' })], '100')).toMatch(/2 unidades o más/);
  });

  it('rechaza un tramo que no baja del precio normal', () => {
    // No llegaría a aplicarse nunca, así que guardarlo dejaría al dueño
    // creyendo que hizo algo.
    expect(problemaDeTramos([tramo({ price: '120' })], '100')).toMatch(/menor que el precio normal/);
    expect(problemaDeTramos([tramo({ price: '100' })], '100')).toMatch(/menor que el precio normal/);
  });

  it('no compara contra el precio cuando todavía no se escribió', () => {
    expect(problemaDeTramos([tramo()], '')).toBeNull();
  });

  it('rechaza dos tramos para la misma cantidad', () => {
    expect(problemaDeTramos([tramo(), tramo({ price: '85' })], '100')).toMatch(/dos tramos que empiezan en 10/);
  });

  it('rechaza un tramo que cuesta más que el anterior', () => {
    expect(problemaDeTramos([tramo(), tramo({ min: '25', price: '95' })], '100'))
      .toMatch(/a más unidades, menos precio/);
  });

  it('no le importa el orden en que el dueño los escribió', () => {
    // El formulario los manda como se escribieron; la regla solo significa algo
    // sobre la lista ordenada por cantidad.
    expect(problemaDeTramos([tramo({ min: '25', price: '80' }), tramo()], '100')).toBeNull();
  });
});

describe('tramosParaGuardar', () => {
  it('manda números ordenados por cantidad', () => {
    expect(tramosParaGuardar([tramo({ min: '25', price: '80' }), tramo()])).toEqual([
      { min: 10, price: 90 },
      { min: 25, price: 80 },
    ]);
  });

  it('deja fuera las filas a medias', () => {
    // La fila recién añadida está vacía hasta que se escribe; mandarla haría
    // fallar la validación del servidor por algo que el dueño no puso.
    expect(tramosParaGuardar([tramo(), tramo({ min: '', price: '' })])).toEqual([{ min: 10, price: 90 }]);
  });
});

describe('tramosDesdeProducto', () => {
  it('pasa lo guardado a texto para los campos del formulario', () => {
    expect(tramosDesdeProducto([{ min: 10, price: 90.5 }])).toEqual([
      expect.objectContaining({ min: '10', price: '90.5' }),
    ]);
  });

  it('sin tramos, ninguna fila', () => {
    expect(tramosDesdeProducto(null)).toEqual([]);
    expect(tramosDesdeProducto(undefined)).toEqual([]);
  });
});
