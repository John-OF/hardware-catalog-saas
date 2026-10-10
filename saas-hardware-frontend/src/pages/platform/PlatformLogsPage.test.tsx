import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PlatformLogsPage from './PlatformLogsPage';
import { getPlatformLogs, type ActivityLogEntry } from '../../api/platform';

vi.mock('../../api/platform', () => ({ getPlatformLogs: vi.fn() }));

const linea = (extra: Partial<ActivityLogEntry>): ActivityLogEntry => ({
  id: 'l-1',
  tenant_id: null,
  actor_email: 'operador@plataforma.test',
  actor_role: 'superadmin',
  action: 'tenant.plan',
  description: 'Cambió el plan.',
  context: null,
  ip: '127.0.0.1',
  created_at: '2026-09-27T12:00:00Z',
  tenant: null,
  ...extra,
});

const abrir = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <PlatformLogsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

const conLineas = (lineas: ActivityLogEntry[]) =>
  vi.mocked(getPlatformLogs).mockResolvedValue({
    data: lineas,
    current_page: 1,
    last_page: 1,
    per_page: 30,
    total: lineas.length,
  } as never);

describe('PlatformLogsPage: de qué tienda es cada línea', () => {
  beforeEach(() => {
    vi.mocked(getPlatformLogs).mockReset();
  });

  it('una tienda borrada sale con su nombre guardado y «(borrada)»', async () => {
    conLineas([linea({ context: { tienda: 'Tienda Vieja' } })]);
    abrir();

    expect(await screen.findByText('Tienda Vieja (borrada)')).toBeInTheDocument();
  });

  /**
   * SEC-9: activar la verificación en dos pasos es de la cuenta del operador, no
   * de ninguna tienda. Antes salía «— (borrada)», como si hubiera una tienda
   * detrás y la hubieran borrado.
   */
  it('lo de la cuenta del operador no dice que haya una tienda borrada', async () => {
    conLineas([linea({ action: 'operador.dos_pasos_activada', description: 'Activó la verificación en dos pasos.' })]);
    abrir();

    expect(await screen.findByText('Activó la verificación en dos pasos.')).toBeInTheDocument();
    expect(screen.queryByText(/borrada/)).not.toBeInTheDocument();
  });

  it('una línea sin actor es del sistema, no de un operador borrado', async () => {
    conLineas([linea({ actor_email: null, action: 'operador.dos_pasos_quitada', description: 'Se quitó desde el servidor.' })]);
    abrir();

    expect(await screen.findByText(/sistema/)).toBeInTheDocument();
    expect(screen.queryByText(/operador borrado/)).not.toBeInTheDocument();
  });
});
