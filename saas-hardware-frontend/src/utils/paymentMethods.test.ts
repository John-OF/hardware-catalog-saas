import { describe, expect, it } from 'vitest';
import { metodosDePagoActivos } from './paymentMethods';
import { unMetodo } from '../test/fixtures';
import type { PaymentMethod } from '../types';

describe('metodosDePagoActivos (MOD-3, MOD-23)', () => {
  it('sin nada guardado, ninguno', () => {
    expect(metodosDePagoActivos(null)).toEqual([]);
    expect(metodosDePagoActivos(undefined)).toEqual([]);
    expect(metodosDePagoActivos([])).toEqual([]);
  });

  it('lo de antes de MOD-23 (un objeto, no una lista) cuenta como ninguno en vez de romper', () => {
    const antiguo = { yape: { enabled: true, phone: '987654321' } } as unknown as PaymentMethod[];
    expect(metodosDePagoActivos(antiguo)).toEqual([]);
  });

  it('un método apagado no sale, aunque tenga datos', () => {
    expect(metodosDePagoActivos([unMetodo({ enabled: false, details: '987654321' })])).toEqual([]);
  });

  it('uno sin nombre tampoco', () => {
    expect(metodosDePagoActivos([unMetodo({ name: '  ' })])).toEqual([]);
  });

  it('da nombre, datos, instrucciones y QR de cada uno, sin espacios de los bordes', () => {
    const [info] = metodosDePagoActivos([
      unMetodo({ id: 'k3', name: ' Deuna ', details: '0991234567 · Ana ', instructions: 'Manda la captura', qr_url: 'https://cdn.test/qr.png' }),
    ]);

    expect(info).toEqual({
      clave: 'k3',
      etiqueta: 'Deuna',
      detalle: '0991234567 · Ana',
      instrucciones: 'Manda la captura',
      qr: 'https://cdn.test/qr.png',
    });
  });

  it('sin datos ni instrucciones, textos vacíos y sin QR', () => {
    const [info] = metodosDePagoActivos([unMetodo({ name: 'Efectivo contra entrega' })]);
    expect(info).toMatchObject({ detalle: '', instrucciones: '', qr: null });
  });

  it('respeta el orden que eligió la tienda', () => {
    const activos = metodosDePagoActivos([
      unMetodo({ id: 'a', name: 'Efectivo' }),
      unMetodo({ id: 'b', name: 'Nequi', enabled: false }),
      unMetodo({ id: 'c', name: 'Transferencia' }),
    ]);

    expect(activos.map((m) => m.etiqueta)).toEqual(['Efectivo', 'Transferencia']);
  });
});
