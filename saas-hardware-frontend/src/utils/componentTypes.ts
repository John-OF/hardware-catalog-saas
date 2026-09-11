import type { ComponentType } from '../types';

/**
 * Qué pieza de PC vende una categoría (FUN-8).
 *
 * Espejo de `App\Enums\ComponentType`. Los valores coinciden con los slugs de
 * icono de `CategoryIcon` a propósito: el select de iconos del panel ya era esta
 * misma lista haciendo de tipo a escondidas —el armador miraba el icono para
 * emparejar sus pasos—, sólo que era opcional y nadie sabía que decidía nada.
 *
 * El orden es el del panel: primero las ocho piezas del armador, en el orden en
 * que se monta una PC, y al final lo que no lo es.
 */
export const COMPONENT_TYPES: { value: ComponentType; label: string }[] = [
  { value: 'cpu', label: 'Procesadores (CPU)' },
  { value: 'motherboard', label: 'Placas Madre (Motherboard)' },
  { value: 'ram', label: 'Memorias RAM' },
  { value: 'gpu', label: 'Tarjetas de Video (GPU)' },
  { value: 'ssd', label: 'Almacenamiento (SSD/HDD)' },
  { value: 'power', label: 'Fuentes de Poder' },
  { value: 'cooling', label: 'Enfriamiento / Disipadores' },
  { value: 'case', label: 'Gabinetes / Chasis' },
  { value: 'monitor', label: 'Monitores' },
  { value: 'peripheral', label: 'Periféricos (Teclado/Mouse)' },
  { value: 'other', label: 'Otros / No es pieza de PC' },
];

/** Slug de icono del tipo. `other` no tiene dibujo propio. */
export const iconOfComponentType = (type: ComponentType): string =>
  type === 'other' ? 'folder' : type;
