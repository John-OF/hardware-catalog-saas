import { describe, expect, it } from 'vitest';
import {
  agregarMetodosAlFormulario,
  MAXIMO_DE_METODOS,
  metodoNuevo,
  metodosDesdeTienda,
  problemaDeMetodos,
  SUGERIDOS_POR_PAIS,
  sugeridosPara,
} from './metodosDePagoEnFormulario';
import { COUNTRY_CODES } from './phone';
import { unMetodo } from '../test/fixtures';

describe('métodos de pago en el formulario (MOD-23)', () => {
  it('cada país del selector de teléfono tiene sugerencias, y todas con nombre', () => {
    for (const { code } of COUNTRY_CODES) {
      expect(SUGERIDOS_POR_PAIS[code], code).toBeDefined();
      expect(SUGERIDOS_POR_PAIS[code].every((s) => s.name.trim() !== '')).toBe(true);
    }
  });

  it('sugiere los del país que la tienda aún no tiene, sin mirar mayúsculas', () => {
    const nombres = sugeridosPara('+51', [{ name: 'yape ' }]).map((s) => s.name);

    expect(nombres).toEqual(['Plin', 'Transferencia bancaria', 'Efectivo contra entrega']);
    expect(sugeridosPara('+593', []).map((s) => s.name)).toContain('Deuna');
  });

  it('un país que no está en la lista recibe transferencia y efectivo', () => {
    expect(sugeridosPara('+999', []).map((s) => s.name)).toEqual(['Transferencia bancaria', 'Efectivo contra entrega']);
  });

  it('lo guardado pasa al formulario con su id y su QR, y cada fila con su clave', () => {
    const filas = metodosDesdeTienda([
      unMetodo({ id: 'a1', name: 'Yape', details: '987654321', qr_url: 'https://cdn.test/qr.png' }),
      unMetodo({ id: 'b2', name: 'Plin', enabled: false }),
    ]);

    expect(filas.map((f) => [f.id, f.name, f.enabled, f.qrGuardado])).toEqual([
      ['a1', 'Yape', true, 'https://cdn.test/qr.png'],
      ['b2', 'Plin', false, null],
    ]);
    expect(new Set(filas.map((f) => f.clave)).size).toBe(2);
    // Lo de antes de MOD-23, que no es una lista, no rompe el formulario.
    expect(metodosDesdeTienda({} as never)).toEqual([]);
  });

  it('pide nombre y no deja pasar del máximo', () => {
    expect(problemaDeMetodos([metodoNuevo({ name: 'Yape', pista: '' })])).toBeNull();
    expect(problemaDeMetodos([metodoNuevo({ name: 'Yape', pista: '' }), metodoNuevo()])).toMatch(/método de pago 2/);

    const demasiados = Array.from({ length: MAXIMO_DE_METODOS + 1 }, (_, i) => metodoNuevo({ name: `M${i}`, pista: '' }));
    expect(problemaDeMetodos(demasiados)).toMatch(/hasta 8/);
  });

  it('manda la lista en orden, los booleanos como 1/0 y el QR por su posición', () => {
    const qr = new File(['x'], 'qr.png', { type: 'image/png' });
    const fd = new FormData();

    agregarMetodosAlFormulario(fd, [
      { ...metodoNuevo(), id: 'a1', name: ' Yape ', details: '987654321', enabled: false, quitarQr: true },
      { ...metodoNuevo(), name: 'Deuna', qrArchivo: qr },
    ]);
    const campos = Object.fromEntries(fd.entries());

    expect(campos['payment_methods[0][id]']).toBe('a1');
    expect(campos['payment_methods[0][name]']).toBe('Yape');
    expect(campos['payment_methods[0][enabled]']).toBe('0');
    expect(campos['payment_methods[0][remove_qr]']).toBe('1');
    // Uno nuevo no lleva id: el backend le pone uno.
    expect(campos['payment_methods[1][id]']).toBeUndefined();
    expect(campos['payment_methods[1][enabled]']).toBe('1');
    expect((campos['payment_method_qr[1]'] as File).name).toBe('qr.png');
    expect(campos['payment_method_qr[0]']).toBeUndefined();
  });

  it('una lista vacía se manda como payment_methods vacío: es lo que le dice al backend que los quite', () => {
    const fd = new FormData();
    agregarMetodosAlFormulario(fd, []);

    expect([...fd.entries()]).toEqual([['payment_methods', '']]);
  });
});
