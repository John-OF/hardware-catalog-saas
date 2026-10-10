import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { toast } from 'react-hot-toast';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PlatformSeguridadPage from './PlatformSeguridadPage';
import { confirmarDosPasos, getPlatformMe, iniciarDosPasos, type SeguridadDelOperador } from '../../api/platform';

vi.mock('../../api/platform', () => ({
  getPlatformMe: vi.fn(),
  iniciarDosPasos: vi.fn(),
  confirmarDosPasos: vi.fn(),
}));

vi.mock('react-hot-toast', () => ({
  default: { success: vi.fn(), error: vi.fn() },
  toast: { success: vi.fn(), error: vi.fn() },
}));

const operador = { id: 'u-1', name: 'Operador', email: 'operador@plataforma.test', role: 'superadmin' };

const conSeguridad = (seguridad: Partial<SeguridadDelOperador>) =>
  vi.mocked(getPlatformMe).mockResolvedValue({
    user: operador,
    seguridad: { dos_pasos: false, obligatoria: false, activada_el: null, codigos_restantes: null, ...seguridad },
  } as never);

const CODIGOS = ['ABCDE-FGHJK', 'MNPQR-STUVW', 'XYZ23-45678', 'ABCDE-23456', 'FGHJK-MNPQR', 'STUVW-XYZ23', '45678-ABCDE', '23456-FGHJK'];

const abrir = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <PlatformSeguridadPage />
    </QueryClientProvider>,
  );

/** La verificación en dos pasos del operador, en su pantalla (SEC-9). */
describe('PlatformSeguridadPage', () => {
  beforeEach(() => {
    vi.mocked(getPlatformMe).mockReset();
    vi.mocked(iniciarDosPasos).mockReset().mockResolvedValue({
      secreto: 'JBSWY3DPEHPK3PXP',
      enlace: 'otpauth://totp/x',
      qr_svg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
    });
    vi.mocked(confirmarDosPasos).mockReset();
    vi.mocked(toast.error).mockReset();
  });

  it('sin activar explica qué es; si es obligatoria, lo avisa', async () => {
    conSeguridad({ obligatoria: true });
    abrir();

    expect(await screen.findByRole('button', { name: /Empezar/ })).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('es obligatoria');
  });

  it('activa con el QR y un código, y enseña los ocho códigos de recuperación una vez', async () => {
    conSeguridad({});
    vi.mocked(confirmarDosPasos).mockResolvedValue({
      codigos: CODIGOS,
      seguridad: { dos_pasos: true, obligatoria: false, activada_el: '2026-09-27T12:00:00Z', codigos_restantes: 8 },
    });
    const user = userEvent.setup();
    abrir();

    await user.click(await screen.findByRole('button', { name: /Empezar/ }));

    expect(screen.getByAltText('Código QR para la app de autenticación')).toBeInTheDocument();
    // La clave, en grupos de cuatro para copiarla a mano.
    expect(screen.getByText('JBSW Y3DP EHPK 3PXP')).toBeInTheDocument();

    await user.type(screen.getByLabelText(/Escribe el código/), '123456');
    await user.click(screen.getByRole('button', { name: /Activar/ }));

    expect(confirmarDosPasos).toHaveBeenCalledWith('123456');
    expect(await screen.findByText('ABCDE-FGHJK')).toBeInTheDocument();
    expect(screen.getAllByRole('listitem')).toHaveLength(8);

    // Al decir que están guardados, desaparecen: no se vuelven a enseñar.
    await user.click(screen.getByRole('button', { name: 'Ya los guardé' }));
    expect(screen.queryByText('ABCDE-FGHJK')).not.toBeInTheDocument();
    expect(await screen.findByText(/quedan 8 códigos/)).toBeInTheDocument();
  });

  it('un código equivocado no activa nada y se borra para escribir otro', async () => {
    conSeguridad({});
    const config = { headers: new AxiosHeaders() };
    vi.mocked(confirmarDosPasos).mockRejectedValue(
      new AxiosError('fallo', 'ERR_BAD_REQUEST', config, {}, {
        status: 422,
        data: { message: 'x', errors: { codigo: ['El código no es correcto.'] } },
        statusText: '',
        headers: {},
        config,
      } as AxiosResponse),
    );
    const user = userEvent.setup();
    abrir();

    await user.click(await screen.findByRole('button', { name: /Empezar/ }));
    await user.type(screen.getByLabelText(/Escribe el código/), '000000');
    await user.click(screen.getByRole('button', { name: /Activar/ }));

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    expect(screen.getByLabelText(/Escribe el código/)).toHaveValue('');
    expect(screen.queryByText('Ya los guardé')).not.toBeInTheDocument();
  });

  it('activa: dice cuántos códigos quedan y no ofrece apagarla desde aquí', async () => {
    conSeguridad({ dos_pasos: true, activada_el: '2026-09-27T12:00:00Z', codigos_restantes: 2 });
    abrir();

    expect(await screen.findByText(/quedan 2 códigos/)).toBeInTheDocument();
    expect(screen.getByText(/platform:quitar-dos-pasos/)).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
