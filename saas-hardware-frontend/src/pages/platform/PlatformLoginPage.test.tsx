import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { toast } from 'react-hot-toast';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PlatformLoginPage from './PlatformLoginPage';
import { platformLogin, platformLoginConCodigo } from '../../api/platform';
import { usePlatformAuthStore } from '../../stores/platformAuthStore';

vi.mock('../../api/platform', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../api/platform')>()),
  platformLogin: vi.fn(),
  platformLoginConCodigo: vi.fn(),
}));

vi.mock('react-hot-toast', () => ({
  default: { success: vi.fn(), error: vi.fn() },
  toast: { success: vi.fn(), error: vi.fn() },
}));

const sesion = {
  token: 'tok-plataforma',
  user: { id: 'u-1', name: 'Operador', email: 'operador@plataforma.test', role: 'superadmin' },
  seguridad: { dos_pasos: true, obligatoria: false, activada_el: null, codigos_restantes: 8 },
};

const error422 = (errors: Record<string, string[]>) => {
  const config = { headers: new AxiosHeaders() };
  const response = { status: 422, data: { message: 'x', errors }, statusText: '', headers: {}, config } as AxiosResponse;
  return new AxiosError('fallo', 'ERR_BAD_REQUEST', config, {}, response);
};

const abrir = () =>
  render(
    <MemoryRouter initialEntries={['/platform/login']}>
      <Routes>
        <Route path="/platform/login" element={<PlatformLoginPage />} />
        <Route path="/platform" element={<p>Panel de plataforma</p>} />
      </Routes>
    </MemoryRouter>,
  );

const conContrasenia = async (user: ReturnType<typeof userEvent.setup>) => {
  await user.type(screen.getByLabelText('Correo del operador'), 'operador@plataforma.test');
  await user.type(screen.getByLabelText('Contraseña'), 'password123');
  await user.click(screen.getByRole('button', { name: 'Entrar' }));
};

/**
 * El login de plataforma con la verificación en dos pasos (SEC-9): la
 * contraseña puede abrir la sesión o devolver un desafío, y entonces se pide el
 * código.
 */
describe('PlatformLoginPage', () => {
  beforeEach(() => {
    vi.mocked(platformLogin).mockReset();
    vi.mocked(platformLoginConCodigo).mockReset();
    vi.mocked(toast.error).mockReset();
    usePlatformAuthStore.getState().clearPlatformAuth();
  });

  it('sin el segundo paso, la contraseña entra directa', async () => {
    vi.mocked(platformLogin).mockResolvedValue({ ...sesion, seguridad: { ...sesion.seguridad, dos_pasos: false } } as never);
    const user = userEvent.setup();
    abrir();

    await conContrasenia(user);

    expect(await screen.findByText('Panel de plataforma')).toBeInTheDocument();
    expect(platformLoginConCodigo).not.toHaveBeenCalled();
  });

  it('con el segundo paso, pide el código y entra con él', async () => {
    vi.mocked(platformLogin).mockResolvedValue({ requiere_dos_pasos: true, desafio: 'desafio-1' });
    vi.mocked(platformLoginConCodigo).mockResolvedValue(sesion as never);
    const user = userEvent.setup();
    abrir();

    await conContrasenia(user);
    await user.type(await screen.findByLabelText('Código de verificación'), '123456');
    await user.click(screen.getByRole('button', { name: 'Verificar' }));

    expect(platformLoginConCodigo).toHaveBeenCalledWith('desafio-1', '123456');
    expect(await screen.findByText('Panel de plataforma')).toBeInTheDocument();
    expect(usePlatformAuthStore.getState().isAuthenticated).toBe(true);
  });

  it('sin el móvil, deja escribir un código de recuperación', async () => {
    vi.mocked(platformLogin).mockResolvedValue({ requiere_dos_pasos: true, desafio: 'desafio-1' });
    vi.mocked(platformLoginConCodigo).mockResolvedValue(sesion as never);
    const user = userEvent.setup();
    abrir();

    await conContrasenia(user);
    await user.click(await screen.findByRole('button', { name: /usa un código de recuperación/i }));
    await user.type(screen.getByLabelText('Código de recuperación'), 'ABCDE-FGH23');
    await user.click(screen.getByRole('button', { name: 'Verificar' }));

    expect(platformLoginConCodigo).toHaveBeenCalledWith('desafio-1', 'ABCDE-FGH23');
  });

  it('si solo falló el código, se queda pidiéndolo y lo borra', async () => {
    vi.mocked(platformLogin).mockResolvedValue({ requiere_dos_pasos: true, desafio: 'desafio-1' });
    vi.mocked(platformLoginConCodigo).mockRejectedValue(error422({ codigo: ['El código no es correcto.'] }));
    const user = userEvent.setup();
    abrir();

    await conContrasenia(user);
    const campo = await screen.findByLabelText('Código de verificación');
    await user.type(campo, '000000');
    await user.click(screen.getByRole('button', { name: 'Verificar' }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('El código no es correcto.', expect.anything()));
    expect(screen.getByLabelText('Código de verificación')).toHaveValue('');
  });

  it('si el desafío caducó, vuelve a la contraseña', async () => {
    vi.mocked(platformLogin).mockResolvedValue({ requiere_dos_pasos: true, desafio: 'desafio-1' });
    vi.mocked(platformLoginConCodigo).mockRejectedValue(
      error422({ desafio: ['La verificación caducó. Vuelve a escribir tu contraseña.'] }),
    );
    const user = userEvent.setup();
    abrir();

    await conContrasenia(user);
    await user.type(await screen.findByLabelText('Código de verificación'), '123456');
    await user.click(screen.getByRole('button', { name: 'Verificar' }));

    expect(await screen.findByLabelText('Correo del operador')).toBeInTheDocument();
    expect(toast.error).toHaveBeenCalledWith('La verificación caducó. Vuelve a escribir tu contraseña.', expect.anything());
  });
});
