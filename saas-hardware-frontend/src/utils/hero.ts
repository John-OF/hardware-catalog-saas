import type { TenantHeroStyle } from '../types';

/**
 * Estilos de portada (PERS-5 / 10.2).
 *
 * Mismo reparto que en el tono y la forma: aquí viven las etiquetas y **las
 * medidas están en el bloque `<style>` de `CatalogPage`** (`.hero-classic`,
 * `.hero-centered`, `.hero-split`, `.hero-minimal`). A diferencia de la forma,
 * la clase no va al `<body>` sino a la propia `<section>` del hero: solo la
 * afecta a ella, y ponerla en el body obligaría a limpiarla al salir.
 *
 * `classic` es el hero de siempre, así que una tienda ya dada de alta no cambia
 * de portada hasta que su dueño toque algo.
 */

/**
 * Qué hace cada estilo con `banner_url`, que es la diferencia que más sorprende
 * al dueño si no se dice: el banner no desaparece por un fallo, es que ese
 * diseño no lo usa (o lo usa de otra forma).
 *
 * - `background`: foto a sangre con un velo oscuro encima y el texto dentro.
 * - `side`: foto en su propia columna, al lado del texto.
 * - `none`: no se pinta; el banner sigue guardado por si vuelve a otro estilo.
 */
export type HeroBannerUse = 'background' | 'side' | 'none';

export interface HeroStyleDef {
  value: TenantHeroStyle;
  label: string;
  hint: string;
  banner: HeroBannerUse;
}

export const HERO_STYLES: HeroStyleDef[] = [
  { value: 'classic', label: 'Clásico', hint: 'Texto a la izquierda sobre la imagen', banner: 'background' },
  { value: 'centered', label: 'Centrado', hint: 'Texto centrado y portada más alta', banner: 'background' },
  { value: 'split', label: 'Partido', hint: 'Texto e imagen lado a lado', banner: 'side' },
  { value: 'minimal', label: 'Mínimo', hint: 'Solo el título, sin tarjeta ni imagen', banner: 'none' },
];

/** El hero histórico: sin elegir nada, la portada se ve como se veía. */
export const DEFAULT_HERO_STYLE: TenantHeroStyle = 'classic';

/**
 * Definición del estilo, con caída al de por defecto ante un valor desconocido.
 *
 * `theme` es una columna JSON y puede traer lo que guardó una versión anterior;
 * sin esta caída el hero se quedaría sin clase y perdería hasta el diseño
 * clásico, que es justo lo que ese valor viejo quería decir.
 */
export function heroStyleOf(value?: string | null): HeroStyleDef {
  return HERO_STYLES.find((h) => h.value === value)
    ?? HERO_STYLES.find((h) => h.value === DEFAULT_HERO_STYLE)
    ?? HERO_STYLES[0];
}

/** Clase CSS del estilo, para la `<section>` del hero. */
export const heroClass = (value?: string | null) => `hero-${heroStyleOf(value).value}`;
