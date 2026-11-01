import { enlaceDeWhatsapp } from './plantillasWhatsapp';

/**
 * Cómo le escribe el dueño de una tienda a la plataforma para pagar o renovar
 * su plan (FUN-28, INF-23).
 *
 * Mientras no haya pasarela (7.7b), pagar es escribirle al operador, y hasta
 * aquí ninguna pantalla decía a quién: el aviso de la prueba decía «escríbenos»
 * sin un enlace. El WhatsApp sale de `VITE_PLATAFORMA_WHATSAPP`, y si no está, el
 * correo de contacto de las páginas legales (`VITE_LEGAL_CORREO`, INF-15). Son
 * datos del dueño de la plataforma: van en el build y no en el código, como esos.
 *
 * Si no hay ninguno, `enlaceDeContacto()` devuelve `null` y cada pantalla dice
 * lo que puede sin un botón que no lleva a ningún sitio.
 */

type Env = Record<string, unknown>;

export type ContactoDeLaPlataforma = {
  whatsapp: string | null;
  correo: string | null;
};

const limpio = (valor: unknown) => (typeof valor === 'string' && valor.trim() !== '' ? valor.trim() : null);

export function contactoDeLaPlataforma(env: Env = import.meta.env): ContactoDeLaPlataforma {
  return {
    whatsapp: limpio(env.VITE_PLATAFORMA_WHATSAPP),
    correo: limpio(env.VITE_LEGAL_CORREO),
  };
}

/*
 * Los mensajes con los que la tienda le escribe a la plataforma. No son
 * plantillas de `MOD-25`: aquellas son de la tienda hacia sus clientes y cada
 * tienda las reescribe; estas son de la tienda hacia la plataforma, y las escribe
 * la plataforma. Llevan el slug porque es lo que el operador busca en su panel.
 */
type TiendaQueEscribe = { name: string; slug: string };

/** Para pagar un plan: con uno elegido (la pantalla de tienda cerrada) o sin elegir (el aviso de la prueba). */
export function mensajeParaActivar(tienda: TiendaQueEscribe, plan?: string | null): string {
  return plan
    ? `Hola, quiero activar el plan ${plan} para mi tienda ${tienda.name} (${tienda.slug}).`
    : `Hola, quiero elegir un plan para mi tienda ${tienda.name} (${tienda.slug}).`;
}

/** Para renovar el plan que ya tiene, antes de que venza. */
export function mensajeParaRenovar(tienda: TiendaQueEscribe): string {
  return `Hola, quiero renovar el plan de mi tienda ${tienda.name} (${tienda.slug}).`;
}

/** Para una tienda suspendida a mano: no hay plan que elegir, hay que hablar. */
export function mensajeParaReabrir(tienda: TiendaQueEscribe): string {
  return `Hola, quiero reabrir mi tienda ${tienda.name} (${tienda.slug}).`;
}

/** WhatsApp si lo hay; si no, un correo con el mismo texto; si no, `null`. */
export function enlaceDeContacto(contacto: ContactoDeLaPlataforma, texto: string, asunto: string): string | null {
  if (contacto.whatsapp) {
    return enlaceDeWhatsapp(contacto.whatsapp, texto);
  }

  if (contacto.correo) {
    return `mailto:${contacto.correo}?subject=${encodeURIComponent(asunto)}&body=${encodeURIComponent(texto)}`;
  }

  return null;
}
