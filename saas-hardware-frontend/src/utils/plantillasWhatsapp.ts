import type { Order, Tenant } from '../types';
import { formatMoney } from './money';
import { metodosDePagoActivos, type MetodoDePagoInfo } from './paymentMethods';
import { nombreConVariante } from './variants';

/**
 * Los mensajes de WhatsApp que cada tienda reescribe (MOD-25).
 *
 * Los textos NO están aquí: los de fábrica viven en
 * `config/plantillas_whatsapp.php` y llegan ya resueltos —el catálogo, con la
 * tienda (`tenant.plantillas_whatsapp`, solo los tres del comprador); el panel,
 * por `GET /api/whatsapp-templates`—. Aquí está lo que hace el navegador con
 * ellos: rellenar las variables y abrir WhatsApp. Se hace aquí y no en el
 * servidor porque abrir WhatsApp después de esperar una petición es lo que el
 * navegador bloquea como ventana emergente.
 */

/**
 * Copia **solo de las claves** de `config/plantillas_whatsapp.php`, para que
 * el código no pida una plantilla mal escrita y abra WhatsApp sin texto.
 * `PlantillasWhatsappTest` (en la API) falla si las dos listas se separan.
 */
export const PLANTILLAS = [
  'pedido_nuevo',
  'consulta_producto',
  'armado_pc',
  'pedido_recibido',
  'pedido_en_proceso',
  'pedido_listo',
  'pedido_cancelado',
  'recordatorio_pago',
  'contacto_pedido',
  'stock_disponible',
] as const;

export type ClaveDePlantilla = (typeof PLANTILLAS)[number];

/** Las que manda el comprador: las únicas que trae el catálogo público. */
export type PlantillaDelComprador = keyof NonNullable<Tenant['plantillas_whatsapp']>;

/** Las que se mandan desde el detalle de un pedido, en el orden del selector. */
export const PLANTILLAS_DE_PEDIDO = [
  'pedido_recibido',
  'pedido_en_proceso',
  'pedido_listo',
  'pedido_cancelado',
  'recordatorio_pago',
  'contacto_pedido',
] as const satisfies readonly ClaveDePlantilla[];

export type PlantillaDePedido = (typeof PLANTILLAS_DE_PEDIDO)[number];

export type ValoresDePlantilla = Record<string, string | null | undefined>;

const VARIABLE = /\{([a-z_]+)\}/g;

/**
 * El texto con sus variables puestas.
 *
 * Dos reglas que no se ven en la plantilla:
 *
 * - **Una línea cuyas variables quedan TODAS vacías no se manda.** Así
 *   "Nota: {nota}" desaparece cuando no hay nota, sin condicionales en el
 *   texto. Una línea sin variables se manda siempre.
 * - **Las líneas en blanco seguidas se juntan en una**, para que quitar una
 *   línea no deje un hueco doble.
 *
 * Una variable que no está en `valores` se deja tal cual (el servidor ya
 * rechaza al guardar las que ese mensaje no admite).
 */
export function rellenarPlantilla(texto: string, valores: ValoresDePlantilla): string {
  const conocida = (nombre: string) => Object.prototype.hasOwnProperty.call(valores, nombre);
  const vacia = (nombre: string) => conocida(nombre) && !(valores[nombre] ?? '').trim();

  return texto
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .filter((linea) => {
      const usadas = [...linea.matchAll(VARIABLE)].map((m) => m[1]);
      return usadas.length === 0 || !usadas.every(vacia);
    })
    .map((linea) => linea.replace(VARIABLE, (entera, nombre: string) => (conocida(nombre) ? valores[nombre] ?? '' : entera)))
    .join('\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/**
 * Las variables de un texto que esa plantilla no admite, sin repetir. Es el
 * aviso del editor antes de guardar; quien rechaza es el servidor, con la misma
 * regla (`PlantillasWhatsapp::variablesQueNoAdmite`).
 */
export function variablesQueNoAdmite(texto: string, admitidas: readonly string[]): string[] {
  const usadas = [...texto.matchAll(VARIABLE)].map((m) => m[1]);
  return [...new Set(usadas)].filter((nombre) => !admitidas.includes(nombre));
}

/**
 * `https://wa.me/<dígitos>?text=…`. El número se queda solo en dígitos: un
 * "+51 999 888 777" con espacios rompía el enlace donde se quitaba solo el "+".
 * Sin texto, abre el chat vacío.
 */
export function enlaceDeWhatsapp(telefono: string | null | undefined, texto = ''): string {
  const digitos = (telefono ?? '').replace(/[^0-9]/g, '');
  return texto ? `https://wa.me/${digitos}?text=${encodeURIComponent(texto)}` : `https://wa.me/${digitos}`;
}

/** `{productos}`: una línea por producto, con su cantidad y lo que suma. */
export function lineaDeProducto(cantidad: number, nombre: string, importe: string): string {
  return `• ${cantidad} x ${nombre} — ${importe}`;
}

/** `{entrega}`: vacía si el pedido no dice cómo se entrega (la tienda no reparte, o es de mostrador). */
export function textoDeEntrega(metodo: 'pickup' | 'delivery' | null | undefined, costo: string): string {
  if (metodo === 'delivery') return `Delivery (${costo})`;
  if (metodo === 'pickup') return 'Recojo en tienda';
  return '';
}

/** `{pagos}`: "Yape (987654321 · Ana), Efectivo contra entrega". */
export function textoDePagos(metodos: MetodoDePagoInfo[]): string {
  return metodos.map((m) => m.etiqueta + (m.detalle ? ` (${m.detalle})` : '')).join(', ');
}

/**
 * La plantilla que corresponde al estado del pedido: la que sale elegida al
 * abrir el detalle.
 */
export function plantillaDelEstado(estado: Order['status']): PlantillaDePedido {
  switch (estado) {
    case 'processing': return 'pedido_en_proceso';
    case 'attended': return 'pedido_listo';
    case 'cancelled': return 'pedido_cancelado';
    default: return 'pedido_recibido';
  }
}

/** Las variables de los mensajes de un pedido, desde el panel. */
export function valoresDePedido(pedido: Order, tienda: Tenant | null | undefined): ValoresDePlantilla {
  const money = (n: number | string | null | undefined) => formatMoney(n, tienda?.currency);

  return {
    cliente: pedido.customer_name,
    tienda: tienda?.name ?? '',
    // FUN-3: el correlativo, que es el que el cliente tiene y el dueño busca.
    pedido: `#${pedido.number}`,
    productos: (pedido.items ?? [])
      .map((item) => lineaDeProducto(item.quantity, nombreConVariante(item.product_name, item.variant_name), money(item.subtotal)))
      .join('\n'),
    total: money(pedido.total),
    entrega: textoDeEntrega(pedido.delivery_method, money(pedido.delivery_cost)),
    pagos: textoDePagos(metodosDePagoActivos(tienda?.payment_methods)),
  };
}

/**
 * La dirección pública de la ficha de un producto, para mandarla desde el
 * panel. Con dominio propio verificado, ese dominio (FUN-6); si no, la del
 * catálogo por slug en este mismo origen, que es donde se sirve.
 */
export function enlaceDeProducto(tienda: Tenant | null | undefined, productoId: string): string {
  if (!tienda) return '';

  if (tienda.custom_domain && tienda.custom_domain_verified_at) {
    return `https://${tienda.custom_domain}/product/${productoId}`;
  }

  return `${window.location.origin}/${tienda.slug}/product/${productoId}`;
}
