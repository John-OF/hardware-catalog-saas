import api from './axios';
import type { Tenant, TenantTheme } from '../types';

export interface UpdateTenantPayload {
  name?: string;
  whatsapp_number?: string;
  primary_color?: string;
  logo_url?: string | null;
  custom_domain?: string | null;
  currency?: string;
  theme?: TenantTheme;
  // Archivos opcionales: si se envían, el backend los sube y usa su URL.
  logoFile?: File | null;
  bannerFile?: File | null;
  faviconFile?: File | null;
}

// Configuración de la tienda del usuario autenticado (rutas privadas).
export const getMyTenant = async (): Promise<Tenant> => {
  const { data } = await api.get<Tenant>('/tenant');
  return data;
};

export const updateTenant = async (payload: UpdateTenantPayload): Promise<Tenant> => {
  const fd = new FormData();
  // Laravel no acepta multipart directo en PUT: usamos POST + _method.
  fd.append('_method', 'PUT');

  if (payload.name !== undefined) fd.append('name', payload.name);
  if (payload.whatsapp_number !== undefined) fd.append('whatsapp_number', payload.whatsapp_number);
  if (payload.primary_color !== undefined) fd.append('primary_color', payload.primary_color);
  if (payload.logo_url !== undefined && payload.logo_url !== null) fd.append('logo_url', payload.logo_url);
  if (payload.custom_domain !== undefined) fd.append('custom_domain', payload.custom_domain ?? '');
  if (payload.currency !== undefined) fd.append('currency', payload.currency);

  // Campos del theme como arreglo anidado: theme[clave]=valor
  if (payload.theme) {
    Object.entries(payload.theme).forEach(([key, value]) => {
      // null/undefined se omiten; el middleware ConvertEmptyStringsToNull
      // del backend convierte '' en null, preservando el "limpiar campo".
      fd.append(`theme[${key}]`, value == null ? '' : String(value));
    });
  }

  if (payload.logoFile) fd.append('logo', payload.logoFile);
  if (payload.bannerFile) fd.append('banner', payload.bannerFile);
  if (payload.faviconFile) fd.append('favicon', payload.faviconFile);

  const { data } = await api.post<Tenant>('/tenant', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
};
