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

export const logoutUser = async (): Promise<void> => {
  await api.post('/auth/logout');
};

export const getMe = async (): Promise<{ user: User; tenant: Tenant }> => {
  const response = await api.get<{ user: User; tenant: Tenant }>('/auth/me');
  return response.data;
};
