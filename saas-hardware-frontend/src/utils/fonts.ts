import type { TenantFont, TenantFontFamily, TenantTheme } from '../types';

/**
 * Catálogo tipográfico de las tiendas (PERS-6 / 10.3).
 *
 * Antes había **una** perilla (`theme.font`) que elegía una pareja cerrada:
 * "serif" significaba Merriweather arriba y abajo. Ahora la unidad es la
 * familia, y la tienda elige dos por separado — `theme.font_heading` para los
 * títulos y `theme.font_body` para el texto —, que es lo que permite la
 * combinación display + texto que hace que dos tiendas no se lean igual.
 *
 * `stack` lleva fallbacks reales del sistema a propósito: si Google Fonts no
 * responde, la tienda no debe caer a Times New Roman cuando eligió una sans.
 *
 * `google` es lo que hay que pedirle a `css2`, o null si la familia ya viene en
 * el `@import` base de index.css (Inter y Outfit, que usa también el panel) o
 * fuera del sistema. Solo se descargan las familias que la tienda usa de
 * verdad: ver `loadGoogleFonts`.
 *
 * Vive aquí y no dentro de useTenantTheme porque estas familias las pintan
 * también las miniaturas del panel: la galería de temas prediseñados (9.3) y
 * los dos selectores de Configuración.
 */
export interface FontFamilyDef {
  value: TenantFontFamily;
  /** Nombre corto de la familia; es el que se lee en el selector y en las fichas. */
  label: string;
  /** Cómo se ve, en tres o cuatro palabras. Es lo que decide al dueño, no el nombre. */
  hint: string;
  stack: string;
  google: string | null;
}

/**
 * El orden es el del selector, y va por tipo: primero las sans, luego las
 * serif y al final la mono. Un dueño busca "algo con serifas", no un nombre.
 */
export const FONT_FAMILIES: FontFamilyDef[] = [
  {
    value: 'inter',
    label: 'Inter',
    hint: 'Sans neutra y compacta',
    stack: "'Inter', system-ui, -apple-system, sans-serif",
    google: null,
  },
  {
    value: 'outfit',
    label: 'Outfit',
    hint: 'Sans geométrica, de marca',
    stack: "'Outfit', 'Inter', system-ui, sans-serif",
    google: null,
  },
  {
    value: 'space-grotesk',
    label: 'Space Grotesk',
    hint: 'Sans técnica, con carácter',
    stack: "'Space Grotesk', 'Outfit', system-ui, sans-serif",
    google: 'Space+Grotesk:wght@400;500;700',
  },
  {
    value: 'montserrat',
    label: 'Montserrat',
    hint: 'Sans ancha y rotunda',
    stack: "'Montserrat', 'Inter', system-ui, sans-serif",
    google: 'Montserrat:wght@400;600;700',
  },
  {
    value: 'playfair',
    label: 'Playfair Display',
    hint: 'Serif de revista, con contraste',
    stack: "'Playfair Display', Georgia, 'Times New Roman', serif",
    google: 'Playfair+Display:wght@400;600;700',
  },
  {
    value: 'lora',
    label: 'Lora',
    hint: 'Serif cálida, cómoda de leer',
    stack: "'Lora', Georgia, 'Times New Roman', serif",
    google: 'Lora:wght@400;600;700',
  },
  {
    value: 'merriweather',
    label: 'Merriweather',
    hint: 'Serif robusta y densa',
    stack: "'Merriweather', Georgia, 'Times New Roman', serif",
    google: 'Merriweather:wght@300;400;700',
  },
  {
    value: 'fira-code',
    label: 'Fira Code',
    hint: 'Monoespaciada, de terminal',
    stack: "'Fira Code', Consolas, Monaco, monospace",
    google: 'Fira+Code:wght@400;500;600',
  },
];

/**
 * La pareja que significaba cada valor de la perilla vieja (`theme.font`).
 *
 * No es historia muerta: es el **fallback** de las tiendas dadas de alta antes
 * de 10.3, que tienen `font` y no tienen las dos claves nuevas. Se expresa en
 * familias del catálogo de arriba en vez de repetir los stacks, así que hay una
 * sola definición de cada tipografía y no pueden desincronizarse.
 *
 * Comprobado que las cuatro parejas dan exactamente los mismos stacks que antes
 * de 10.3: ninguna tienda existente cambia de letra hasta que su dueño toque el
 * selector.
 */
const LEGACY_PAIRS: Record<TenantFont, { heading: TenantFontFamily; body: TenantFontFamily }> = {
  sans:    { heading: 'outfit',       body: 'inter' },
  serif:   { heading: 'merriweather', body: 'merriweather' },
  mono:    { heading: 'fira-code',    body: 'fira-code' },
  heading: { heading: 'outfit',       body: 'outfit' },
};

export const DEFAULT_FONT: TenantFont = 'sans';
export const DEFAULT_FONT_HEADING: TenantFontFamily = LEGACY_PAIRS[DEFAULT_FONT].heading;
export const DEFAULT_FONT_BODY: TenantFontFamily = LEGACY_PAIRS[DEFAULT_FONT].body;

/** La familia, o undefined si el valor no está en el catálogo. */
export const familyOf = (value?: string | null): FontFamilyDef | undefined =>
  FONT_FAMILIES.find((f) => f.value === value);

/**
 * Las dos familias que le tocan a un theme: título y cuerpo.
 *
 * El orden de preferencia importa y es lo único con enjundia de este fichero:
 * manda la clave nueva; si no está (tienda anterior a 10.3, o un valor que
 * guardó una versión futura y esta no conoce) se cae a la pareja vieja, y esa
 * a su vez al valor por defecto. `theme` es una columna JSON: puede traer
 * cualquier cosa, y quedarse sin fuente por un valor raro sería peor que
 * ignorarlo.
 */
export function resolveFonts(theme?: TenantTheme | null): { heading: FontFamilyDef; body: FontFamilyDef } {
  const pareja = LEGACY_PAIRS[(theme?.font ?? DEFAULT_FONT) as TenantFont] ?? LEGACY_PAIRS[DEFAULT_FONT];

  return {
    heading: familyOf(theme?.font_heading) ?? familyOf(pareja.heading) ?? FONT_FAMILIES[0],
    body:    familyOf(theme?.font_body)    ?? familyOf(pareja.body)    ?? FONT_FAMILIES[0],
  };
}

/**
 * Pone (o quita) un `<link>` de Google Fonts con exactamente las familias que
 * se le pasen, bajo el id indicado.
 *
 * Se hace bajo demanda y no en el `@import` de index.css para no pagar la
 * descarga de las seis familias opcionales en cada tienda; una que use Inter y
 * Outfit no pide nada. Las repetidas se descartan: cuando título y cuerpo
 * comparten familia sale una sola petición.
 *
 * El id lo pone quien llama porque hay dos consumidores con vidas distintas: la
 * tienda pública carga sus dos familias y el panel carga el catálogo entero
 * para que sus miniaturas no mientan.
 */
export function loadGoogleFonts(id: string, specs: (string | null | undefined)[]) {
  const existing = document.getElementById(id) as HTMLLinkElement | null;
  const familias = [...new Set(specs.filter((s): s is string => !!s))];

  if (familias.length === 0) {
    existing?.remove();
    return;
  }

  const href = `https://fonts.googleapis.com/css2?${familias.map((f) => `family=${f}`).join('&')}&display=swap`;

  if (existing) {
    if (existing.href !== href) existing.href = href;
    return;
  }

  const link = document.createElement('link');
  link.id = id;
  link.rel = 'stylesheet';
  link.href = href;
  document.head.appendChild(link);
}
