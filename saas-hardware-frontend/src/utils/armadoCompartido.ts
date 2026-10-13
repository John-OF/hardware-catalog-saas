import type { Product, ProductVariant } from '../types';
import { tieneVariantes } from './variants';

/**
 * El armado de PC en la URL, para poder compartirlo (MOD-20).
 *
 * Cada paso va con el id de su pieza (`?cpu=…&motherboard=…`) y, si la pieza
 * tiene variantes, la elegida al lado (`cpu_variante=…`). No se guarda nada más:
 * los precios, el stock y el veredicto de compatibilidad se vuelven a pedir al
 * abrir el enlace, así que un enlace viejo nunca enseña un precio viejo.
 */

export interface PiezaDelEnlace {
  id: string;
  variante: string | null;
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const claveDeVariante = (paso: string) => `${paso}_variante`;

/** Lo que trae el enlace, por paso. Lo que no tiene forma de id se ignora. */
export function leerArmadoDelEnlace(params: URLSearchParams, pasos: readonly string[]): Record<string, PiezaDelEnlace> {
  const armado: Record<string, PiezaDelEnlace> = {};

  for (const paso of pasos) {
    const id = params.get(paso);
    if (!id || !UUID.test(id)) continue;

    const variante = params.get(claveDeVariante(paso));
    armado[paso] = { id, variante: variante && UUID.test(variante) ? variante : null };
  }

  return armado;
}

/**
 * La URL con el armado de ahora. Los parámetros que no son del armado se
 * quedan como estaban.
 */
export function escribirArmadoEnElEnlace(
  actuales: URLSearchParams,
  pasos: readonly string[],
  selecciones: Record<string, Product>,
  variantes: Record<string, ProductVariant | null>,
): URLSearchParams {
  const params = new URLSearchParams(actuales);

  for (const paso of pasos) {
    params.delete(paso);
    params.delete(claveDeVariante(paso));

    const pieza = selecciones[paso];
    if (!pieza) continue;

    params.set(paso, pieza.id);
    const variante = variantes[paso];
    if (variante) params.set(claveDeVariante(paso), variante.id);
  }

  return params;
}

/**
 * Las piezas del enlace, ya con su producto. Una pieza que el catálogo ya no
 * tiene, o que no es del paso donde venía, no se elige y se cuenta en `faltan`
 * para que la pantalla lo diga. Si la variante del enlace ya no existe, se
 * toma la primera con stock, como hace el cajón.
 */
export function armadoDesdeElEnlace(
  enlace: Record<string, PiezaDelEnlace>,
  productos: Product[],
): { selecciones: Record<string, Product>; variantes: Record<string, ProductVariant | null>; faltan: number } {
  const porId = new Map(productos.map((p) => [p.id, p]));
  const selecciones: Record<string, Product> = {};
  const variantes: Record<string, ProductVariant | null> = {};
  let faltan = 0;

  for (const [paso, pieza] of Object.entries(enlace)) {
    const producto = porId.get(pieza.id);

    if (!producto || producto.category?.component_type !== paso) {
      faltan++;
      continue;
    }

    selecciones[paso] = producto;
    variantes[paso] = tieneVariantes(producto)
      ? producto.variants!.find((v) => v.id === pieza.variante)
        ?? producto.variants!.find((v) => v.stock > 0)
        ?? producto.variants![0]
      : null;
  }

  return { selecciones, variantes, faltan };
}
