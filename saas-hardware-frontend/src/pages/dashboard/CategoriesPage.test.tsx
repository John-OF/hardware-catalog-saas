import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CategoriesPage from './CategoriesPage';
import { createCategory, getCategories } from '../../api/categories';
import { useTenantStore } from '../../stores/tenantStore';
import { unaTienda } from '../../test/fixtures';
import type { Category } from '../../types';

vi.mock('../../api/categories', async (original) => ({
  ...(await original<typeof import('../../api/categories')>()),
  getCategories: vi.fn(),
  createCategory: vi.fn(),
}));

vi.mock('react-hot-toast', () => ({
  default: { success: vi.fn(), error: vi.fn() },
  toast: { success: vi.fn(), error: vi.fn() },
}));

const procesadores: Category = {
  id: 'c-1', name: 'Procesadores', icon: 'cpu', component_type: 'cpu', sort_order: 0, is_active: true,
} as Category;

const abrir = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <CategoriesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

const crearUna = async (nombre: string) => {
  await userEvent.click(await screen.findByRole('button', { name: /Nueva Categoría/ }));
  await userEvent.type(screen.getByLabelText('Nombre de Categoría'), nombre);
};

describe('CategoriesPage y la vertical de la tienda (MOD-19)', () => {
  beforeEach(() => {
    vi.mocked(getCategories).mockReset().mockResolvedValue([procesadores]);
    vi.mocked(createCategory).mockReset().mockResolvedValue({ ...procesadores, id: 'c-2' });
  });

  it('en una tienda de PC pregunta qué pieza vende la categoría y lo manda', async () => {
    useTenantStore.setState({ tenant: unaTienda() });
    abrir();

    expect(await screen.findByText('Procesadores (CPU)')).toBeInTheDocument();

    await crearUna('Tarjetas');
    expect(screen.getByLabelText('¿Qué vende esta categoría?')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Guardar|Crear/ }));
    await waitFor(() => expect(createCategory).toHaveBeenCalled());
    expect(vi.mocked(createCategory).mock.calls[0][0]).toMatchObject({ name: 'Tarjetas', component_type: 'other', icon: expect.any(String) });
  });

  it('en una tienda de otro rubro no enseña ni manda nada de piezas de PC', async () => {
    useTenantStore.setState({
      tenant: unaTienda({ vertical: 'general', modulos: { tipo_de_componente: false, ficha_tecnica: false, armador: false } }),
    });
    abrir();

    expect(await screen.findByText('Procesadores')).toBeInTheDocument();
    // El tipo que conserva la categoría (la tienda fue de PC) no se enseña.
    expect(screen.queryByText('Procesadores (CPU)')).not.toBeInTheDocument();

    await crearUna('Camisetas');
    expect(screen.queryByLabelText('¿Qué vende esta categoría?')).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Guardar|Crear/ }));
    await waitFor(() => expect(createCategory).toHaveBeenCalled());

    // Ausente es «no lo toques»: la API decide el tipo y el icono al crear.
    const enviado = vi.mocked(createCategory).mock.calls[0][0];
    expect(enviado).not.toHaveProperty('component_type');
    expect(enviado).not.toHaveProperty('icon');
  });
});
