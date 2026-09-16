import { beforeEach, describe, expect, it, vi } from 'vitest';
import { updateTenant } from './tenant';
import api from './axios';

vi.mock('./axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

/**
 * Cómo se arma el `FormData` de `PUT /tenant` (MOD-3, MOD-1).
 *
 * Existe porque un booleano en FormData no es lo mismo que un booleano en
 * JSON: la regla `boolean` de Laravel acepta '1'/'0' pero no '' (al revés
 * que un campo de texto, que sí la acepta vía `ConvertEmptyStringsToNull`).
 * Un test contra `putJson` en el backend no lo habría cazado —manda JSON de
 * verdad—, así que esto se prueba aquí, donde de verdad se arma el FormData.
 */
describe('updateTenant (FormData)', () => {
  beforeEach(() => {
    vi.mocked(api.post).mockReset().mockResolvedValue({ data: {} });
  });

  const camposDe = async (payload: Parameters<typeof updateTenant>[0]) => {
    await updateTenant(payload);
    // La última llamada, no la primera: algún test manda más de un payload.
    const llamadas = vi.mocked(api.post).mock.calls;
    const fd = llamadas[llamadas.length - 1][1] as FormData;
    return Object.fromEntries(fd.entries());
  };

  it('un método de pago activado manda enabled="1" y sus datos', async () => {
    const campos = await camposDe({
      payment_methods: { yape: { enabled: true, phone: '987654321', holder_name: 'Ana' } },
    });

    expect(campos['payment_methods[yape][enabled]']).toBe('1');
    expect(campos['payment_methods[yape][phone]']).toBe('987654321');
    expect(campos['payment_methods[yape][holder_name]']).toBe('Ana');
  });

  it('un método apagado manda enabled="0", no cadena vacía', async () => {
    const campos = await camposDe({ payment_methods: { plin: { enabled: false } } });

    // "" fallaría la regla `boolean` de Laravel: apagar un método no debe
    // convertirse en un 422 al guardar.
    expect(campos['payment_methods[plin][enabled]']).toBe('0');
  });

  it('delivery_enabled manda "1"/"0" y nunca una cadena vacía', async () => {
    expect((await camposDe({ delivery_enabled: true }))['delivery_enabled']).toBe('1');
    expect((await camposDe({ delivery_enabled: false }))['delivery_enabled']).toBe('0');
  });

  it('delivery_cost viaja como texto', async () => {
    const campos = await camposDe({ delivery_cost: 12.5 });
    expect(campos['delivery_cost']).toBe('12.5');
  });

  it('sin payment_methods ni delivery_* en el payload, no se mandan esos campos', async () => {
    const campos = await camposDe({ name: 'Tienda' });

    expect(campos['name']).toBe('Tienda');
    expect(campos['delivery_enabled']).toBeUndefined();
    expect(campos['delivery_cost']).toBeUndefined();
    expect(Object.keys(campos).some((k) => k.startsWith('payment_methods'))).toBe(false);
  });
});
