import api from './axios';
import type { AuthResponse, User, Tenant } from '../types';

export const registerStore = async (data: any): Promise<AuthResponse> => {
  const response = await api.post<AuthResponse>('/auth/register', data);
  return response.data;
};

export const loginUser = async (data: any): Promise<AuthResponse> => {
  const response = await api.post<AuthResponse>('/auth/login', data);
  return response.data;
};

/** Pide el enlace de recuperación. La respuesta es genérica: no revela si el correo existe. */
export const forgotPassword = async (email: string): Promise<{ message: string }> => {
  const response = await api.post<{ message: string }>('/auth/forgot-password', { email });
  return response.data;
};

export const resetPassword = async (data: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<{ message: string }> => {
  const response = await api.post<{ message: string }>('/auth/reset-password', data);
  return response.data;
};

export const logoutUser = async (): Promise<void> => {
  await api.post('/auth/logout');
};

export const getMe = async (): Promise<{ user: User; tenant: Tenant }> => {
  const response = await api.get<{ user: User; tenant: Tenant }>('/auth/me');
  return response.data;
};
