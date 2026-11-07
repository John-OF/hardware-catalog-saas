import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CustomersPage from './CustomersPage';
import { getCustomer, getCustomers, getEtiquetasDeClientes, updateCustomer } from '../../api/customers';
import { useAuthStore } from '../../stores/authStore';
import type { Cliente, PaginatedResponse, User } from '../../types';

vi.mock('../../api/customers', () => ({
  getCustomers: vi.fn(),
  getCustomer: vi.fn(),
  getEtiquetasDeClientes: vi.fn(),
  updateCustomer: vi.fn(),
  importarClientes: vi.fn(),
}));

const cliente = (datos: Partial<Cliente> = {}): Cliente => ({
  id: 'c-1',
  user_id: 'u-9',
  tiene_cuenta: true,
  name: 'Ana Compradora',
  email: 'ana@correo.test',
  phone: '987654321',
  notes: null,
  tags: [],
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

/** Alguien del panel con estos permisos (lo que manda el servidor en `user.permisos`). */
const conPermisos = (permisos: string[]) =>
  useAuthStore.setState({ user: { id: 'u-1', name: 'Dueña', email: 'd@x.com', role: 'staff', permisos } as unknown as User });

describe('CustomersPage (MOD-10, MOD-24)', () => {
  beforeEach(() => {
    vi.mocked(getCustomers).mockReset();
    vi.mocked(getCustomer).mockReset();
    vi.mocked(updateCustomer).mockReset();
    vi.mocked(getEtiquetasDeClientes).mockReset().mockResolvedValue([]);
    conPermisos(['clientes.ver']);
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

  it('explica el vacío cuando la tienda no tiene clientes', async () => {
    vi.mocked(getCustomers).mockResolvedValue(pagina([]));
    abrir();

    expect(await screen.findByText('Todavía no tienes clientes')).toBeInTheDocument();
  });

  it('MOD-24: quien compró sin cuenta sale igual, con su teléfono y un botón de WhatsApp', async () => {
    vi.mocked(getCustomers).mockResolvedValue(
      pagina([cliente({ user_id: null, tiene_cuenta: false, email: null, phone: '+51 987 654 321', tags: ['mayorista'] })]),
    );
    abrir();

    expect(await screen.findByText('Ana Compradora')).toBeInTheDocument();
    expect(screen.queryByText('Cuenta')).not.toBeInTheDocument();
    expect(screen.getByText('mayorista')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /WhatsApp/ })).toHaveAttribute('href', 'https://wa.me/51987654321');
  });

  it('MOD-24: sin permisos no salen ni importar ni exportar, y la ficha no deja escribir notas', async () => {
    const user = userEvent.setup();
    vi.mocked(getCustomers).mockResolvedValue(pagina([cliente({ notes: 'Nota de la tienda' })]));
    vi.mocked(getCustomer).mockResolvedValue({ customer: cliente({ notes: 'Nota de la tienda' }), orders: [] });
    abrir();

    await screen.findByText('Ana Compradora');
    expect(screen.queryByRole('button', { name: /Importar CSV/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Exportar CSV/ })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Ver ficha' }));
    expect(await screen.findByDisplayValue('Nota de la tienda')).toHaveAttribute('readonly');
    expect(screen.queryByRole('button', { name: /Guardar notas/ })).not.toBeInTheDocument();
  });

  it('MOD-24: con permiso, guarda las notas y las etiquetas nuevas', async () => {
    const user = userEvent.setup();
    conPermisos(['clientes.ver', 'clientes.gestionar']);
    vi.mocked(getCustomers).mockResolvedValue(pagina([cliente()]));
    vi.mocked(getCustomer).mockResolvedValue({ customer: cliente(), orders: [] });
    vi.mocked(updateCustomer).mockResolvedValue({ customer: cliente({ notes: 'Paga por Yape', tags: ['vip'] }), orders: [] });
    abrir();

    await screen.findByText('Ana Compradora');
    expect(screen.getByRole('button', { name: /Importar CSV/ })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Ver ficha' }));

    const notas = await screen.findByLabelText(/Notas/);
    await user.type(notas, 'Paga por Yape');
    await user.type(screen.getByLabelText('Nueva etiqueta'), 'vip{Enter}');
    await user.click(screen.getByRole('button', { name: /Guardar notas/ }));

    expect(updateCustomer).toHaveBeenCalledWith('c-1', { notes: 'Paga por Yape', tags: ['vip'] });
  });

  it('pide el orden que se elige, que es cómo se ve quién compra más', async () => {
    const user = userEvent.setup();
    vi.mocked(getCustomers).mockResolvedValue(pagina([cliente()]));
    abrir();

    await screen.findByText('Ana Compradora');
    await user.selectOptions(screen.getByRole('combobox', { name: 'Orden' }), 'gasto');

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
