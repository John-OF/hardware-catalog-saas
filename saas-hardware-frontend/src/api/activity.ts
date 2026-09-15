import api from './axios';
import type { ActividadDelPanel, AreaDeActividad, PaginatedResponse } from '../types';

/**
 * Actividad del panel de la tienda: quién cambió qué (INF-3). Solo admin.
 *
 * `actor` filtra por correo y no por id, para que también se pueda buscar lo que
 * hizo alguien a quien ya se quitó del equipo.
 */
export const getActividad = async (params?: {
  area?: AreaDeActividad;
  actor?: string;
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<ActividadDelPanel>> => {
  const { data } = await api.get<PaginatedResponse<ActividadDelPanel>>('/activity', { params });
  return data;
};
