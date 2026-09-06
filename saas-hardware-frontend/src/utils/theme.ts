/**
 * Semilla del tema de la tienda antes del primer pintado (UI-2).
 *
 * El problema: `useTenantTheme` aplica la paleta desde un efecto, o sea despues
 * de que resuelva la peticion del tenant. El primer frame sale con los valores
 * por defecto de `:root` —que son los oscuros— y salta al tema real cuando
 * llegan los datos. En local es un parpadeo; con conexion lenta el visitante ve
 * la tienda entera cambiar de color.
 *
 * Lo que NO se puede hacer aqui: pintarlo en el servidor. `routes/web.php` solo
 * devuelve HTML a los crawlers y a las personas las redirige al SPA, asi que el
 * backend nunca entrega la pagina que ve el comprador y no hay donde sembrar
 * las variables. Subirlo a `useLayoutEffect` tampoco basta: lo que se espera es
 * la red, no un frame.
 *
 * Lo que si: recordar el tema de la ultima visita a esa tienda y aplicarlo
 * antes de montar React. La primera visita absoluta sigue teniendo el salto
 * —no hay dato del que tirar—, pero deja de tenerlo todo lo demas: recargar,
 * volver mas tarde, entrar desde un enlace compartido a otra pantalla de la
 * misma tienda.
 *
 * Es una cache de presentacion, nunca la fuente de verdad: cuando llega la
 * respuesta real, `useTenantTheme` reaplica y sobrescribe. Si el dueño cambio
 * los colores, el visitante ve los viejos durante lo que tarde la peticion, que
 * es exactamente lo que veia antes con los colores por defecto.
 */

import type { Tenant } from '../types';
import { NEUTRAL_CLASSES, neutralClass } from './neutrals';
import { SHAPE_CLASSES, shapeClasses } from './shape';
import { loadGoogleFonts, resolveFonts } from './fonts';

const STORAGE_PREFIX = 'theme:';
export const FONT_LINK_ID = 'tenant-font';

/** Lo minimo para pintar: nada de textos ni imagenes, solo la piel. */
export interface ApplicableTheme {
  primary_color?: string | null;
  accent_color?: string | null;
  color_mode?: string | null;
  neutral?: string | null;
  radius?: string | null;
  card_style?: string | null;
  density?: string | null;
  font?: string | null;
  font_heading?: string | null;
  font_body?: string | null;
}

/**
 * Primeros segmentos que NO son el slug de una tienda. `product`, `builder` y
 * `p` si lo son en un dominio propio, donde la URL va sin prefijo de tienda.
 */
const NON_STORE_SEGMENTS = new Set([
  'login', 'register', 'forgot-password', 'reset-password', 'platform', 'dashboard',
]);
const STORE_SEGMENTS_WITHOUT_SLUG = new Set(['product', 'builder', 'p']);

/**
 * Con que clave se recuerda la tienda que hay en pantalla, o null si esto no es
 * una pantalla de tienda. Devolver null importa tanto como acertar: el panel
 * tiene su propia paleta y sembrarle la de una tienda seria el fallo que la
 * limpieza de `useTenantTheme` viene evitando.
 */
export function storeThemeKey(
  pathname = window.location.pathname,
  hostname = window.location.hostname
): string | null {
  const isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1';
  const firstSegment = pathname.split('/').filter(Boolean)[0] ?? '';

  if (NON_STORE_SEGMENTS.has(firstSegment)) return null;
  // Dominio propio: la tienda es el dominio y la URL no lleva slug.
  if (firstSegment === '' || STORE_SEGMENTS_WITHOUT_SLUG.has(firstSegment)) {
    return isLocalhost ? null : `${STORAGE_PREFIX}domain:${hostname}`;
  }
  return `${STORAGE_PREFIX}slug:${firstSegment}`;
}

/**
 * Aplica la piel de la tienda al documento. Es la misma operacion que hace
 * `useTenantTheme`; vive aqui para que la semilla y el hook no puedan
 * divergir.
 */
export function applyTheme(theme: ApplicableTheme) {
  const root = document.documentElement;

  if (theme.primary_color) {
    root.style.setProperty('--primary', theme.primary_color);
    const hex = theme.primary_color.replace('#', '');
    const r = parseInt(hex.substring(0, 2), 16);
    const g = parseInt(hex.substring(2, 4), 16);
    const b = parseInt(hex.substring(4, 6), 16);
    if (!Number.isNaN(r) && !Number.isNaN(g) && !Number.isNaN(b)) {
      root.style.setProperty('--primary-glow', `rgba(${r}, ${g}, ${b}, 0.15)`);
    }
  }

  if (theme.accent_color) {
    root.style.setProperty('--accent', theme.accent_color);
  }

  const fonts = resolveFonts(theme as never);
  root.style.setProperty('--font-sans', fonts.body.stack);
  root.style.setProperty('--font-heading', fonts.heading.stack);
  loadGoogleFonts(FONT_LINK_ID, [fonts.heading.google, fonts.body.google]);

  document.body.classList.remove(...NEUTRAL_CLASSES);
  document.body.classList.add(neutralClass(theme.neutral));
  document.body.classList.toggle('light-mode', theme.color_mode === 'light');

  document.body.classList.remove(...SHAPE_CLASSES);
  document.body.classList.add(...shapeClasses(theme as never));
}

/** Deshace lo de arriba: el panel no debe heredar la piel de una tienda. */
export function clearTheme() {
  const root = document.documentElement;
  document.body.classList.remove('light-mode');
  document.body.classList.remove(...NEUTRAL_CLASSES);
  document.body.classList.remove(...SHAPE_CLASSES);
  root.style.removeProperty('--font-sans');
  root.style.removeProperty('--font-heading');
  root.style.removeProperty('--primary');
  root.style.removeProperty('--primary-glow');
  root.style.removeProperty('--accent');
  document.getElementById(FONT_LINK_ID)?.remove();
}

export function rememberTheme(tenant: Tenant) {
  const key = storeThemeKey();
  if (!key) return;

  const theme: ApplicableTheme = {
    primary_color: tenant.primary_color,
    accent_color: tenant.theme?.accent_color,
    color_mode: tenant.theme?.color_mode,
    neutral: tenant.theme?.neutral,
    radius: tenant.theme?.radius,
    card_style: tenant.theme?.card_style,
    density: tenant.theme?.density,
    font: tenant.theme?.font,
    font_heading: tenant.theme?.font_heading,
    font_body: tenant.theme?.font_body,
  };

  try {
    localStorage.setItem(key, JSON.stringify(theme));
  } catch {
    // Ventana privada, almacenamiento lleno o bloqueado: sin cache se vuelve al
    // comportamiento de antes, que es un parpadeo, no un fallo.
  }
}

/** Se llama una vez, antes de montar React. */
export function seedStoredTheme() {
  const key = storeThemeKey();
  if (!key) return;

  try {
    const stored = localStorage.getItem(key);
    if (!stored) return;
    applyTheme(JSON.parse(stored) as ApplicableTheme);
  } catch {
    // JSON corrupto o storage inaccesible: mismo caso que arriba.
  }
}
