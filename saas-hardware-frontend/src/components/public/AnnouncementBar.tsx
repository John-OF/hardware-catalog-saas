import type { TenantTheme } from '../../types';
import { announcementStyleOf, announcementText } from '../../utils/branding';

/**
 * Franja de anuncios sobre el catálogo (PERS-7 / 10.4).
 *
 * Va por encima del header y no dentro, porque el header es una `.glass-card`
 * flotante con sus propias esquinas: metida ahí, la franja hereda el redondeo y
 * dejaría de leerse como una banda. Suelta y a ancho completo del contenedor es
 * como se lee "esto es un aviso de la tienda" sin ninguna etiqueta que lo diga.
 *
 * Sin texto no pinta nada —ni el hueco—, así que las tiendas que no la usen no
 * notan que existe.
 *
 * Los tres colores salen de `branding.ts` como estilos en línea, no de clases
 * CSS: los comparte con la muestra del panel, que no carga este `<style>`.
 */
export default function AnnouncementBar({ theme }: { theme?: TenantTheme | null }) {
  const texto = announcementText(theme);

  if (!texto) return null;

  const estilo = announcementStyleOf(theme?.announcement_style);

  return (
    <>
      <div
        className="announcement-bar"
        role="status"
        style={{ background: estilo.bg, color: estilo.fg, borderColor: estilo.border }}
      >
        {texto}
      </div>

      <style>{`
        .announcement-bar {
          display: flex; align-items: center; justify-content: center; gap: 0.5rem;
          padding: 0.6rem 1.25rem; margin-bottom: 1rem;
          border: 1px solid transparent; border-radius: var(--radius-md);
          font-size: 0.85rem; font-weight: 600; line-height: 1.35; text-align: center;
        }
        @media (max-width: 768px) {
          .announcement-bar { padding: 0.55rem 1rem; font-size: 0.8rem; }
        }
      `}</style>
    </>
  );
}
