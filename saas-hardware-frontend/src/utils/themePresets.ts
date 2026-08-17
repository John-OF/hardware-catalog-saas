import type { TenantColorMode, TenantFont, TenantLayout, TenantNeutral } from '../types';

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
 * `radius` y `hero_style` que menciona el reporte NO están: son campos de 10.1 y
 * 10.2, que todavía no existen. Cuando se implementen, se añaden aquí y cada
 * preset gana una perilla más sin tocar nada del resto.
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
  font: TenantFont;
  layout: TenantLayout;
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
    font: 'heading',
    layout: 'grid',
  },
  {
    id: 'neon',
    name: 'Neón',
    hint: 'Verde terminal y tipografía mono, grilla densa',
    primary_color: '#22c55e',
    accent_color: '#a3e635',
    neutral: 'zinc',
    color_mode: 'dark',
    font: 'mono',
    layout: 'compact',
  },
  {
    id: 'medianoche',
    name: 'Medianoche',
    hint: 'Azul profundo y celeste, sobrio y elegante',
    primary_color: '#38bdf8',
    accent_color: '#818cf8',
    neutral: 'navy',
    color_mode: 'dark',
    font: 'sans',
    layout: 'grid',
  },
  {
    id: 'taller',
    name: 'Taller',
    hint: 'Naranja industrial sobre gris cálido',
    primary_color: '#f97316',
    accent_color: '#f59e0b',
    neutral: 'stone',
    color_mode: 'dark',
    font: 'sans',
    layout: 'compact',
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
    font: 'sans',
    layout: 'list',
  },
  {
    id: 'corporativo',
    name: 'Corporativo',
    hint: 'Azul marino claro, serio y de confianza',
    primary_color: '#1d4ed8',
    accent_color: '#0891b2',
    neutral: 'navy',
    color_mode: 'light',
    font: 'sans',
    layout: 'grid',
  },
  {
    id: 'boutique',
    name: 'Boutique',
    hint: 'Ámbar sobre papel cálido, con serif editorial',
    primary_color: '#b45309',
    accent_color: '#a16207',
    neutral: 'stone',
    color_mode: 'light',
    font: 'serif',
    layout: 'grid',
  },
  {
    id: 'minimal',
    name: 'Minimal',
    hint: 'Monocromo blanco y negro, sin distracciones',
    primary_color: '#18181b',
    accent_color: '#71717a',
    neutral: 'zinc',
    color_mode: 'light',
    font: 'sans',
    layout: 'compact',
  },
];

/** Los campos que un preset escribe. Lo que no esté aquí, no lo toca. */
export type PresetValues = Pick<
  ThemePreset,
  'primary_color' | 'accent_color' | 'neutral' | 'color_mode' | 'font' | 'layout'
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
    && p.font === values.font
    && p.layout === values.layout
  ) ?? null;
}
