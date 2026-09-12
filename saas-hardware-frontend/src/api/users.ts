import api from './axios';
import type { User } from '../types';

/** Los dos roles del panel (FUN-4). En la interfaz, `staff` se llama "Colaborador". */
export type RolDePanel = 'admin' | 'staff';

/**
 * El equipo de la tienda (FUN-4).
 *
 * Son los usuarios que pueden entrar al PANEL. Los clientes del catálogo viven
 * en la misma tabla del backend pero no salen por aquí: para verlos haría falta
 * `MOD-10`, que no existe.
 */
export const getTeamUsers = async (): Promise<User[]> => {
  const { data } = await api.get<User[]>('/users');
  return data;
};

/** Invita a alguien: se crea sin contraseña y recibe un enlace para elegirla. */
export const inviteUser = async (
  payload: { name: string; email: string; role?: RolDePanel },
): Promise<User> => {
  const { data } = await api.post<User>('/users', payload);
  return data;
};

export const updateTeamUser = async (
  id: string,
  payload: { name?: string; is_active?: boolean; role?: RolDePanel },
): Promise<User> => {
  const { data } = await api.put<User>(`/users/${id}`, payload);
  return data;
};

export const deleteTeamUser = async (id: string): Promise<void> => {
  await api.delete(`/users/${id}`);
};

/** El enlace de la invitación caduca; esto manda uno nuevo. */
export const resendInvitation = async (id: string): Promise<{ message: string }> => {
  const { data } = await api.post<{ message: string }>(`/users/${id}/resend-invitation`);
  return data;
};
