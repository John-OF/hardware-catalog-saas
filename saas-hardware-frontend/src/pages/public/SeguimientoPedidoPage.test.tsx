import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SeguimientoPedidoPage from './SeguimientoPedidoPage';
import { enviarComprobante, getPublicTenant, getSeguimiento } from '../../api/public';
import { unMetodo, unaTienda } from '../../test/fixtures';
import type { SeguimientoDePedido } from '../../types';

vi.mock('../../api/public', () => ({
  getPublicTenant: vi.fn(),
  resolveTenantDomain: vi.fn(),
  getSeguimiento: vi.fn(),
  enviarComprobante: vi.fn(),
}));

const TOKEN = 'A'.repeat(40);

const unSeguimiento = (datos: Partial<SeguimientoDePedido> = {}): SeguimientoDePedido => ({
  number: 47,
  status: 'pending',
  created_at: '2026-11-05T15:00:00Z',
  customer_name: 'Ana',
  items: [{ product_name: 'Mouse', variant_name: null, quantity: 2, unit_price: 50, subtotal: 100 }],
  items_subtotal: 100,
  discount_amount: null,
  coupon_code: null,
  delivery_method: 'pickup',
  delivery_cost: 0,
  tax_name: null,
  tax_rate: null,
  tax_amount: null,
  total: 100,
  payment: { status: null, proof_sent_at: null, rejection_reason: null, can_upload: true },
  ...datos,
});

const abrir = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[`/tienda-demo/pedido/${TOKEN}`]}>
        <Routes>
          <Route path="/:slug/pedido/:token" element={<SeguimientoPedidoPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

describe('SeguimientoPedidoPage (MOD-26)', () => {
  beforeEach(() => {
    vi.mocked(getPublicTenant).mockResolvedValue(
      unaTienda({ payment_methods: [unMetodo({ id: 'y', name: 'Yape', details: '987654321 · Ana' })] }),
    );
    vi.mocked(getSeguimiento).mockReset();
    vi.mocked(enviarComprobante).mockReset();
  });

  it('enseña el pedido, cómo pagar y deja mandar el comprobante', async () => {
    const user = userEvent.setup();
    vi.mocked(getSeguimiento).mockResolvedValue(unSeguimiento());
    vi.mocked(enviarComprobante).mockResolvedValue(
      unSeguimiento({ payment: { status: 'en_revision', proof_sent_at: '2026-11-05T16:00:00Z', rejection_reason: null, can_upload: true } }),
    );
    abrir();

    expect(await screen.findByText('Pedido #47')).toBeInTheDocument();
    expect(getSeguimiento).toHaveBeenCalledWith('tienda-demo', TOKEN);
    expect(screen.getByText('2 × Mouse')).toBeInTheDocument();
    expect(screen.getByText('Yape')).toBeInTheDocument();

    const boton = screen.getByRole('button', { name: /Enviar comprobante/ });
    expect(boton).toBeDisabled();

    await user.upload(screen.getByLabelText(/Manda tu comprobante/), new File(['x'], 'captura.png', { type: 'image/png' }));
    await user.click(boton);

    await waitFor(() => expect(enviarComprobante).toHaveBeenCalledWith('tienda-demo', TOKEN, expect.any(File)));
    expect(await screen.findByText(/La tienda lo está revisando/)).toBeInTheDocument();
  });

  it('con el comprobante rechazado dice por qué y deja mandar otro', async () => {
    vi.mocked(getSeguimiento).mockResolvedValue(
      unSeguimiento({ payment: { status: 'rechazado', proof_sent_at: null, rejection_reason: 'El monto no coincide', can_upload: true } }),
    );
    abrir();

    expect(await screen.findByText(/El monto no coincide/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Enviar comprobante/ })).toBeInTheDocument();
  });

  it('con el pago confirmado no pide nada más', async () => {
    vi.mocked(getSeguimiento).mockResolvedValue(
      unSeguimiento({ payment: { status: 'confirmado', proof_sent_at: null, rejection_reason: null, can_upload: false } }),
    );
    abrir();

    expect(await screen.findByText(/La tienda confirmó tu pago/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Enviar comprobante/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Yape')).not.toBeInTheDocument();
  });

  it('un enlace que no existe lo dice en vez de quedarse cargando', async () => {
    vi.mocked(getSeguimiento).mockRejectedValue(new Error('404'));
    abrir();

    expect(await screen.findByText('No encontramos este pedido')).toBeInTheDocument();
  });
});
