import api from './axios';
import type { RolDelEquipo } from '../types';
import type { AreaDePermisos, Permiso } from '../utils/permisos';

/**
 * Los roles del equipo y sus permisos por acción (MOD-21). Todo esto es solo
 * del admin, como el equipo: quien reparte permisos no puede recibirlos.
 */

/** La clave de la lista de roles en React Query: la comparten Personas y Roles. */
export const CLAVE_ROLES = ['teamRoles'];

/** Lo que se le puede marcar a un rol, agrupado por área, con su texto. */
export const getCatalogoDePermisos = async (): Promise<AreaDePermisos[]> => {
  const { data } = await api.get<{ areas: AreaDePermisos[] }>('/permissions');
  return data.areas;
};

export const getRolesDelEquipo = async (): Promise<RolDelEquipo[]> => {
  const { data } = await api.get<RolDelEquipo[]>('/team-roles');
  return data;
};

export interface DatosDeRol {
  name: string;
  description: string | null;
  permissions: Permiso[];
}

export const crearRolDelEquipo = async (datos: DatosDeRol): Promise<RolDelEquipo> => {
  const { data } = await api.post<RolDelEquipo>('/team-roles', datos);
  return data;
};

/** Si cambian los permisos, el servidor cierra la sesión de quien tiene el rol. */
export const actualizarRolDelEquipo = async (id: string, datos: Partial<DatosDeRol>): Promise<RolDelEquipo> => {
  const { data } = await api.put<RolDelEquipo>(`/team-roles/${id}`, datos);
  return data;
};

/** Un rol que alguien tiene no se borra: el servidor responde 422 con el motivo. */
export const borrarRolDelEquipo = async (id: string): Promise<void> => {
  await api.delete(`/team-roles/${id}`);
};
