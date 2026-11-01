import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TrialBanner from './TrialBanner';
import { useTenantStore } from '../../stores/tenantStore';
import { unaTienda } from '../../test/fixtures';
import type { Tenant } from '../../types';

const abrir = (datos: Partial<Tenant>) => {
  useTenantStore.setState({ tenant: unaTienda({ name: 'Mi Tienda', slug: 'mi-tienda', timezone: 'UTC', ...datos }) });
  return render(<TrialBanner />);
};

describe('TrialBanner: prueba (FUN-16) y plan pagado (INF-23)', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-11-01T18:00:00Z'));
    vi.stubEnv('VITE_PLATAFORMA_WHATSAPP', '593991234567');
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllEnvs();
  });

  it('en prueba dice cuántos días quedan, con el enlace para escribir', () => {
    abrir({ trial_ends_at: '2026-11-04T18:00:00Z' });

    expect(screen.getByText('Te quedan 3 días de prueba.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Escribirnos' }).getAttribute('href')).toMatch(/^https:\/\/wa\.me\/593991234567\?text=/);
  });

  it('el plan avisa en su última semana, contando días de calendario', () => {
    abrir({ trial_ends_at: null, plan_ends_at: '2026-11-05T23:59:59Z' });

    expect(screen.getByText('Tu plan vence en 4 días (el 5 nov 2026).')).toBeInTheDocument();
    // Renovar, no «reabrir»: la tienda sigue abierta.
    expect(screen.getByRole('link', { name: 'Escribirnos' })).toHaveAttribute(
      'href',
      `https://wa.me/593991234567?text=${encodeURIComponent('Hola, quiero renovar el plan de mi tienda Mi Tienda (mi-tienda).')}`,
    );
  });

  it('el último día dice «hoy»', () => {
    abrir({ trial_ends_at: null, plan_ends_at: '2026-11-01T23:59:59Z' });

    expect(screen.getByText('Tu plan vence hoy.')).toBeInTheDocument();
  });

  it('con más de una semana por delante, o sin fecha, no avisa', () => {
    const { container } = abrir({ trial_ends_at: null, plan_ends_at: '2026-11-20T12:00:00Z' });
    expect(container).toBeEmptyDOMElement();
  });

  it('sin ningún contacto configurado, avisa igual pero sin enlace', () => {
    vi.stubEnv('VITE_PLATAFORMA_WHATSAPP', '');
    vi.stubEnv('VITE_LEGAL_CORREO', '');
    abrir({ trial_ends_at: null, plan_ends_at: '2026-11-03T12:00:00Z' });

    expect(screen.getByText('Tu plan vence en 2 días (el 3 nov 2026).')).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });
});
