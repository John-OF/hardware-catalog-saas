import { useEffect } from 'react';
import type { Tenant } from '../types';

/**
 * Familias por opción de `theme.font` (PERS-6).
 *
 * Antes la fuente se aplicaba como `fontFamily` inline en el contenedor del
 * catálogo, así que todo lo que usaba `var(--font-heading)` (los títulos, que es
 * justo donde más se nota una tipografía) seguía en Outfit, y el resto de
 * pantallas públicas ni se enteraban.
 *
 * `google` es la familia que hay que cargar de Google Fonts; null si ya viene en
 * el import base de index.css o si es una fuente del sistema.
 */
const FONT_MAP: Record<string, { sans: string; heading: string; google: string | null }> = {
  sans: {
    sans: "'Inter', system-ui, -apple-system, sans-serif",
    heading: "'Outfit', 'Inter', sans-serif",
    google: null,
  },
  serif: {
    sans: "'Merriweather', Georgia, 'Times New Roman', serif",
    heading: "'Merriweather', Georgia, serif",
    google: 'Merriweather:wght@300;400;700',
  },
  mono: {
    sans: "'Fira Code', Consolas, Monaco, monospace",
    heading: "'Fira Code', Consolas, Monaco, monospace",
    google: 'Fira+Code:wght@400;500;600',
  },
  heading: {
    sans: "'Outfit', 'Inter', sans-serif",
    heading: "'Outfit', 'Montserrat', sans-serif",
    google: null,
  },
};

const FONT_LINK_ID = 'tenant-font';

/**
 * Carga una familia de Google Fonts solo cuando la tienda la usa.
 *
 * Se hace bajo demanda y no en el `@import` de index.css para no pagar la
 * descarga de Merriweather y Fira Code en las tiendas que no las eligieron.
 */
function loadFontFamily(googleFamily: string | null) {
  const existing = document.getElementById(FONT_LINK_ID) as HTMLLinkElement | null;

  if (!googleFamily) {
    existing?.remove();
    return;
  }

  const href = `https://fonts.googleapis.com/css2?family=${googleFamily}&display=swap`;

  if (existing) {
    if (existing.href !== href) existing.href = href;
    return;
  }

  const link = document.createElement('link');
  link.id = FONT_LINK_ID;
  link.rel = 'stylesheet';
  link.href = href;
  document.head.appendChild(link);
}

/**
 * Aplica los estilos y colores dinámicos del tenant al documento.
 * Maneja la inyección de variables CSS, la tipografía y el modo claro/oscuro.
 */
export function useTenantTheme(tenant?: Tenant | null) {
  useEffect(() => {
    if (!tenant) return;

    const root = document.documentElement;

    if (tenant.primary_color) {
      root.style.setProperty('--primary', tenant.primary_color);
      // Generar un glow rgb a partir de hexadecimal
      const hex = tenant.primary_color.replace('#', '');
      const r = parseInt(hex.substring(0, 2), 16);
      const g = parseInt(hex.substring(2, 4), 16);
      const b = parseInt(hex.substring(4, 6), 16);
      root.style.setProperty('--primary-glow', `rgba(${r}, ${g}, ${b}, 0.15)`);
    }

    if (tenant.theme?.accent_color) {
      root.style.setProperty('--accent', tenant.theme.accent_color);
    }

    // Tipografía: se inyecta como variable para que la hereden TODAS las vistas
    // públicas y, sobre todo, los encabezados (PERS-6).
    const font = FONT_MAP[tenant.theme?.font ?? 'sans'] ?? FONT_MAP.sans;
    root.style.setProperty('--font-sans', font.sans);
    root.style.setProperty('--font-heading', font.heading);
    loadFontFamily(font.google);

    // Modo claro/oscuro: se aplica al body para abarcar todo el viewport
    const isLight = tenant.theme?.color_mode === 'light';
    document.body.classList.toggle('light-mode', isLight);

    return () => {
      document.body.classList.remove('light-mode');
      // Se devuelven las variables a los valores de index.css: si no, al pasar
      // del catálogo público al panel el dashboard heredaría la fuente y el
      // color de la última tienda visitada.
      root.style.removeProperty('--font-sans');
      root.style.removeProperty('--font-heading');
      root.style.removeProperty('--primary');
      root.style.removeProperty('--primary-glow');
      root.style.removeProperty('--accent');
      document.getElementById(FONT_LINK_ID)?.remove();
    };
  }, [tenant]);
}
