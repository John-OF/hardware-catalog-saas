import { describe, expect, it, vi } from 'vitest';
import { cuentasDePrueba, interpretarCuentasDePrueba } from './cuentasDePrueba';

/**
 * Las cuentas de prueba de los logins, sólo en desarrollo. Lo que se prueba
 * aquí es que una lista mal escrita no rompe el login: se queda con lo que
 * entiende y descarta el resto.
 */
describe('interpretarCuentasDePrueba', () => {
  it('lee las cuentas de los dos tipos', () => {
    const crudo = JSON.stringify([
      { tipo: 'plataforma', etiqueta: 'Operador', email: 'op@local', password: 'clave1' },
      { tipo: 'tienda', etiqueta: 'Demo', email: 'admin@demo.local', password: 'clave2' },
    ]);

    expect(interpretarCuentasDePrueba(crudo)).toEqual([
      { tipo: 'plataforma', etiqueta: 'Operador', email: 'op@local', password: 'clave1' },
      { tipo: 'tienda', etiqueta: 'Demo', email: 'admin@demo.local', password: 'clave2' },
    ]);
  });

  it('sin variable, o vacía, no hay cuentas', () => {
    expect(interpretarCuentasDePrueba(undefined)).toEqual([]);
    expect(interpretarCuentasDePrueba('')).toEqual([]);
    expect(interpretarCuentasDePrueba('   ')).toEqual([]);
  });

  it('un JSON mal escrito no rompe nada: se ignora y lo avisa en la consola', () => {
    const aviso = vi.spyOn(console, 'warn').mockImplementation(() => {});

    expect(interpretarCuentasDePrueba('[{ "tipo": "tienda", ')).toEqual([]);
    expect(aviso).toHaveBeenCalledOnce();

    aviso.mockRestore();
  });

  it('descarta las filas a las que les falta algo o traen un tipo que no existe', () => {
    const crudo = JSON.stringify([
      { tipo: 'cliente', email: 'a@local', password: 'x' },
      { tipo: 'tienda', email: '', password: 'x' },
      { tipo: 'tienda', email: 'b@local' },
      'no es un objeto',
      null,
      { tipo: 'tienda', email: 'c@local', password: 'x' },
    ]);

    expect(interpretarCuentasDePrueba(crudo)).toEqual([
      // Sin etiqueta, se enseña el correo.
      { tipo: 'tienda', etiqueta: 'c@local', email: 'c@local', password: 'x' },
    ]);
  });

  it('algo que no es una lista no da cuentas', () => {
    expect(interpretarCuentasDePrueba('{"tipo":"tienda"}')).toEqual([]);
  });
});

describe('cuentasDePrueba', () => {
  it('en la suite no hay ninguna: Vitest no define __CUENTAS_DE_PRUEBA__', () => {
    expect(cuentasDePrueba()).toEqual([]);
  });
});
