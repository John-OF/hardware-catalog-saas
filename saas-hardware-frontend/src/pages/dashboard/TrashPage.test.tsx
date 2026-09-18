import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TrashPage, { diasQueLeQuedan } from './TrashPage';
import { borrarDePapelera, getPapelera, restaurarDePapelera, vaciarPapelera } from '../../api/trash';

vi.mock('../../api/trash', () => ({
  getPapelera: vi.fn(),
  restaurarDePapelera: vi.fn(),
  borrarDePapelera: vi.fn(),
  vaciarPapelera: vi.fn(),
}));

vi.mock('react-hot-toast', () => ({
  default: { success: vi.fn(), error: vi.fn() },
  toast: { success: vi.fn(), error: vi.fn() },
}));

/** El corte que manda el servidor: hoy menos 30 días. */
const CORTE = '2026-08-18T00:00:00Z';

const respuesta = (tipo: 'productos' | 'pedidos', items: Record<string, unknown>[], totales = { productos: items.length, pedidos: 0 }) => ({
  tipo,
  items: { data: items, current_page: 1, last_page: 1, per_page: 20, total: items.length },
  totales,
  retencion: { dias: 30, corte: CORTE },
  tenant_timezone: 'America/Lima',
});

const producto = (extra: Record<string, unknown> = {}) => ({
  id: 'p-1',
  name: 'RTX 4070',
  sku: 'GPU-4070',
  price: 2800,
  deleted_at: '2026-09-15T10:00:00Z',
  ...extra,
});

const abrir = (ruta = '/dashboard/trash') =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[ruta]}>
        <TrashPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

describe('TrashPage (MOD-8)', () => {
  beforeEach(() => {
    vi.mocked(getPapelera).mockReset();
    vi.mocked(restaurarDePapelera).mockReset().mockResolvedValue(undefined);
    vi.mocked(borrarDePapelera).mockReset().mockResolvedValue(undefined);
    vi.mocked(vaciarPapelera).mockReset().mockResolvedValue({ productos: 1, pedidos: 0 });
  });

  it('enseña lo borrado y cuántos días le quedan', async () => {
    vi.mocked(getPapelera).mockResolvedValue(respuesta('productos', [producto()]) as never);
    abrir();

    expect(await screen.findByText('RTX 4070')).toBeInTheDocument();
    // Borrado el 15-09 a las 10:00 con corte el 18-08 a las 00:00: 28 días y
    // pico, que se redondean hacia arriba —el día en curso todavía cuenta—.
    expect(screen.getByText('Quedan 29 días')).toBeInTheDocument();
  });

  it('el tipo viene de la URL, para que el enlace se pueda guardar', async () => {
    vi.mocked(getPapelera).mockResolvedValue(
      respuesta('pedidos', [{ id: 'o-1', number: 12, customer_name: 'Ana', total: 400, deleted_at: '2026-09-16T10:00:00Z' }], { productos: 0, pedidos: 1 }) as never,
    );
    abrir('/dashboard/trash?tipo=pedidos');

    expect(await screen.findByText('Pedido #12')).toBeInTheDocument();
    expect(getPapelera).toHaveBeenCalledWith({ tipo: 'pedidos', page: 1 });
  });

  it('un tipo inventado en la URL cae en productos en vez de romperse', async () => {
    vi.mocked(getPapelera).mockResolvedValue(respuesta('productos', []) as never);
    abrir('/dashboard/trash?tipo=categorias');

    await waitFor(() => expect(getPapelera).toHaveBeenCalledWith({ tipo: 'productos', page: 1 }));
  });

  it('restaurar llama al backend con el tipo de la pestaña', async () => {
    vi.mocked(getPapelera).mockResolvedValue(respuesta('productos', [producto()]) as never);
    abrir();

    await userEvent.click(await screen.findByRole('button', { name: /restaurar/i }));

    await waitFor(() => expect(restaurarDePapelera).toHaveBeenCalledWith('productos', 'p-1'));
  });

  it('eliminar del todo pide confirmación y no borra si se cancela', async () => {
    vi.mocked(getPapelera).mockResolvedValue(respuesta('productos', [producto()]) as never);
    const confirmar = vi.spyOn(window, 'confirm').mockReturnValue(false);
    abrir();

    await userEvent.click(await screen.findByRole('button', { name: /^eliminar/i }));

    expect(confirmar).toHaveBeenCalled();
    expect(borrarDePapelera).not.toHaveBeenCalled();

    confirmar.mockReturnValue(true);
    await userEvent.click(screen.getByRole('button', { name: /^eliminar/i }));

    await waitFor(() => expect(borrarDePapelera).toHaveBeenCalledWith('productos', 'p-1'));
    confirmar.mockRestore();
  });

  it('el botón de vaciar solo aparece si hay algo que vaciar', async () => {
    vi.mocked(getPapelera).mockResolvedValue(respuesta('productos', [], { productos: 0, pedidos: 0 }) as never);
    abrir();

    expect(await screen.findByText('No hay productos borrados')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /vaciar papelera/i })).not.toBeInTheDocument();
  });

  it('las pestañas enseñan los dos totales aunque solo se haya pedido uno', async () => {
    vi.mocked(getPapelera).mockResolvedValue(respuesta('productos', [producto()], { productos: 1, pedidos: 4 }) as never);
    abrir();

    // Las pestañas se pintan antes de que llegue la respuesta, así que se espera
    // a la lista: si no, se leerían los ceros del primer render.
    await screen.findByText('RTX 4070');

    expect(screen.getByRole('tab', { name: /pedidos/i })).toHaveTextContent('4');
  });
});

describe('diasQueLeQuedan (MOD-8)', () => {
  it('cuenta contra el corte del servidor, no contra el reloj del navegador', () => {
    expect(diasQueLeQuedan('2026-09-17T00:00:00Z', '2026-08-18T00:00:00Z')).toBe(30);
    expect(diasQueLeQuedan('2026-08-19T00:00:00Z', '2026-08-18T00:00:00Z')).toBe(1);
  });

  it('lo ya caducado no enseña días negativos', () => {
    expect(diasQueLeQuedan('2026-08-01T00:00:00Z', '2026-08-18T00:00:00Z')).toBe(0);
  });
});
