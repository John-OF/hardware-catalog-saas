import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginPage from '../../pages/auth/LoginPage';
import PlatformLoginPage from '../../pages/platform/PlatformLoginPage';
import { loginUser } from '../../api/auth';
import { platformLogin } from '../../api/platform';
import { useAuthStore } from '../../stores/authStore';
import { usePlatformAuthStore } from '../../stores/platformAuthStore';
import { cuentasDePrueba, type CuentaDePrueba } from '../../utils/cuentasDePrueba';

vi.mock('../../utils/cuentasDePrueba', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../utils/cuentasDePrueba')>()),
  cuentasDePrueba: vi.fn(),
}));

vi.mock('../../api/auth', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../api/auth')>()),
  loginUser: vi.fn(),
}));

vi.mock('../../api/platform', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../api/platform')>()),
  platformLogin: vi.fn(),
}));

vi.mock('react-hot-toast', () => ({
  default: { success: vi.fn(), error: vi.fn() },
  toast: { success: vi.fn(), error: vi.fn() },
}));

const CUENTAS: CuentaDePrueba[] = [
  { tipo: 'plataforma', etiqueta: 'Operador de plataforma', email: 'op@local', password: 'clave-op' },
  { tipo: 'tienda', etiqueta: 'Tienda con catálogo', email: 'admin@tienda.local', password: 'clave-tienda' },
];

const sesionDeTienda = {
  token: 'tok-tienda',
  user: { id: 'u-1', name: 'Admin', email: 'admin@tienda.local', role: 'admin' },
  tenant: { id: 't-1', name: 'Tienda con catálogo', slug: 'tienda' },
} as unknown as Awaited<ReturnType<typeof loginUser>>;

const sesionDePlataforma = {
  token: 'tok-plataforma',
  user: { id: 'u-2', name: 'Operador', email: 'op@local', role: 'superadmin' },
  seguridad: { dos_pasos: false, obligatoria: false, activada_el: null, codigos_restantes: 0 },
} as unknown as Awaited<ReturnType<typeof platformLogin>>;

const abrir = (ruta: string) =>
  render(
    <MemoryRouter initialEntries={[ruta]}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/dashboard" element={<p>Panel de la tienda</p>} />
        <Route path="/platform/login" element={<PlatformLoginPage />} />
        <Route path="/platform" element={<p>Panel de plataforma</p>} />
      </Routes>
    </MemoryRouter>,
  );

/**
 * La lista de cuentas de prueba de los dos logins (sólo en desarrollo): un clic
 * entra con la cuenta por el mismo camino que el formulario, y una cuenta del
 * otro tipo lleva a su login y entra allí.
 */
describe('CuentasDePrueba', () => {
  beforeEach(() => {
    vi.mocked(cuentasDePrueba).mockReturnValue(CUENTAS);
    vi.mocked(loginUser).mockReset().mockResolvedValue(sesionDeTienda);
    vi.mocked(platformLogin).mockReset().mockResolvedValue(sesionDePlataforma);
    useAuthStore.getState().clearAuth();
    usePlatformAuthStore.getState().clearPlatformAuth();
  });

  it('en el login de tienda, una tienda entra con su correo y su contraseña', async () => {
    const user = userEvent.setup();
    abrir('/login');

    await user.click(await screen.findByRole('button', { name: /Tienda con catálogo/ }));

    expect(await screen.findByText('Panel de la tienda')).toBeInTheDocument();
    expect(loginUser).toHaveBeenCalledWith({ email: 'admin@tienda.local', password: 'clave-tienda' });
    expect(platformLogin).not.toHaveBeenCalled();
  });

  it('en el login de tienda, el operador lleva al login de plataforma y entra allí', async () => {
    const user = userEvent.setup();
    abrir('/login');

    await user.click(await screen.findByRole('button', { name: /Operador de plataforma/ }));

    expect(await screen.findByText('Panel de plataforma')).toBeInTheDocument();
    expect(platformLogin).toHaveBeenCalledOnce();
    expect(platformLogin).toHaveBeenCalledWith('op@local', 'clave-op');
    expect(loginUser).not.toHaveBeenCalled();
  });

  it('en el login de plataforma, una tienda lleva al login de tienda y entra allí', async () => {
    const user = userEvent.setup();
    abrir('/platform/login');

    await user.click(await screen.findByRole('button', { name: /Tienda con catálogo/ }));

    expect(await screen.findByText('Panel de la tienda')).toBeInTheDocument();
    expect(loginUser).toHaveBeenCalledOnce();
    expect(loginUser).toHaveBeenCalledWith({ email: 'admin@tienda.local', password: 'clave-tienda' });
  });

  it('sin cuentas no pinta nada', async () => {
    vi.mocked(cuentasDePrueba).mockReturnValue([]);
    abrir('/login');

    await screen.findByRole('button', { name: 'Ingresar al Panel' });
    // El componente llega con lazy(): se le da tiempo a cargar antes de mirar.
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(screen.queryByRole('region', { name: 'Cuentas de prueba' })).not.toBeInTheDocument();
  });
});
