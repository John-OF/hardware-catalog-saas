import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import { useTenantBranding } from './useTenantBranding';
import { unaTienda } from '../test/fixtures';

/**
 * El branding de la pestaña y las etiquetas que lee un buscador (INF-4).
 *
 * Lo que se vigila aquí es el **canónico**: el catálogo guarda sus filtros en la
 * query (`UI-1`), así que la misma lista existe bajo decenas de URL distintas y
 * un buscador las contaría como páginas duplicadas. Sin el canónico sin query,
 * el SEO de una tienda se reparte entre todas esas variantes.
 */
describe('useTenantBranding (INF-4)', () => {
  beforeEach(() => {
    document.head.innerHTML = '';
    window.history.replaceState({}, '', '/tienda-demo');
  });

  const canonical = () =>
    document.querySelector<HTMLLinkElement>("link[rel='canonical']")?.href;

  it('pone el canónico de la página que se está mirando', () => {
    renderHook(() => useTenantBranding(unaTienda()));

    expect(canonical()).toBe(`${window.location.origin}/tienda-demo`);
  });

  it('el canónico deja fuera la query, para que los filtros no dupliquen la página', () => {
    window.history.replaceState({}, '', '/tienda-demo?categoria=cpu&orden=precio&pagina=3');

    renderHook(() => useTenantBranding(unaTienda()));

    expect(canonical()).toBe(`${window.location.origin}/tienda-demo`);
  });

  it('no deja dos canónicos al volver a renderizar', () => {
    const { rerender } = renderHook(({ t }) => useTenantBranding(t), {
      initialProps: { t: unaTienda() },
    });

    rerender({ t: unaTienda({ name: 'Otra Tienda' }) });

    expect(document.querySelectorAll("link[rel='canonical']")).toHaveLength(1);
  });

  it('sin tienda todavía no toca nada', () => {
    renderHook(() => useTenantBranding(null));

    expect(canonical()).toBeUndefined();
  });

  it('el título y la descripción salen del tema de la tienda', () => {
    renderHook(() =>
      useTenantBranding(
        unaTienda({ name: 'Tienda SEO', theme: { hero_subtitle: 'Componentes al mejor precio' } }),
        'RTX 4070',
      ),
    );

    expect(document.title).toBe('RTX 4070 · Tienda SEO');
    expect(
      document.querySelector('meta[name="description"]')?.getAttribute('content'),
    ).toBe('Componentes al mejor precio');
  });
});
