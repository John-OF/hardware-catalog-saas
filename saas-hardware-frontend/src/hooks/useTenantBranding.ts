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

function updateMetaTags(title: string, description: string, imageUrl?: string | null) {
  // Update Meta Description
  let descMeta = document.querySelector('meta[name="description"]');
  if (!descMeta) {
    descMeta = document.createElement('meta');
    descMeta.setAttribute('name', 'description');
    document.head.appendChild(descMeta);
  }
  descMeta.setAttribute('content', description);

  // Update OG Title
  let ogTitle = document.querySelector('meta[property="og:title"]');
  if (!ogTitle) {
    ogTitle = document.createElement('meta');
    ogTitle.setAttribute('property', 'og:title');
    document.head.appendChild(ogTitle);
  }
  ogTitle.setAttribute('content', title);

  // Update OG Description
  let ogDesc = document.querySelector('meta[property="og:description"]');
  if (!ogDesc) {
    ogDesc = document.createElement('meta');
    ogDesc.setAttribute('property', 'og:description');
    document.head.appendChild(ogDesc);
  }
  ogDesc.setAttribute('content', description);

  // Update OG Image
  if (imageUrl) {
    let ogImage = document.querySelector('meta[property="og:image"]');
    if (!ogImage) {
      ogImage = document.createElement('meta');
      ogImage.setAttribute('property', 'og:image');
      document.head.appendChild(ogImage);
    }
    ogImage.setAttribute('content', imageUrl);
  }

  // Update OG URL
  let ogUrl = document.querySelector('meta[property="og:url"]');
  if (!ogUrl) {
    ogUrl = document.createElement('meta');
    ogUrl.setAttribute('property', 'og:url');
    document.head.appendChild(ogUrl);
  }
  ogUrl.setAttribute('content', window.location.href);
}

/**
 * Aplica el branding de la pestaña del navegador (título y favicon) y meta tags SEO
 * según el theme del tenant. Si se pasa `suffix` (ej. el nombre del producto), se
 * antepone al título: "Producto · Mi Tienda".
 */
export function useTenantBranding(
  tenant?: Tenant | null, 
  suffix?: string, 
  description?: string | null, 
  imageUrl?: string | null
) {
  useEffect(() => {
    if (!tenant) return;

    const baseTitle = tenant.theme?.page_title?.trim() || tenant.name;
    const finalTitle = suffix ? `${suffix} · ${baseTitle}` : baseTitle;
    document.title = finalTitle;

    const baseDesc = tenant.theme?.hero_subtitle || `Catálogo oficial de ${tenant.name}.`;
    const finalDesc = description 
      ? description.replace(/<[^>]*>/g, '').substring(0, 160) 
      : baseDesc;

    const finalImage = imageUrl || tenant.logo_url;

    // Actualizar meta tags dinámicamente en el cliente
    updateMetaTags(finalTitle, finalDesc, finalImage);

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
  }, [tenant, suffix, description, imageUrl]);
}
