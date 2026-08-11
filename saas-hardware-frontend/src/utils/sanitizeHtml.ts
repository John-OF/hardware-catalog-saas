import DOMPurify from 'dompurify';

/**
 * Limpia el HTML que viene de la API antes de inyectarlo con dangerouslySetInnerHTML.
 *
 * El servidor ya sanitiza al guardar (cast SanitizedHtml + HTMLPurifier), asi que
 * esto es el cinturon: cubre el contenido que se guardo ANTES de aquel cambio y
 * cualquier via de escritura que se escape en el futuro.
 *
 * La whitelist es la misma que el perfil 'store_content' del backend; si cambia
 * una, hay que cambiar la otra.
 */
const ALLOWED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'h3', 'h4', 'h5', 'a'];
const ALLOWED_ATTR = ['href', 'title'];

export function sanitizeHtml(html: string): string {
  return DOMPurify.sanitize(html, { ALLOWED_TAGS, ALLOWED_ATTR });
}
