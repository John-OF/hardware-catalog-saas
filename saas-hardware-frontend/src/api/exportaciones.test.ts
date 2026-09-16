import { beforeEach, describe, expect, it, vi } from 'vitest';
import api from './axios';
import { exportarCatalogo, exportarPedidos, nombreDeArchivo } from './exportaciones';

vi.mock('./axios', () => ({ default: { get: vi.fn() } }));

describe('exportaciones (MOD-7)', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset();
    vi.mocked(api.get).mockResolvedValue({
      data: new Blob(['nombre;precio\n'], { type: 'text/csv' }),
      headers: { 'content-disposition': 'attachment; filename=catalogo-mi-tienda-2026-09-15.csv' },
    });

    // jsdom no implementa ninguna de las dos, y sin ellas el propio `descargar`
    // reventaría antes de llegar a lo que se quiere comprobar.
    URL.createObjectURL = vi.fn(() => 'blob:falso');
    URL.revokeObjectURL = vi.fn();
  });

  it('pide el CSV como blob, que es lo que hace falta para guardarlo', async () => {
    await exportarCatalogo();

    expect(api.get).toHaveBeenCalledWith('/products/export', expect.objectContaining({ responseType: 'blob' }));
  });

  it('se lleva los filtros que el dueño tiene puestos en pantalla', async () => {
    await exportarCatalogo({ search: 'ryzen', category_id: 'cat-1' });

    expect(vi.mocked(api.get).mock.calls[0][1]?.params).toEqual({ search: 'ryzen', category_id: 'cat-1' });
  });

  it('no manda los filtros vacíos: `?search=` buscaría la cadena vacía', async () => {
    await exportarPedidos({ status: '', desde: undefined });

    expect(vi.mocked(api.get).mock.calls[0][1]?.params).toEqual({});
  });

  it('guarda el archivo con el nombre que manda el servidor', async () => {
    const click = vi.fn();
    const original = document.createElement.bind(document);

    vi.spyOn(document, 'createElement').mockImplementation((etiqueta: string) => {
      const elemento = original(etiqueta) as HTMLAnchorElement;
      if (etiqueta === 'a') elemento.click = click;
      return elemento;
    });

    await exportarCatalogo();

    expect(click).toHaveBeenCalled();
    // Y el blob se suelta: exportar cinco veces no deja cinco catálogos en memoria.
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:falso');

    vi.mocked(document.createElement).mockRestore();
  });
});

describe('nombreDeArchivo', () => {
  it('lo saca de la cabecera, con y sin comillas', () => {
    expect(nombreDeArchivo('attachment; filename=pedidos-tienda.csv', '/orders/export')).toBe('pedidos-tienda.csv');
    expect(nombreDeArchivo('attachment; filename="pedidos tienda.csv"', '/orders/export')).toBe('pedidos tienda.csv');
  });

  it('sin cabecera usa uno de repuesto según lo que se exportaba', () => {
    expect(nombreDeArchivo(undefined, '/orders/export')).toBe('pedidos.csv');
    expect(nombreDeArchivo(undefined, '/products/export')).toBe('catalogo.csv');
  });
});
