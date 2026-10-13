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
}): Promise<PaginatedResponse<ActividadDelPanel> & { retencion_dias?: number }> => {
  // `retencion_dias` (INF-20): lo anterior se borra solo. Viene del servidor,
  // que es quien lo borra, para que la pantalla no prometa otro número.
  const { data } = await api.get<PaginatedResponse<ActividadDelPanel> & { retencion_dias?: number }>('/activity', { params });
  return data;
};
