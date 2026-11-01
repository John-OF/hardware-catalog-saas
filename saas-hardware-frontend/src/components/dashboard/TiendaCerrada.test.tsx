import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TiendaCerrada from './TiendaCerrada';
import { getPublicPlans } from '../../api/public';
import type { PublicPlan } from '../../api/public';
import { useAuthStore } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';
import { unaTienda } from '../../test/fixtures';
import type { CierreDeTienda, User } from '../../types';

vi.mock('../../api/public', async (original) => ({
  ...(await original<typeof import('../../api/public')>()),
  getPublicPlans: vi.fn(),
}));

const plan = (key: PublicPlan['key'], label: string, price_usd: number): PublicPlan => ({
  key, label, price_usd, limits: { products: 20, custom_domain: false },
});

const PLANES = [plan('free', 'Básico', 15), plan('pro', 'Pro', 29), plan('enterprise', 'Enterprise', 79)];

const abrir = (cierre: CierreDeTienda, rol: 'admin' | 'staff' = 'admin', onSalir = vi.fn()) => {
  useTenantStore.setState({ tenant: unaTienda({ name: 'Mi Tienda', slug: 'mi-tienda', timezone: 'UTC' }), cierre });
  useAuthStore.setState({ user: { id: 'u-1', name: 'Ana', email: 'ana@x.com', role: rol } as User });

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <TiendaCerrada onSalir={onSalir} />
    </QueryClientProvider>,
  );

  return onSalir;
};

describe('TiendaCerrada (FUN-28)', () => {
  beforeEach(() => {
    vi.mocked(getPublicPlans).mockReset().mockResolvedValue(PLANES);
    vi.stubEnv('VITE_PLATAFORMA_WHATSAPP', '593991234567');
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it('con la prueba terminada dice desde cuándo y ofrece los planes por WhatsApp', async () => {
    abrir({ motivo: 'prueba', desde: '2026-11-08T12:00:00+00:00', plan: 'Básico' });

    expect(screen.getByRole('heading', { name: 'Tu prueba gratuita terminó' })).toBeInTheDocument();
    expect(screen.getByText(/catálogo de Mi Tienda no se ve/)).toHaveTextContent('8 nov 2026');
    expect(screen.getByText(/siguen guardados/)).toBeInTheDocument();

    const pedirPro = await screen.findByRole('link', { name: /Pedir el plan Pro/ });
    expect(pedirPro).toHaveAttribute(
      'href',
      `https://wa.me/593991234567?text=${encodeURIComponent('Hola, quiero activar el plan Pro para mi tienda Mi Tienda (mi-tienda).')}`,
    );
    // En prueba, ningún plan es «el suyo»: la columna dice Básico solo por defecto.
    expect(screen.queryByText('Tu plan')).not.toBeInTheDocument();
  });

  it('con el plan vencido lo nombra y marca el que tenía', async () => {
    abrir({ motivo: 'plan', desde: '2026-11-08T15:00:00+00:00', plan: 'Pro' });

    expect(screen.getByRole('heading', { name: 'Tu plan Pro venció' })).toBeInTheDocument();
    expect(await screen.findByText('Tu plan')).toBeInTheDocument();
  });

  it('suspendida a mano no ofrece planes: solo escribir a la plataforma', async () => {
    abrir({ motivo: 'suspendida', desde: null, plan: 'Pro' });

    expect(screen.getByRole('heading', { name: 'Tu tienda está suspendida' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Escribir a la plataforma/ })).toHaveAttribute(
      'href',
      `https://wa.me/593991234567?text=${encodeURIComponent('Hola, quiero reabrir mi tienda Mi Tienda (mi-tienda).')}`,
    );
    expect(screen.queryByRole('heading', { name: 'Elige un plan' })).not.toBeInTheDocument();
    expect(getPublicPlans).not.toHaveBeenCalled();
  });

  it('a quien no administra solo le dice a quién avisar', () => {
    abrir({ motivo: 'prueba', desde: '2026-11-08T12:00:00+00:00', plan: 'Básico' }, 'staff');

    expect(screen.getByText(/Avísale a quien administra la tienda/)).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(getPublicPlans).not.toHaveBeenCalled();
  });

  it('sin WhatsApp cae al correo de contacto, y sin ninguno no hay botones que no lleven a nada', async () => {
    vi.stubEnv('VITE_PLATAFORMA_WHATSAPP', '');
    vi.stubEnv('VITE_LEGAL_CORREO', 'hola@gondofy.test');
    abrir({ motivo: 'prueba', desde: null, plan: 'Básico' });

    expect((await screen.findByRole('link', { name: /Pedir el plan Pro/ })).getAttribute('href')).toMatch(/^mailto:hola@gondofy\.test\?/);
  });

  it('sin ningún contacto configurado enseña los planes pero sin botón', async () => {
    vi.stubEnv('VITE_PLATAFORMA_WHATSAPP', '');
    vi.stubEnv('VITE_LEGAL_CORREO', '');
    abrir({ motivo: 'prueba', desde: null, plan: 'Básico' });

    expect(await screen.findByRole('heading', { name: 'Pro' })).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('el botón de salir cierra la sesión', async () => {
    const onSalir = abrir({ motivo: 'suspendida', desde: null, plan: 'Pro' });

    await userEvent.click(screen.getByRole('button', { name: /Cerrar sesión/ }));

    expect(onSalir).toHaveBeenCalled();
  });
});
