import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'react-hot-toast';
import CartDrawer from './CartDrawer';
import { createPublicOrder } from '../../api/public';
import { useCartStore } from '../../stores/cartStore';
import { useCustomerAuthStore } from '../../stores/customerAuthStore';
import { unaRamConVariantes, unaTienda, unProducto } from '../../test/fixtures';
import type { Order } from '../../types';

vi.mock('../../api/public', () => ({ createPublicOrder: vi.fn() }));
vi.mock('react-hot-toast', () => {
  const toast = { success: vi.fn(), error: vi.fn() };
  return { toast, default: toast };
});

const pedidoCreado = (number: number) => ({ id: 'o-1', number }) as Order;

const abrirCarrito = (onClose = vi.fn()) => {
  render(<CartDrawer open onClose={onClose} slug="tienda-demo" tenant={unaTienda()} />);
  return { onClose };
};

/** Un CPU sin variantes y dos capacidades de la misma RAM. */
const llenarCarrito = () => {
  const { producto, de16, de32 } = unaRamConVariantes();
  const cart = useCartStore.getState();
  cart.addItem('tienda-demo', unProducto({ id: 'p-cpu', name: 'Intel Core i5-13400F', price: 200 }));
  cart.addItem('tienda-demo', producto, 1, de16);
  cart.addItem('tienda-demo', producto, 2, de32);
};

const rellenarDatos = async (user: ReturnType<typeof userEvent.setup>) => {
  await user.type(screen.getByPlaceholderText('Tu nombre'), 'Ana Compradora');
  await user.type(screen.getByPlaceholderText('Tu WhatsApp / teléfono'), '987-654-321');
};

describe('CartDrawer (checkout)', () => {
  beforeEach(() => {
    useCartStore.setState({ slug: null, items: [] });
    useCustomerAuthStore.setState({ user: null, token: null, isAuthenticated: false });
    vi.mocked(createPublicOrder).mockReset();
    vi.mocked(toast.success).mockReset();
    vi.mocked(toast.error).mockReset();
  });

  it('con el carrito vacío no enseña el formulario', () => {
    abrirCarrito();

    expect(screen.getByText('Tu pedido está vacío.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Enviar pedido/ })).not.toBeInTheDocument();
  });

  it('enseña el total con el precio de cada variante y su oferta', () => {
    llenarCarrito();
    abrirCarrito();

    // 200 + 60 + 2 × 99.90
    expect(screen.getByText('Total').nextElementSibling).toHaveTextContent('$459.80');
    expect(screen.getByText('32 GB')).toBeInTheDocument();
  });

  it('manda el pedido con la variante de cada línea y abre WhatsApp con el número del pedido', async () => {
    const user = userEvent.setup();
    const abrir = vi.spyOn(window, 'open').mockReturnValue(null);
    vi.mocked(createPublicOrder).mockResolvedValue(pedidoCreado(42));
    llenarCarrito();
    const { onClose } = abrirCarrito();

    await rellenarDatos(user);
    await user.type(screen.getByPlaceholderText(/Nota/), 'Recojo en tienda');
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    await waitFor(() => expect(onClose).toHaveBeenCalled());

    expect(createPublicOrder).toHaveBeenCalledWith('tienda-demo', {
      customer_name: 'Ana Compradora',
      // El prefijo sale del WhatsApp de la tienda (+51) y el número se queda en dígitos.
      customer_phone: '+51987654321',
      // Opcional: sin escribirlo no se manda (FUN-2).
      customer_email: undefined,
      customer_note: 'Recojo en tienda',
      items: [
        { product_id: 'p-cpu', variant_id: null, quantity: 1 },
        { product_id: 'p-ram', variant_id: 'v-16', quantity: 1 },
        { product_id: 'p-ram', variant_id: 'v-32', quantity: 2 },
      ],
    });

    expect(abrir).toHaveBeenCalledTimes(1);
    const url = new URL(abrir.mock.calls[0][0] as string);
    expect(`${url.origin}${url.pathname}`).toBe('https://wa.me/51999888777');
    const mensaje = url.searchParams.get('text');
    expect(mensaje).toContain('Hola Tienda Demo');
    expect(mensaje).toContain('2 x Kingston Fury (32 GB) — $199.80');
    expect(mensaje).toContain('*Total: $459.80*');
    expect(mensaje).toContain('Nota: Recojo en tienda');
    expect(mensaje).toContain('(Pedido #42)');

    expect(useCartStore.getState().items).toEqual([]);
    expect(toast.success).toHaveBeenCalled();
  });

  it('manda el correo cuando se escribe', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'open').mockReturnValue(null);
    vi.mocked(createPublicOrder).mockResolvedValue(pedidoCreado(1));
    llenarCarrito();
    abrirCarrito();

    await rellenarDatos(user);
    await user.type(screen.getByPlaceholderText('Tu correo (opcional)'), '  ana@correo.test ');
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    await waitFor(() => expect(createPublicOrder).toHaveBeenCalled());
    expect(vi.mocked(createPublicOrder).mock.calls[0][1].customer_email).toBe('ana@correo.test');
  });

  it('si el backend rechaza el pedido, enseña su mensaje y no pierde el carrito ni abre WhatsApp', async () => {
    const user = userEvent.setup();
    const abrir = vi.spyOn(window, 'open').mockReturnValue(null);
    vi.mocked(createPublicOrder).mockRejectedValue({
      response: { status: 422, data: { message: 'No hay stock suficiente de Kingston Fury (32 GB).' } },
    });
    llenarCarrito();
    const { onClose } = abrirCarrito();

    await rellenarDatos(user);
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('No hay stock suficiente de Kingston Fury (32 GB).'));
    expect(abrir).not.toHaveBeenCalled();
    expect(onClose).not.toHaveBeenCalled();
    expect(useCartStore.getState().items).toHaveLength(3);
    expect(screen.getByPlaceholderText('Tu nombre')).toHaveValue('Ana Compradora');
    expect(screen.getByRole('button', { name: /Enviar pedido/ })).toBeEnabled();
  });

  it('sin respuesta del servidor dice un texto genérico en vez de callarse', async () => {
    const user = userEvent.setup();
    vi.mocked(createPublicOrder).mockRejectedValue(new Error('Network Error'));
    llenarCarrito();
    abrirCarrito();

    await rellenarDatos(user);
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('No se pudo enviar el pedido. Intenta de nuevo.'));
  });

  it('bloquea el botón mientras se envía, para no crear el pedido dos veces', async () => {
    const user = userEvent.setup();
    vi.mocked(createPublicOrder).mockReturnValue(new Promise(() => {}));
    llenarCarrito();
    abrirCarrito();

    await rellenarDatos(user);
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    const boton = await screen.findByRole('button', { name: /Enviando/ });
    expect(boton).toBeDisabled();
    await user.click(boton);
    expect(createPublicOrder).toHaveBeenCalledTimes(1);
  });

  it('no envía sin nombre ni teléfono', async () => {
    const user = userEvent.setup();
    llenarCarrito();
    abrirCarrito();

    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    expect(createPublicOrder).not.toHaveBeenCalled();
  });

  it('rellena nombre, prefijo, teléfono y correo del cliente con sesión', () => {
    useCustomerAuthStore.setState({
      isAuthenticated: true,
      token: 'tok',
      user: { id: 'c-1', name: 'Beto Cliente', email: 'beto@correo.test', phone: '+593991234567', role: 'customer', is_active: true },
    });
    llenarCarrito();
    abrirCarrito();

    expect(screen.getByPlaceholderText('Tu nombre')).toHaveValue('Beto Cliente');
    expect(screen.getByRole('combobox')).toHaveValue('+593');
    expect(screen.getByPlaceholderText('Tu WhatsApp / teléfono')).toHaveValue('991234567');
    expect(screen.getByPlaceholderText('Tu correo (opcional)')).toHaveValue('beto@correo.test');
  });

  it('los botones de cantidad cambian la línea correcta y bajar a cero la quita', async () => {
    const user = userEvent.setup();
    llenarCarrito();
    abrirCarrito();

    const lineaDe16 = screen.getByText('16 GB').closest('.cart-item') as HTMLElement;
    await user.click(within(lineaDe16).getByRole('button', { name: 'Más' }));
    expect(useCartStore.getState().items.find((i) => i.variant?.id === 'v-16')?.quantity).toBe(2);

    const lineaDe32 = screen.getByText('32 GB').closest('.cart-item') as HTMLElement;
    await user.click(within(lineaDe32).getByRole('button', { name: 'Quitar' }));
    expect(screen.queryByText('32 GB')).not.toBeInTheDocument();

    await user.click(within(lineaDe16).getByRole('button', { name: 'Menos' }));
    await user.click(within(lineaDe16).getByRole('button', { name: 'Menos' }));
    expect(screen.queryByText('16 GB')).not.toBeInTheDocument();
    expect(useCartStore.getState().items.map((i) => i.product.id)).toEqual(['p-cpu']);
  });
});
