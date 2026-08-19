import type {
  TenantCardStyle,
  TenantColorMode,
  TenantFontFamily,
  TenantLayout,
  TenantNeutral,
  TenantHeroStyle,
  TenantRadius,
} from '../types';

/**
 * Temas prediseñados (PERS-3 / 9.3).
 *
 * Cada preset es una combinación **curada** de las perillas que el dueño ya
 * puede tocar una a una. No añade ningún campo nuevo al `theme`: aplicar un
 * preset es rellenar el formulario de Configuración de golpe, y a partir de ahí
 * se sigue editando lo que sea. Por eso el backend no necesita nada para esto.
 *
 * Los tipos salen de las mismas uniones que valida `TenantController::update`,
 * así que un valor inventado (`neutral: 'forest'`, `layout: 'masonry'`) no
 * compila: `tsc -b` corre en CI y lo caza antes de que el dueño se coma un 422.
 * Lo que el tipo NO puede comprobar es el formato del hex; el backend exige
 * `#rrggbb` de seis dígitos.
 *
 * Con `hero_style` (10.2) ya están dentro las tres perillas que el reporte pedía
 * para los presets: entró igual que `radius` y `card_style` al cerrarse 10.1 —
 * un campo más en la interfaz, uno en cada preset y nada que tocar del resto.
 *
 * En 10.3 el `font` de pareja cerrada se parte en `font_heading` y `font_body`,
 * que es lo que permite que un preset proponga una **combinación**: "Boutique"
 * lleva Playfair en los títulos y Lora en el texto, que es una decisión de
 * diseño que la perilla vieja no sabía expresar.
 *
 * **`density` queda fuera a propósito**: el radio y el estilo de tarjeta son
 * identidad (hacen que la tienda parezca de otra marca), pero cuántos productos
 * caben en pantalla es preferencia de quien la lleva y no tiene por qué cambiar
 * porque le guste una combinación de colores. Aplicar un preset respeta la
 * densidad que el dueño tuviera puesta.
 */
export interface ThemePreset {
  id: string;
  name: string;
  /** Para quién es, en una línea. Es lo que decide al dueño, no los hex. */
  hint: string;
  primary_color: string;
  accent_color: string;
  neutral: TenantNeutral;
  color_mode: TenantColorMode;
  font_heading: TenantFontFamily;
  font_body: TenantFontFamily;
  layout: TenantLayout;
  radius: TenantRadius;
  card_style: TenantCardStyle;
  hero_style: TenantHeroStyle;
}

export const THEME_PRESETS: ThemePreset[] = [
  {
    id: 'gamer',
    name: 'Gamer',
    hint: 'Violeta RGB sobre oscuro, tarjetas grandes',
    primary_color: '#8b5cf6',
    accent_color: '#22d3ee',
    neutral: 'plum',
    color_mode: 'dark',
    font_heading: 'space-grotesk',
    font_body: 'inter',
    layout: 'grid',
    radius: 'round',
    card_style: 'glass',
    hero_style: 'centered',
  },
  {
    id: 'neon',
    name: 'Neón',
    hint: 'Verde terminal y tipografía mono, grilla densa',
    primary_color: '#22c55e',
    accent_color: '#a3e635',
    neutral: 'zinc',
    color_mode: 'dark',
    font_heading: 'fira-code',
    font_body: 'fira-code',
    layout: 'compact',
    radius: 'sharp',
    card_style: 'flat',
    hero_style: 'split',
  },
  {
    id: 'medianoche',
    name: 'Medianoche',
    hint: 'Azul profundo y celeste, sobrio y elegante',
    primary_color: '#38bdf8',
    accent_color: '#818cf8',
    neutral: 'navy',
    color_mode: 'dark',
    font_heading: 'outfit',
    font_body: 'inter',
    layout: 'grid',
    radius: 'soft',
    card_style: 'glass',
    hero_style: 'classic',
  },
  {
    id: 'taller',
    name: 'Taller',
    hint: 'Naranja industrial sobre gris cálido',
    primary_color: '#f97316',
    accent_color: '#f59e0b',
    neutral: 'stone',
    color_mode: 'dark',
    font_heading: 'montserrat',
    font_body: 'inter',
    layout: 'compact',
    radius: 'sharp',
    card_style: 'solid',
    hero_style: 'classic',
  },
  {
    // Ojo con los nombres: el selector de plantilla ya llama "Mayorista" al
    // layout `compact` y "Boutique" al `grid`. Un preset llamado "Mayorista"
    // que usara `list` haría que dos cosas distintas se llamaran igual en la
    // misma pantalla.
    id: 'distribuidor',
    name: 'Distribuidor',
    hint: 'Claro y azul, en lista densa para ver precios',
    primary_color: '#2563eb',
    accent_color: '#0ea5e9',
    neutral: 'slate',
    color_mode: 'light',
    font_heading: 'inter',
    font_body: 'inter',
    layout: 'list',
    radius: 'sharp',
    card_style: 'flat',
    hero_style: 'minimal',
  },
  {
    id: 'corporativo',
    name: 'Corporativo',
    hint: 'Azul marino claro, serio y de confianza',
    primary_color: '#1d4ed8',
    accent_color: '#0891b2',
    neutral: 'navy',
    color_mode: 'light',
    font_heading: 'lora',
    font_body: 'inter',
    layout: 'grid',
    radius: 'soft',
    card_style: 'solid',
    hero_style: 'split',
  },
  {
    id: 'boutique',
    name: 'Boutique',
    hint: 'Ámbar sobre papel cálido, con serif editorial',
    primary_color: '#b45309',
    accent_color: '#a16207',
    neutral: 'stone',
    color_mode: 'light',
    font_heading: 'playfair',
    font_body: 'lora',
    layout: 'grid',
    radius: 'round',
    card_style: 'solid',
    hero_style: 'centered',
  },
  {
    id: 'minimal',
    name: 'Minimal',
    hint: 'Monocromo blanco y negro, sin distracciones',
    primary_color: '#18181b',
    accent_color: '#71717a',
    neutral: 'zinc',
    color_mode: 'light',
    font_heading: 'inter',
    font_body: 'inter',
    layout: 'compact',
    radius: 'soft',
    card_style: 'flat',
    hero_style: 'minimal',
  },
];

/** Los campos que un preset escribe. Lo que no esté aquí, no lo toca. */
export type PresetValues = Pick<
  ThemePreset,
  | 'primary_color' | 'accent_color' | 'neutral' | 'color_mode'
  | 'font_heading' | 'font_body' | 'layout' | 'radius' | 'card_style' | 'hero_style'
>;

/**
 * Preset cuyos valores coinciden exactamente con los del formulario, o null.
 *
 * Sirve para marcar cuál está aplicado. Devolver null en cuanto el dueño cambia
 * un solo color es lo correcto: la ficha resaltada significa "tu tienda se ve
 * así", y dejarla marcada tras un retoque seria mentir.
 */
export function matchingPreset(values: PresetValues): ThemePreset | null {
  return THEME_PRESETS.find((p) =>
    p.primary_color.toLowerCase() === values.primary_color.toLowerCase()
    && p.accent_color.toLowerCase() === values.accent_color.toLowerCase()
    && p.neutral === values.neutral
    && p.color_mode === values.color_mode
    && p.font_heading === values.font_heading
    && p.font_body === values.font_body
    && p.layout === values.layout
    && p.radius === values.radius
    && p.card_style === values.card_style
    && p.hero_style === values.hero_style
  ) ?? null;
}
