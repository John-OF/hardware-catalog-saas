import axios from 'axios';
import type { PaginatedResponse, Tenant, User } from '../types';

/**
 * Cliente HTTP del panel de plataforma (SAAS-4).
 *
 * Instancia propia y no la de `api/axios.ts` a propósito: aquella inyecta el
 * token del dueño de tienda y la cabecera `X-Tenant`, y aquí ninguna de las dos
 * cosas aplica — el operador trabaja por encima de todas las tiendas. Además
 * así puede haber a la vez una sesión de tienda y una de plataforma en el mismo
 * navegador sin pisarse.
 */
const platformApi = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

export const PLATFORM_TOKEN_KEY = 'platform_token';

platformApi.interceptors.request.use((config) => {
  const token = sessionStorage.getItem(PLATFORM_TOKEN_KEY);
  if (token) config.headers['Authorization'] = `Bearer ${token}`;
  return config;
});

platformApi.interceptors.response.use(
  (response) => response,
  (error) => {
    // 401 = token caducado o revocado. El 403 no se toca: puede ser un token
    // de tienda intentando entrar aquí, y ahí queremos ver el error.
    if (error.response?.status === 401) {
      sessionStorage.removeItem(PLATFORM_TOKEN_KEY);
      window.location.href = '/platform/login';
    }
    return Promise.reject(error);
  },
);

/** Tienda tal como la lista el panel de plataforma, con sus contadores. */
export interface PlatformTenant extends Tenant {
  products_count: number;
  orders_count: number;
  users_count: number;
  created_at: string;
}

export const platformLogin = async (email: string, password: string): Promise<{ token: string; user: User }> => {
  const { data } = await platformApi.post<{ token: string; user: User }>('/platform/login', { email, password });
  return data;
};

export const platformLogout = async (): Promise<void> => {
  await platformApi.post('/platform/logout');
};

export const getPlatformTenants = async (params?: {
  search?: string;
  status?: 'active' | 'suspended';
  page?: number;
}): Promise<PaginatedResponse<PlatformTenant>> => {
  const { data } = await platformApi.get<PaginatedResponse<PlatformTenant>>('/platform/tenants', { params });
  return data;
};

export const updatePlatformTenant = async (
  id: string,
  payload: { is_active?: boolean; plan?: string },
): Promise<PlatformTenant> => {
  const { data } = await platformApi.put<PlatformTenant>(`/platform/tenants/${id}`, payload);
  return data;
};

export const sendTenantPasswordReset = async (id: string): Promise<{ message: string }> => {
  const { data } = await platformApi.post<{ message: string }>(`/platform/tenants/${id}/password-reset`);
  return data;
};
