import api from './axios';
import type { ActividadDelPanel, AreaDeActividad, PaginatedResponse } from '../types';

/**
 * Lo que trae `GET /activity` además de la página de líneas.
 *
 * - `retencion_dias` (INF-20): lo anterior se borra solo. Viene del servidor,
 *   que es quien lo borra, para que la pantalla no prometa otro número.
 * - `personas` (MOD-21): el equipo de hoy, para el filtro por persona.
 */
export type RespuestaDeActividad = PaginatedResponse<ActividadDelPanel> & {
  retencion_dias?: number;
  personas?: { name: string; email: string }[];
};

/**
 * Actividad del panel de la tienda: quién cambió qué (INF-3). Con el permiso
 * `actividad.ver` (MOD-21).
 *
 * `actor` filtra por correo y no por id, para que también se pueda buscar lo que
 * hizo alguien a quien ya se quitó del equipo.
 */
export const getActividad = async (params?: {
  area?: AreaDeActividad;
  actor?: string;
  page?: number;
  per_page?: number;
}): Promise<RespuestaDeActividad> => {
  const { data } = await api.get<RespuestaDeActividad>('/activity', { params });
  return data;
};
