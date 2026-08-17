import type { TenantFont } from '../types';

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
 *
 * Vive aquí y no dentro de useTenantTheme porque la galería de temas
 * prediseñados (9.3) pinta cada miniatura con la fuente real del preset.
 */
export const FONT_MAP: Record<TenantFont, {
  sans: string;
  heading: string;
  google: string | null;
  /** Nombre corto para las fichas de preset; el selector usa textos más largos. */
  label: string;
}> = {
  sans: {
    sans: "'Inter', system-ui, -apple-system, sans-serif",
    heading: "'Outfit', 'Inter', sans-serif",
    google: null,
    label: 'Sans',
  },
  serif: {
    sans: "'Merriweather', Georgia, 'Times New Roman', serif",
    heading: "'Merriweather', Georgia, serif",
    google: 'Merriweather:wght@300;400;700',
    label: 'Serif',
  },
  mono: {
    sans: "'Fira Code', Consolas, Monaco, monospace",
    heading: "'Fira Code', Consolas, Monaco, monospace",
    google: 'Fira+Code:wght@400;500;600',
    label: 'Mono',
  },
  heading: {
    sans: "'Outfit', 'Inter', sans-serif",
    heading: "'Outfit', 'Montserrat', sans-serif",
    google: null,
    label: 'Display',
  },
};

export const DEFAULT_FONT: TenantFont = 'sans';

export function fontOf(font?: string | null) {
  return FONT_MAP[(font ?? DEFAULT_FONT) as TenantFont] ?? FONT_MAP[DEFAULT_FONT];
}
