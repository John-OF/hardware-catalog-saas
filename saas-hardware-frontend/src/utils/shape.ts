import type { TenantCardStyle, TenantDensity, TenantRadius, TenantTheme } from '../types';

/**
 * Forma de la tienda (PERS-4 / 10.1): radio de bordes, estilo de tarjeta y
 * densidad.
 *
 * Mismo reparto que en el tono neutral: aquí solo viven las etiquetas y **los
 * valores están en `index.css`** (`.radius-*`, `.cards-*`, `.density-*`). Las
 * miniaturas del selector aplican esas mismas clases sobre un `.glass-card` de
 * verdad, así que no pueden desfasarse de lo que ve el comprador.
 *
 * Los tres por defecto son los de siempre (`soft`, `glass`, `normal`): una
 * tienda ya dada de alta no cambia de aspecto hasta que su dueño toque algo.
 */
export const RADII: { value: TenantRadius; label: string; hint: string }[] = [
  { value: 'sharp', label: 'Afilado', hint: 'Esquinas rectas' },
  { value: 'soft', label: 'Suave', hint: 'Lo de siempre' },
  { value: 'round', label: 'Redondeado', hint: 'Muy curvo' },
];

export const CARD_STYLES: { value: TenantCardStyle; label: string; hint: string }[] = [
  { value: 'glass', label: 'Cristal', hint: 'Translúcida, con desenfoque' },
  { value: 'solid', label: 'Sólida', hint: 'Opaca, con sombra' },
  { value: 'flat', label: 'Plana', hint: 'Opaca, solo borde' },
];

export const DENSITIES: { value: TenantDensity; label: string }[] = [
  { value: 'compact', label: 'Compacta' },
  { value: 'normal', label: 'Normal' },
  { value: 'comfortable', label: 'Amplia' },
];

export const DEFAULT_RADIUS: TenantRadius = 'soft';
export const DEFAULT_CARD_STYLE: TenantCardStyle = 'glass';
export const DEFAULT_DENSITY: TenantDensity = 'normal';

function classOf<T extends string>(
  prefijo: string,
  valor: string | null | undefined,
  opciones: { value: T }[],
  porDefecto: T,
): string {
  const conocido = opciones.some((o) => o.value === valor);
  return `${prefijo}-${conocido ? valor : porDefecto}`;
}

export const radiusClass = (v?: string | null) => classOf('radius', v, RADII, DEFAULT_RADIUS);
export const cardStyleClass = (v?: string | null) => classOf('cards', v, CARD_STYLES, DEFAULT_CARD_STYLE);
export const densityClass = (v?: string | null) => classOf('density', v, DENSITIES, DEFAULT_DENSITY);

/** Las tres clases de forma que le tocan a un theme. */
export function shapeClasses(theme?: TenantTheme | null): string[] {
  return [
    radiusClass(theme?.radius),
    cardStyleClass(theme?.card_style),
    densityClass(theme?.density),
  ];
}

/** Todas las clases posibles, para limpiar antes de aplicar las nuevas. */
export const SHAPE_CLASSES = [
  ...RADII.map((r) => `radius-${r.value}`),
  ...CARD_STYLES.map((c) => `cards-${c.value}`),
  ...DENSITIES.map((d) => `density-${d.value}`),
];
