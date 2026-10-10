import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PlatformLayout from './PlatformLayout';
import { getPlatformMe } from '../../api/platform';
import { usePlatformAuthStore } from '../../stores/platformAuthStore';

vi.mock('../../api/platform', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../api/platform')>()),
  getPlatformMe: vi.fn(),
  platformLogout: vi.fn(),
}));

const operador = { id: 'u-1', name: 'Operador', email: 'operador@plataforma.test', role: 'superadmin' };

const abrir = (ruta: string) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[ruta]}>
        <Routes>
          <Route path="/platform" element={<PlatformLayout />}>
            <Route path="tenants" element={<p>Listado de tiendas</p>} />
            <Route path="seguridad" element={<p>Pantalla de seguridad</p>} />
          </Route>
          <Route path="/platform/login" element={<p>Login</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

const conSeguridad = (dos_pasos: boolean, obligatoria: boolean) =>
  vi.mocked(getPlatformMe).mockResolvedValue({
    user: operador,
    seguridad: { dos_pasos, obligatoria, activada_el: null, codigos_restantes: null },
  } as never);

/**
 * SEC-9: en producción, sin la verificación en dos pasos el backend responde 403
 * a todo salvo a activarla; el layout lleva directo a esa pantalla.
 */
describe('PlatformLayout y la verificación en dos pasos', () => {
  beforeEach(() => {
    vi.mocked(getPlatformMe).mockReset();
    usePlatformAuthStore.getState().setPlatformAuth('tok-plataforma', operador as never);
  });

  it('obligatoria y sin activar: cualquier sección lleva a Seguridad', async () => {
    conSeguridad(false, true);
    abrir('/platform/tenants');

    expect(await screen.findByText('Pantalla de seguridad')).toBeInTheDocument();
    expect(screen.queryByText('Listado de tiendas')).not.toBeInTheDocument();
  });

  it('fuera de producción no obliga, aunque lo avisa en el menú', async () => {
    conSeguridad(false, false);
    abrir('/platform/tenants');

    expect(await screen.findByText('Listado de tiendas')).toBeInTheDocument();
    expect(await screen.findByTitle('La verificación en dos pasos no está activada')).toBeInTheDocument();
  });

  it('activada, el panel se usa con normalidad', async () => {
    conSeguridad(true, true);
    abrir('/platform/tenants');

    expect(await screen.findByText('Listado de tiendas')).toBeInTheDocument();
    expect(screen.queryByTitle('La verificación en dos pasos no está activada')).not.toBeInTheDocument();
  });
});
