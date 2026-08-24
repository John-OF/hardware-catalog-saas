import api from './axios';
import type { PlanInfo } from '../types';

/**
 * Plan de la tienda, sus límites y lo que lleva gastado (SAAS-3).
 *
 * Va aparte de `getMyTenant` a propósito: el consumo cambia cada vez que se
 * crea o se borra algo, mientras que la configuración de la tienda casi nunca,
 * así que mezclarlos obligaría a recargar el tema entero para refrescar un
 * contador.
 */
export const getPlan = async (): Promise<PlanInfo> => {
  const { data } = await api.get<PlanInfo>('/plan');
  return data;
};
