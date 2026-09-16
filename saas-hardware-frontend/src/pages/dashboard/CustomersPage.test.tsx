import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CustomersPage from './CustomersPage';
import { getCustomer, getCustomers } from '../../api/customers';
import type { Cliente, PaginatedResponse } from '../../types';

vi.mock('../../api/customers', () => ({ getCustomers: vi.fn(), getCustomer: vi.fn() }));

const cliente = (datos: Partial<Cliente> = {}): Cliente => ({
  id: 'c-1',
  name: 'Ana Compradora',
  email: 'ana@correo.test',
  phone: '987654321',
  is_active: true,
  created_at: '2026-03-01T10:00:00Z',
  pedidos_count: 3,
  total_gastado: 1250,
  ultima_compra: '2026-09-10T18:00:00Z',
  favoritos_count: 2,
  ...datos,
});

const pagina = (data: Cliente[], extra: Partial<PaginatedResponse<Cliente>> = {}) => ({
  data, current_page: 1, last_page: 1, per_page: 20, total: data.length, ...extra,
}) as PaginatedResponse<Cliente>;

const abrir = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <CustomersPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

describe('CustomersPage (MOD-10)', () => {
  beforeEach(() => {
    vi.mocked(getCustomers).mockReset();
    vi.mocked(getCustomer).mockReset();
  });

  it('enseña al cliente con lo que lleva comprado', async () => {
    vi.mocked(getCustomers).mockResolvedValue(pagina([cliente()]));
    abrir();

    expect(await screen.findByText('Ana Compradora')).toBeInTheDocument();
    expect(screen.getByText('ana@correo.test')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('a quien se registró y aún no ha comprado lo dice, en vez de dejar la celda vacía', async () => {
    vi.mocked(getCustomers).mockResolvedValue(
      pagina([cliente({ pedidos_count: 0, total_gastado: 0, ultima_compra: null })]),
    );
    abrir();

    expect(await screen.findByText('Sin compras todavía')).toBeInTheDocument();
  });

  it('explica el vacío cuando la tienda no tiene clientes registrados', async () => {
    vi.mocked(getCustomers).mockResolvedValue(pagina([]));
    abrir();

    expect(await screen.findByText('Todavía no hay clientes registrados')).toBeInTheDocument();
  });

  it('pide el orden que se elige, que es cómo se ve quién compra más', async () => {
    const user = userEvent.setup();
    vi.mocked(getCustomers).mockResolvedValue(pagina([cliente()]));
    abrir();

    await screen.findByText('Ana Compradora');
    await user.selectOptions(screen.getByRole('combobox'), 'gasto');

    expect(getCustomers).toHaveBeenLastCalledWith(expect.objectContaining({ sort: 'gasto' }));
  });

  it('la ficha pide el historial solo al abrirla', async () => {
    const user = userEvent.setup();
    vi.mocked(getCustomers).mockResolvedValue(pagina([cliente()]));
    vi.mocked(getCustomer).mockResolvedValue({
      customer: cliente(),
      orders: [
        { id: 'o-1', number: 1042, status: 'attended', total: 500, created_at: '2026-09-10T18:00:00Z', items_count: 2 },
      ],
    });
    abrir();

    await screen.findByText('Ana Compradora');
    expect(getCustomer).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Ver ficha' }));

    expect(await screen.findByText('#1042')).toBeInTheDocument();
    expect(getCustomer).toHaveBeenCalledWith('c-1');
  });
});
