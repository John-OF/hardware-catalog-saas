import { beforeEach, describe, expect, it, vi } from 'vitest';
import api from './axios';
import { getCompatibilidad } from './public';

vi.mock('./axios', () => ({ default: { get: vi.fn() } }));

/**
 * El armador pregunta la compatibilidad al servidor (FUN-8, 13.5): las reglas
 * no tienen copia en el navegador. Lo que puede romperse aquí es la forma en
 * que viajan la selección (un objeto) y los candidatos (una lista) por la URL.
 */
describe('getCompatibilidad', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset();
    vi.mocked(api.get).mockResolvedValue({ data: {} });
  });

  it('pregunta a la ruta del armador de la tienda con la selección y el cajón', async () => {
    await getCompatibilidad('mi-tienda', { seleccion: { cpu: 'id-cpu' }, paso: 'motherboard', candidatos: ['a', 'b'] });

    expect(api.get).toHaveBeenCalledWith('/public/mi-tienda/builder/compatibility', {
      params: { seleccion: { cpu: 'id-cpu' }, paso: 'motherboard', candidatos: ['a', 'b'] },
    });
  });

  it('el cliente de verdad los escribe como los lee Laravel: seleccion[cpu]= y candidatos[]=', async () => {
    const { default: real } = await vi.importActual<typeof import('./axios')>('./axios');

    const url = decodeURIComponent(real.getUri({
      url: '/public/mi-tienda/builder/compatibility',
      params: { seleccion: { cpu: 'id-cpu', ram: 'id-ram' }, paso: 'ram', candidatos: ['a', 'b'] },
    }));

    expect(url).toContain('seleccion[cpu]=id-cpu');
    expect(url).toContain('seleccion[ram]=id-ram');
    expect(url).toContain('candidatos[]=a');
    expect(url).toContain('candidatos[]=b');
  });
});
