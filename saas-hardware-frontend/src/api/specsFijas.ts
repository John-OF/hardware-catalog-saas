import api from './axios';
import type { DefinicionDeSpecs } from '../types';

/**
 * Las specs con nombre fijo por tipo de componente (FUN-8, 13.2).
 *
 * Sin tienda: la definición es de la plataforma y la misma para todas. La usan
 * el formulario del panel (qué preguntar) y el catálogo público (con qué
 * etiqueta y unidad pintar lo guardado).
 */
export const getSpecsFijas = async (): Promise<DefinicionDeSpecs> => {
  const { data } = await api.get<DefinicionDeSpecs>('/public/component-specs');
  return data;
};
