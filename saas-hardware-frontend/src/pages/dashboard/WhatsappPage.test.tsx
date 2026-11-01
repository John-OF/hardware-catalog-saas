import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WhatsappPage from './WhatsappPage';
import { getPlantillasWhatsapp, guardarPlantillasWhatsapp, type PlantillasWhatsapp } from '../../api/plantillasWhatsapp';
import { useTenantStore } from '../../stores/tenantStore';
import { unaTienda } from '../../test/fixtures';

vi.mock('../../api/plantillasWhatsapp', async (original) => ({
  ...(await original<typeof import('../../api/plantillasWhatsapp')>()),
  getPlantillasWhatsapp: vi.fn(),
  guardarPlantillasWhatsapp: vi.fn(),
}));

vi.mock('react-hot-toast', () => ({
  default: { success: vi.fn(), error: vi.fn() },
  toast: { success: vi.fn(), error: vi.fn() },
}));

const VARIABLES_DE_PEDIDO = ['cliente', 'tienda', 'pedido', 'productos', 'total', 'entrega', 'pagos'];

/** Dos de las diez, con la forma de `GET /api/whatsapp-templates`. */
const respuesta = (listo = '¡Hola {cliente}! Tu pedido {pedido} ya está listo.', personalizada = false): PlantillasWhatsapp => ({
  max: 1000,
  variables: { cliente: 'El nombre del cliente', pedido: 'El número del pedido', tienda: 'El nombre de tu tienda' },
  plantillas: [
    {
      clave: 'pedido_nuevo', grupo: 'comprador', etiqueta: 'Pedido desde el carrito', cuando: 'Lo envía el comprador.',
      variables: ['tienda', 'pedido', 'productos', 'total', 'cliente', 'entrega', 'nota', 'pagos'],
      texto: 'Hola {tienda}, quiero:\n{productos}\nNota: {nota}', de_fabrica: 'Hola {tienda}, quiero:\n{productos}\nNota: {nota}', personalizada: false,
    },
    {
      clave: 'pedido_listo', grupo: 'tienda', etiqueta: 'Pedido listo', cuando: 'Lo mandas tú.',
      variables: VARIABLES_DE_PEDIDO,
      texto: listo, de_fabrica: '¡Hola {cliente}! Tu pedido {pedido} ya está listo.', personalizada,
    },
  ],
});

const abrir = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <WhatsappPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

const tarjeta = async (titulo: string) => (await screen.findByText(titulo)).closest('article') as HTMLElement;

describe('WhatsappPage (MOD-25)', () => {
  beforeEach(() => {
    useTenantStore.setState({ tenant: unaTienda({ name: 'Pastelería Ana' }) });
    vi.mocked(getPlantillasWhatsapp).mockReset().mockResolvedValue(respuesta());
    vi.mocked(guardarPlantillasWhatsapp).mockReset().mockResolvedValue(respuesta('Listo, {cliente}.', true));
  });

  it('enseña cada mensaje con su vista previa, que quita las líneas vacías', async () => {
    abrir();

    const carrito = await tarjeta('Pedido desde el carrito');
    // Con datos de ejemplo y el nombre real de la tienda; «Nota» sí sale porque
    // el ejemplo trae nota.
    expect(within(carrito).getByText(/Hola Pastelería Ana, quiero:/)).toBeInTheDocument();
    expect(within(carrito).getByText(/Nota: Lo recojo el sábado/)).toBeInTheDocument();

    const listo = await tarjeta('Pedido listo');
    expect(within(listo).getByText('¡Hola Ana Pérez! Tu pedido #47 ya está listo.')).toBeInTheDocument();
    // Ya es el de fábrica: no hay nada que restaurar.
    expect(within(listo).queryByRole('button', { name: /Restaurar/ })).not.toBeInTheDocument();
  });

  it('guarda solo los mensajes que cambiaron', async () => {
    const user = userEvent.setup();
    abrir();

    const listo = await tarjeta('Pedido listo');
    const area = within(listo).getByRole('textbox');
    await user.clear(area);
    await user.type(area, 'Listo, ');
    // La variable entra donde está el cursor, con sus llaves.
    await user.click(within(listo).getByRole('button', { name: '{cliente}' }));
    await user.type(area, '.');

    expect(within(listo).getByText('Sin guardar')).toBeInTheDocument();
    expect(screen.getByText('1 mensaje sin guardar.')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /Guardar todos los cambios/ }));

    await waitFor(() => expect(guardarPlantillasWhatsapp).toHaveBeenCalledWith({ pedido_listo: 'Listo, {cliente}.' }));
    expect(await screen.findByText('No hay cambios sin guardar.')).toBeInTheDocument();
    expect(within(listo).getByText('Personalizado')).toBeInTheDocument();
  });

  it('guardar un mensaje desde su tarjeta no se lleva el borrador de otro', async () => {
    const user = userEvent.setup();
    abrir();

    const carrito = await tarjeta('Pedido desde el carrito');
    const listo = await tarjeta('Pedido listo');
    await user.type(within(carrito).getByRole('textbox'), ' Gracias.');
    await user.clear(within(listo).getByRole('textbox'));
    await user.type(within(listo).getByRole('textbox'), 'Listo, ');
    await user.click(within(listo).getByRole('button', { name: '{cliente}' }));
    await user.type(within(listo).getByRole('textbox'), '.');

    expect(screen.getByText('2 mensajes sin guardar.')).toBeInTheDocument();

    await user.click(within(listo).getByRole('button', { name: /Guardar este mensaje/ }));

    await waitFor(() => expect(guardarPlantillasWhatsapp).toHaveBeenCalledWith({ pedido_listo: 'Listo, {cliente}.' }));
    // El del carrito sigue escrito y sin guardar.
    expect(await screen.findByText('1 mensaje sin guardar.')).toBeInTheDocument();
    expect(within(carrito).getByRole('textbox')).toHaveValue('Hola {tienda}, quiero:\n{productos}\nNota: {nota} Gracias.');
    expect(within(carrito).getByText('Sin guardar')).toBeInTheDocument();
  });

  it('una variable que ese mensaje no admite se avisa y no deja guardar', async () => {
    const user = userEvent.setup();
    abrir();

    const listo = await tarjeta('Pedido listo');
    const area = within(listo).getByRole('textbox');
    await user.clear(area);
    // `{{` es como user-event escribe una llave literal.
    await user.type(area, 'Hola {{clinete}');

    expect(within(listo).getByText('{clinete} no existe en este mensaje. Usa las de arriba.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Guardar todos los cambios/ })).toBeDisabled();
  });

  it('restaurar deja el texto de fábrica, y guardarlo lo manda para que el servidor quite el propio', async () => {
    const user = userEvent.setup();
    vi.mocked(getPlantillasWhatsapp).mockResolvedValue(respuesta('Listo, {cliente}.', true));
    abrir();

    const listo = await tarjeta('Pedido listo');
    expect(within(listo).getByText('Personalizado')).toBeInTheDocument();

    await user.click(within(listo).getByRole('button', { name: /Restaurar el de fábrica/ }));
    expect(within(listo).getByRole('textbox')).toHaveValue('¡Hola {cliente}! Tu pedido {pedido} ya está listo.');

    await user.click(screen.getByRole('button', { name: /Guardar todos los cambios/ }));
    await waitFor(() => expect(guardarPlantillasWhatsapp).toHaveBeenCalledWith({
      pedido_listo: '¡Hola {cliente}! Tu pedido {pedido} ya está listo.',
    }));
  });
});
