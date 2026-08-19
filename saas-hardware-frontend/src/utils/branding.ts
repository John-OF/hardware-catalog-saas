import type { TenantAnnouncementStyle, TenantTheme } from '../types';

/**
 * Elementos de marca de la tienda (PERS-7 / 10.4): la franja de anuncios y los
 * datos del pie.
 *
 * Mismo reparto que el resto de la personalización: aquí viven las etiquetas y
 * la lista de redes, y los colores están en el `<style>` de `AnnouncementBar`.
 * El panel y la tienda pública leen los dos de aquí, así que un campo nuevo se
 * añade en un solo sitio y aparece en los dos.
 */

/**
 * Los colores van aquí y no en el `<style>` de la franja porque los pintan dos
 * sitios: la tienda y la muestra del selector de Configuración. En CSS harían
 * falta las reglas en los dos —el panel no carga el `<style>` de un componente
 * público— y la muestra podría acabar prometiendo un color que la tienda ya no
 * usa. Son tokens, así que siguen al tema de cada tienda igual que todo lo demás.
 */
export interface AnnouncementStyleDef {
  value: TenantAnnouncementStyle;
  label: string;
  hint: string;
  bg: string;
  fg: string;
  border: string;
}

export const ANNOUNCEMENT_STYLES: AnnouncementStyleDef[] = [
  {
    value: 'primary',
    label: 'Color principal',
    hint: 'La franja que más se ve',
    bg: 'var(--primary)',
    // El blanco de 9.1: el que va ENCIMA de un fondo de color solido, y que por
    // eso no cambia en modo claro como lo haria --text-primary.
    fg: 'var(--text-on-primary)',
    border: 'transparent',
  },
  {
    value: 'accent',
    label: 'Color de acento',
    hint: 'Destaca sin competir con los botones',
    bg: 'var(--accent)',
    fg: 'var(--text-on-primary)',
    border: 'transparent',
  },
  {
    value: 'neutral',
    label: 'Discreta',
    hint: 'Gris de tarjeta, para avisos',
    // La unica que va sobre el fondo de la pagina: lleva texto normal y borde
    // para no quedarse flotando.
    bg: 'var(--bg-card)',
    fg: 'var(--text-primary)',
    border: 'var(--border)',
  },
];

export const DEFAULT_ANNOUNCEMENT_STYLE: TenantAnnouncementStyle = 'primary';

/** Los colores de la franja, con caída al de por defecto ante un valor desconocido. */
export const announcementStyleOf = (value?: string | null): AnnouncementStyleDef =>
  ANNOUNCEMENT_STYLES.find((s) => s.value === value)
  ?? ANNOUNCEMENT_STYLES.find((s) => s.value === DEFAULT_ANNOUNCEMENT_STYLE)
  ?? ANNOUNCEMENT_STYLES[0];

/**
 * El texto de la franja, o null si no hay nada que anunciar.
 *
 * **La franja se enciende y se apaga con el texto**, sin un booleano aparte.
 * Un interruptor separado obliga a explicar por qué un mensaje escrito no sale,
 * y ese es el fallo que más soporte genera de todos los de esta pantalla. El
 * precio es que apagarla borra el texto; a cambio, lo que se ve en el panel es
 * exactamente lo que hay en la tienda.
 */
export const announcementText = (theme?: TenantTheme | null): string | null =>
  theme?.announcement?.trim() || null;

/** Las claves de red social del theme. */
export type SocialKey = 'footer_facebook' | 'footer_instagram' | 'footer_tiktok';

export interface SocialNetworkDef {
  key: SocialKey;
  label: string;
  placeholder: string;
}

/**
 * Las redes que ofrece el pie, en orden.
 *
 * Se guarda el enlace completo y no el usuario: armar la URL a partir de un
 * "@mitienda" obliga a saber el formato de cada red (y a rehacerlo cuando una
 * lo cambie), y un enlace roto en el pie de la tienda no lo detecta nadie. Con
 * la URL entera, el dueño pega lo que ve en su navegador.
 */
export const SOCIAL_NETWORKS: SocialNetworkDef[] = [
  { key: 'footer_facebook',  label: 'Facebook',  placeholder: 'https://facebook.com/mitienda' },
  { key: 'footer_instagram', label: 'Instagram', placeholder: 'https://instagram.com/mitienda' },
  { key: 'footer_tiktok',    label: 'TikTok',    placeholder: 'https://tiktok.com/@mitienda' },
];

/** Las redes que esta tienda tiene puestas, listas para pintar. */
export const socialLinks = (theme?: TenantTheme | null) =>
  SOCIAL_NETWORKS
    .map((red) => ({ ...red, url: theme?.[red.key]?.trim() ?? '' }))
    .filter((red) => red.url !== '');

/** Los datos de contacto del pie que la tienda tiene rellenos. */
export const footerData = (theme?: TenantTheme | null) => ({
  address: theme?.footer_address?.trim() || null,
  hours:   theme?.footer_hours?.trim() || null,
  taxId:   theme?.footer_tax_id?.trim() || null,
});

/**
 * ¿Hay algo de marca que enseñar en el pie?
 *
 * Solo cuentan los campos de 10.4. El WhatsApp de la tienda **no** se pinta
 * aquí aunque siempre exista: lo lleva el botón "Contactar" del header en todas
 * las páginas que tienen pie, y meterlo le habría cambiado el pie a todas las
 * tiendas ya dadas de alta sin que su dueño pidiera nada.
 */
export const hasFooterData = (theme?: TenantTheme | null): boolean => {
  const datos = footerData(theme);

  return Boolean(datos.address || datos.hours || datos.taxId || socialLinks(theme).length);
};
