import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { datosLegales } from '../../utils/legal';
import PrivacidadPage from './PrivacidadPage';
import ReembolsosPage from './ReembolsosPage';
import TerminosPage from './TerminosPage';

/**
 * INF-15: las páginas legales que Paddle revisa antes de aprobar la cuenta.
 * El nombre del titular y el correo salen del build; si faltan, la página lo
 * tiene que decir a la vista, no quedarse en blanco.
 */

const pintar = (pagina: React.ReactElement) => render(<MemoryRouter>{pagina}</MemoryRouter>);

afterEach(() => {
  vi.unstubAllEnvs();
});

describe('datosLegales', () => {
  it('lee el titular y el correo del build, sin espacios de sobra', () => {
    expect(datosLegales({ VITE_LEGAL_TITULAR: ' Ana Pérez ', VITE_LEGAL_CORREO: 'ana@ejemplo.com' })).toEqual({
      titular: 'Ana Pérez',
      correo: 'ana@ejemplo.com',
      faltan: [],
    });
  });

  it('dice qué variable falta, también si viene vacía', () => {
    expect(datosLegales({ VITE_LEGAL_TITULAR: '   ' }).faltan).toEqual(['VITE_LEGAL_TITULAR', 'VITE_LEGAL_CORREO']);
  });
});

describe('páginas legales', () => {
  it('con los datos del titular, los enseña y no avisa de nada', () => {
    vi.stubEnv('VITE_LEGAL_TITULAR', 'Ana Pérez');
    vi.stubEnv('VITE_LEGAL_CORREO', 'ana@ejemplo.com');

    pintar(<TerminosPage />);

    expect(screen.getByRole('heading', { level: 1, name: 'Términos y condiciones' })).toBeInTheDocument();
    expect(screen.getAllByText(/Ana Pérez/).length).toBeGreaterThan(0);
    expect(screen.getAllByRole('link', { name: 'ana@ejemplo.com' })[0]).toHaveAttribute('href', 'mailto:ana@ejemplo.com');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(document.title).toBe('Términos y condiciones — Catálogo de Componentes PC');
  });

  it('sin los datos del titular, avisa a la vista en vez de quedarse en blanco', () => {
    vi.stubEnv('VITE_LEGAL_TITULAR', '');
    vi.stubEnv('VITE_LEGAL_CORREO', '');

    pintar(<TerminosPage />);

    expect(screen.getByRole('alert')).toHaveTextContent('VITE_LEGAL_TITULAR, VITE_LEGAL_CORREO');
    expect(screen.getAllByText('[falta el nombre del titular]').length).toBeGreaterThan(0);
    expect(screen.getAllByText('[falta el correo de contacto]').length).toBeGreaterThan(0);
  });

  it('los términos nombran a Paddle como vendedor y dicen que los precios no incluyen impuestos', () => {
    pintar(<TerminosPage />);

    expect(screen.getByRole('heading', { name: /Paddle es el vendedor/ })).toBeInTheDocument();
    expect(screen.getByText(/Merchant of Record/)).toBeInTheDocument();
    expect(screen.getByText('Los precios no incluyen impuestos.')).toBeInTheDocument();
  });

  it.each([
    ['Términos y condiciones', <TerminosPage key="t" />],
    ['Política de privacidad', <PrivacidadPage key="p" />],
    ['Política de reembolsos', <ReembolsosPage key="r" />],
  ])('%s se pinta con su título y enlaza a las otras dos', (titulo, pagina) => {
    pintar(pagina);

    expect(screen.getByRole('heading', { level: 1, name: titulo })).toBeInTheDocument();
    const pie = screen.getByRole('navigation', { name: 'Otras páginas legales' });
    expect(pie.querySelectorAll('a[href="/terminos"], a[href="/privacidad"], a[href="/reembolsos"]')).toHaveLength(3);
  });
});
