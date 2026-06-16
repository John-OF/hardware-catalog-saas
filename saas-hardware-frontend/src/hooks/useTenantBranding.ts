import { useEffect } from 'react';
import type { Tenant } from '../types';

// Capturamos el título y favicon originales (los de index.html) una sola vez,
// para poder restaurarlos cuando el visitante sale de las páginas del tenant.
const DEFAULT_TITLE = document.title;

function getOrCreateFaviconLink(): HTMLLinkElement {
  let link = document.querySelector<HTMLLinkElement>("link[rel~='icon']");
  if (!link) {
    link = document.createElement('link');
    link.rel = 'icon';
    document.head.appendChild(link);
  }
  return link;
}

const DEFAULT_FAVICON = getOrCreateFaviconLink().getAttribute('href');

/**
 * Aplica el branding de la pestaña del navegador (título y favicon) según el
 * theme del tenant. Si se pasa `suffix` (ej. el nombre del producto), se
 * antepone al título: "Producto · Mi Tienda".
 */
export function useTenantBranding(tenant?: Tenant | null, suffix?: string) {
  useEffect(() => {
    if (!tenant) return;

    const baseTitle = tenant.theme?.page_title?.trim() || tenant.name;
    document.title = suffix ? `${suffix} · ${baseTitle}` : baseTitle;

    const favicon = tenant.theme?.favicon_url?.trim();
    const link = getOrCreateFaviconLink();
    if (favicon) {
      link.href = favicon;
    }

    return () => {
      document.title = DEFAULT_TITLE;
      if (favicon && DEFAULT_FAVICON) {
        getOrCreateFaviconLink().href = DEFAULT_FAVICON;
      }
    };
  }, [tenant, suffix]);
}
