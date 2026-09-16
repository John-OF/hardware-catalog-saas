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

// `total` es el que de verdad manda: el mensaje de WhatsApp lo usa a él y no
// recalcula el carrito en el cliente, para no desalinearse de lo que cobró
// el servidor (por ejemplo, con envío sumado — MOD-1).
const pedidoCreado = (number: number, total = 0) => ({ id: 'o-1', number, total }) as Order;

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
    vi.mocked(createPublicOrder).mockResolvedValue(pedidoCreado(42, 459.8));
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

  // -------------------------------------------------------- MOD-1: envío

  it('sin envío activado no hay nada que elegir y no se manda delivery_method', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'open').mockReturnValue(null);
    vi.mocked(createPublicOrder).mockResolvedValue(pedidoCreado(1, 200));
    useCartStore.getState().addItem('tienda-demo', unProducto({ price: 200 }));
    render(<CartDrawer open onClose={vi.fn()} slug="tienda-demo" tenant={unaTienda({ delivery_enabled: false })} />);

    expect(screen.queryByText('Entrega')).not.toBeInTheDocument();

    await rellenarDatos(user);
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    await waitFor(() => expect(createPublicOrder).toHaveBeenCalled());
    expect(vi.mocked(createPublicOrder).mock.calls[0][1].delivery_method).toBeUndefined();
  });

  it('con envío activado, "recojo" es la opción de entrada y no suma nada', () => {
    llenarCarrito();
    render(<CartDrawer open onClose={vi.fn()} slug="tienda-demo" tenant={unaTienda({ delivery_enabled: true, delivery_cost: 15 })} />);

    expect(screen.getByRole('radio', { name: /Recojo en tienda/ })).toBeChecked();
    expect(screen.getByText('Total').nextElementSibling).toHaveTextContent('$459.80');
  });

  it('elegir delivery suma el costo al total y lo manda al crear el pedido', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'open').mockReturnValue(null);
    // El total que de verdad cobra el servidor: 459.80 + 15 de envío.
    vi.mocked(createPublicOrder).mockResolvedValue(pedidoCreado(7, 474.8));
    llenarCarrito();
    render(<CartDrawer open onClose={vi.fn()} slug="tienda-demo" tenant={unaTienda({ delivery_enabled: true, delivery_cost: 15 })} />);

    await user.click(screen.getByRole('radio', { name: /Delivery/ }));
    expect(screen.getByText('Total').nextElementSibling).toHaveTextContent('$474.80');

    await rellenarDatos(user);
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    await waitFor(() => expect(createPublicOrder).toHaveBeenCalled());
    expect(vi.mocked(createPublicOrder).mock.calls[0][1].delivery_method).toBe('delivery');

    const url = new URL(vi.mocked(window.open).mock.calls[0][0] as string);
    const mensaje = url.searchParams.get('text');
    expect(mensaje).toContain('Entrega: Delivery ($15.00)');
    // El total del mensaje es el del pedido creado, no un recálculo local.
    expect(mensaje).toContain('*Total: $474.80*');
  });

  // ---------------------------------------------------- MOD-3: métodos de pago

  it('sin métodos de pago activos, no se muestra el bloque ni se menciona en el mensaje', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'open').mockReturnValue(null);
    vi.mocked(createPublicOrder).mockResolvedValue(pedidoCreado(3, 200));
    useCartStore.getState().addItem('tienda-demo', unProducto({ price: 200 }));
    render(<CartDrawer open onClose={vi.fn()} slug="tienda-demo" tenant={unaTienda({ payment_methods: null })} />);

    expect(screen.queryByText('Formas de pago')).not.toBeInTheDocument();

    await rellenarDatos(user);
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    await waitFor(() => expect(createPublicOrder).toHaveBeenCalled());
    const url = new URL(vi.mocked(window.open).mock.calls[0][0] as string);
    expect(url.searchParams.get('text')).not.toContain('Formas de pago');
  });

  it('enseña solo los métodos activos y los suma al mensaje de WhatsApp', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'open').mockReturnValue(null);
    vi.mocked(createPublicOrder).mockResolvedValue(pedidoCreado(9, 200));
    useCartStore.getState().addItem('tienda-demo', unProducto({ price: 200 }));
    render(
      <CartDrawer
        open
        onClose={vi.fn()}
        slug="tienda-demo"
        tenant={unaTienda({
          payment_methods: {
            yape: { enabled: true, phone: '987654321', holder_name: 'Ana' },
            plin: { enabled: false, phone: '999888777' },
            efectivo: { enabled: true },
          },
        })}
      />,
    );

    expect(screen.getByText('Formas de pago')).toBeInTheDocument();
    expect(screen.getByText('Yape')).toBeInTheDocument();
    expect(screen.getByText((_, el) => el?.textContent === 'Yape · 987654321 · Ana')).toBeInTheDocument();
    expect(screen.getByText('Efectivo contra entrega')).toBeInTheDocument();
    expect(screen.queryByText('Plin')).not.toBeInTheDocument();

    await rellenarDatos(user);
    await user.click(screen.getByRole('button', { name: /Enviar pedido/ }));

    await waitFor(() => expect(createPublicOrder).toHaveBeenCalled());
    const url = new URL(vi.mocked(window.open).mock.calls[0][0] as string);
    const mensaje = url.searchParams.get('text');
    expect(mensaje).toContain('Formas de pago: Yape (987654321 · Ana), Efectivo contra entrega');
  });
});
