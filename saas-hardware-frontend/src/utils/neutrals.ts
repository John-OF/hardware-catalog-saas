import type { TenantNeutral } from '../types';

/**
 * Tonos neutrales de la tienda (PERS-2).
 *
 * Aquí viven solo las etiquetas del selector: **los colores son los de
 * `index.css`** (`.neutral-<tono>` y `.neutral-<tono>.light-mode`). No duplicar
 * los hex en este archivo — la vista previa de Configuración pinta aplicando esas
 * mismas clases, así que no puede desincronizarse de lo que ve el comprador.
 *
 * Al añadir un tono hay que tocar cuatro sitios: esta lista, los dos bloques de
 * `index.css`, la unión `TenantNeutral` en `types/index.ts` y la whitelist de
 * `TenantController::update`. Si falta la del backend, guardar devuelve 422.
 */
export const NEUTRALS: { value: TenantNeutral; label: string; hint: string }[] = [
  { value: 'slate', label: 'Pizarra', hint: 'Azulado' },
  { value: 'zinc', label: 'Grafito', hint: 'Gris puro' },
  { value: 'stone', label: 'Arena', hint: 'Gris cálido' },
  { value: 'navy', label: 'Marino', hint: 'Azul profundo' },
  { value: 'plum', label: 'Ciruela', hint: 'Violáceo' },
];

/** El tono histórico: las tiendas que nunca lo eligieron se ven igual que antes. */
export const DEFAULT_NEUTRAL: TenantNeutral = 'slate';

/**
 * Clase CSS del tono, con caída al de por defecto ante un valor desconocido.
 *
 * `theme` es una columna JSON y puede traer lo que se guardó con una versión
 * anterior; sin esta caída el `<body>` se quedaría sin ninguna clase de paleta y
 * heredaría la de `:root`, que es justo la de `slate`. Se hace explícito para que
 * no dependa de esa coincidencia.
 */
export function neutralClass(neutral?: string | null): string {
  const known = NEUTRALS.some((n) => n.value === neutral);
  return `neutral-${known ? neutral : DEFAULT_NEUTRAL}`;
}

/** Todas las clases posibles, para limpiar antes de aplicar la nueva. */
export const NEUTRAL_CLASSES = NEUTRALS.map((n) => `neutral-${n.value}`);
