import type { Tenant } from '../types';

/**
 * Las partes del sistema que dependen del rubro de la tienda (MOD-19).
 *
 * Qué módulos tiene cada vertical lo decide la API (`config/verticales.php`,
 * `App\Support\Verticales`), y la tienda llega con ellos ya resueltos en
 * `tenant.modulos`: el navegador nunca pregunta por el nombre de la vertical
 * (`vertical === 'hardware'`), pregunta por un módulo.
 *
 * Copia **solo de las claves**, para que una errata no esconda el armador sin
 * que nadie lo note: `VerticalesTest` de la API compara esta lista con la
 * config, en el mismo orden.
 */
export const MODULOS_DE_VERTICAL = [
  'tipo_de_componente',
  'ficha_tecnica',
  'armador',
] as const;

export type ModuloDeVertical = (typeof MODULOS_DE_VERTICAL)[number];

/**
 * Si la tienda tiene un módulo. Falla en cerrado, como la API: una tienda que
 * todavía no cargó, o una respuesta sin `modulos`, no enseña nada de PC.
 */
export function tieneModulo(
  tenant: Pick<Tenant, 'modulos'> | null | undefined,
  modulo: ModuloDeVertical,
): boolean {
  return tenant?.modulos?.[modulo] === true;
}
