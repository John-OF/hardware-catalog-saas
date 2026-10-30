import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ActivityPage from './ActivityPage';
import { getActividad } from '../../api/activity';
import type { ActividadDelPanel, PaginatedResponse } from '../../types';

vi.mock('../../api/activity', () => ({ getActividad: vi.fn() }));

/** MOD-21: el equipo del filtro viene con la propia actividad, no de `GET /users`. */
const personas = [
  { name: 'Dueña', email: 'duenia@tienda.test' },
  { name: 'Vendedor', email: 'vendedor@tienda.test' },
];

const pagina = (data: ActividadDelPanel[], extra: Partial<PaginatedResponse<ActividadDelPanel>> = {}) => ({
  data, current_page: 1, last_page: 1, per_page: 30, total: data.length, personas, ...extra,
});

const linea = (datos: Partial<ActividadDelPanel>): ActividadDelPanel => ({
  id: 'l-1',
  action: 'producto.editado',
  description: 'Editó «Ryzen 7»: precio 1500 → 1450.',
  actor_email: 'vendedor@tienda.test',
  actor_role: 'staff',
  actor: { id: 'u-2', name: 'Vendedor' },
  context: null,
  created_at: '2026-09-14T15:30:00Z',
  ...datos,
});

const abrir = (ruta = '/dashboard/activity') =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[ruta]}>
        <ActivityPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

describe('ActivityPage (INF-3)', () => {
  beforeEach(() => {
    vi.mocked(getActividad).mockReset();
  });

  it('enseña quién, en qué área y qué cambió', async () => {
    vi.mocked(getActividad).mockResolvedValue(pagina([linea({})]));
    abrir();

    expect(await screen.findByText('Editó «Ryzen 7»: precio 1500 → 1450.')).toBeInTheDocument();
    expect(screen.getByText('Colaborador')).toBeInTheDocument();
    expect(screen.getByText('Productos', { selector: '.activity-area' })).toBeInTheDocument();
  });

  it('dice con qué rol del equipo se hizo, el del día de la línea (MOD-21)', async () => {
    vi.mocked(getActividad).mockResolvedValue(
      pagina([linea({ context: { rol_del_actor: 'Caja' } })]),
    );
    abrir();

    expect(await screen.findByText('Caja')).toBeInTheDocument();
    expect(screen.queryByText('Colaborador')).not.toBeInTheDocument();
  });

  it('dice cuánto se guarda, con el número que manda el servidor (INF-20)', async () => {
    vi.mocked(getActividad).mockResolvedValue({ ...pagina([linea({})]), retencion_dias: 365 });
    abrir();

    expect(await screen.findByText(/Se guarda lo de los últimos 365 días; lo anterior se borra solo\./)).toBeInTheDocument();
  });

  it('dice cuándo la persona ya no está en el equipo, con el correo que quedó guardado', async () => {
    vi.mocked(getActividad).mockResolvedValue(pagina([linea({ actor: null, actor_email: 'exvendedor@tienda.test' })]));
    abrir();

    expect(await screen.findByText('exvendedor@tienda.test')).toBeInTheDocument();
    expect(screen.getByText(/ya no está en el equipo/)).toBeInTheDocument();
  });

  it('filtra por área y por persona, y vuelve a la primera página', async () => {
    const user = userEvent.setup();
    vi.mocked(getActividad).mockResolvedValue(pagina([linea({})], { last_page: 3, total: 70 }));
    abrir('/dashboard/activity?pagina=2');

    await screen.findByText('Página 1 de 3 · 70 en total');
    expect(getActividad).toHaveBeenLastCalledWith({ area: undefined, actor: undefined, page: 2 });

    await user.selectOptions(screen.getByLabelText('Filtrar por área'), 'pedido');
    await waitFor(() => expect(getActividad).toHaveBeenLastCalledWith({ area: 'pedido', actor: undefined, page: 1 }));

    await screen.findByRole('option', { name: 'Vendedor' });
    await user.selectOptions(screen.getByLabelText('Filtrar por persona'), 'vendedor@tienda.test');
    await waitFor(() =>
      expect(getActividad).toHaveBeenLastCalledWith({ area: 'pedido', actor: 'vendedor@tienda.test', page: 1 }),
    );
  });

  it('distingue "no hay nada todavía" de "nada con estos filtros"', async () => {
    vi.mocked(getActividad).mockResolvedValue(pagina([]));
    const { unmount } = abrir();
    expect(await screen.findByText('Todavía no hay actividad')).toBeInTheDocument();
    unmount();

    abrir('/dashboard/activity?area=equipo');
    expect(await screen.findByText('Nada con estos filtros')).toBeInTheDocument();
  });
});
