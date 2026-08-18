import { useEffect } from 'react';
import type { Tenant } from '../types';
import { NEUTRAL_CLASSES, neutralClass } from '../utils/neutrals';
import { SHAPE_CLASSES, shapeClasses } from '../utils/shape';
import { fontOf } from '../utils/fonts';

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
    const font = fontOf(tenant.theme?.font);
    root.style.setProperty('--font-sans', font.sans);
    root.style.setProperty('--font-heading', font.heading);
    loadFontFamily(font.google);

    // Tono neutral (PERS-2) y modo claro/oscuro: los dos van como clase en el
    // <body>, que es lo que abarca todo el viewport. La paleta estructural sale
    // de la combinación de ambas (`.neutral-stone.light-mode`, etc.), así que
    // tienen que aplicarse juntas y no por separado.
    //
    // Se hace con clases y no inyectando variables como el color o la fuente
    // porque son 13 tokens por tono: en CSS quedan al lado de `:root` y
    // `.light-mode`, que es donde alguien va a buscarlos.
    document.body.classList.remove(...NEUTRAL_CLASSES);
    document.body.classList.add(neutralClass(tenant.theme?.neutral));

    const isLight = tenant.theme?.color_mode === 'light';
    document.body.classList.toggle('light-mode', isLight);

    // Forma (PERS-4): radio de bordes, estilo de tarjeta y densidad. Van por el
    // mismo camino que el tono y por el mismo motivo, y son independientes
    // entre si: cualquier combinacion de las tres es valida.
    document.body.classList.remove(...SHAPE_CLASSES);
    document.body.classList.add(...shapeClasses(tenant.theme));

    return () => {
      document.body.classList.remove('light-mode');
      document.body.classList.remove(...NEUTRAL_CLASSES);
      document.body.classList.remove(...SHAPE_CLASSES);
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
