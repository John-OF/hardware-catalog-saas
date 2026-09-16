import { describe, expect, it } from 'vitest';
import { metodosDePagoActivos } from './paymentMethods';

describe('metodosDePagoActivos (MOD-3)', () => {
  it('sin nada guardado, ninguno', () => {
    expect(metodosDePagoActivos(null)).toEqual([]);
    expect(metodosDePagoActivos(undefined)).toEqual([]);
    expect(metodosDePagoActivos({})).toEqual([]);
  });

  it('un método apagado no sale, aunque tenga datos', () => {
    expect(metodosDePagoActivos({ yape: { enabled: false, phone: '987654321' } })).toEqual([]);
  });

  it('yape y plin muestran teléfono y titular', () => {
    const [info] = metodosDePagoActivos({ yape: { enabled: true, phone: '987654321', holder_name: 'Ana' } });
    expect(info).toEqual({ clave: 'yape', etiqueta: 'Yape', detalle: '987654321 · Ana' });
  });

  it('sin titular, el detalle es solo el teléfono', () => {
    const [info] = metodosDePagoActivos({ plin: { enabled: true, phone: '987654321' } });
    expect(info.detalle).toBe('987654321');
  });

  it('transferencia junta banco, cuenta y titular', () => {
    const [info] = metodosDePagoActivos({
      transferencia: { enabled: true, bank: 'BCP', account_number: '1937482910', holder_name: 'Ana Dueña' },
    });
    expect(info).toEqual({
      clave: 'transferencia',
      etiqueta: 'Transferencia bancaria',
      detalle: 'BCP 1937482910 · Ana Dueña',
    });
  });

  it('efectivo no tiene detalle', () => {
    const [info] = metodosDePagoActivos({ efectivo: { enabled: true } });
    expect(info).toEqual({ clave: 'efectivo', etiqueta: 'Efectivo contra entrega', detalle: '' });
  });

  it('devuelve solo los encendidos, en el orden fijo: yape, plin, transferencia, efectivo', () => {
    const activos = metodosDePagoActivos({
      efectivo: { enabled: true },
      yape: { enabled: true, phone: '1' },
      transferencia: { enabled: false, bank: 'BCP' },
      plin: { enabled: true, phone: '2' },
    });

    expect(activos.map((m) => m.clave)).toEqual(['yape', 'plin', 'efectivo']);
  });
});
