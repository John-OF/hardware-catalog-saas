import api from './axios';
import type { PaymentMethods, Tenant, TenantTheme } from '../types';

export interface UpdateTenantPayload {
  name?: string;
  whatsapp_number?: string;
  primary_color?: string;
  logo_url?: string | null;
  custom_domain?: string | null;
  currency?: string;
  /** MOD-13. Identificador IANA de `utils/timezones.ts`; el backend valida contra la misma lista. */
  timezone?: string;
  theme?: TenantTheme;
  /** MOD-3. Se manda el objeto completo: el backend hace merge por método, no por campo. */
  payment_methods?: PaymentMethods;
  /** MOD-1. */
  delivery_enabled?: boolean;
  delivery_cost?: number | string;
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
  if (payload.timezone !== undefined) fd.append('timezone', payload.timezone);

  // Campos del theme como arreglo anidado: theme[clave]=valor
  if (payload.theme) {
    Object.entries(payload.theme).forEach(([key, value]) => {
      // null/undefined se omiten; el middleware ConvertEmptyStringsToNull
      // del backend convierte '' en null, preservando el "limpiar campo".
      fd.append(`theme[${key}]`, value == null ? '' : String(value));
    });
  }

  // payment_methods[metodo][campo]=valor, igual que el theme. `enabled` va
  // como '1'/'0': la regla `boolean` de Laravel no acepta '' (a diferencia de
  // los campos de texto, que sí la aceptan vía ConvertEmptyStringsToNull). Es
  // el mismo criterio que ya usa `is_active` en productos.
  if (payload.payment_methods) {
    Object.entries(payload.payment_methods).forEach(([metodo, datos]) => {
      if (!datos) return;
      Object.entries(datos).forEach(([campo, valor]) => {
        const texto = campo === 'enabled' ? (valor ? '1' : '0') : (valor == null ? '' : String(valor));
        fd.append(`payment_methods[${metodo}][${campo}]`, texto);
      });
    });
  }

  if (payload.delivery_enabled !== undefined) {
    fd.append('delivery_enabled', payload.delivery_enabled ? '1' : '0');
  }
  if (payload.delivery_cost !== undefined) {
    fd.append('delivery_cost', String(payload.delivery_cost));
  }

  if (payload.logoFile) fd.append('logo', payload.logoFile);
  if (payload.bannerFile) fd.append('banner', payload.bannerFile);
  if (payload.faviconFile) fd.append('favicon', payload.faviconFile);

  const { data } = await api.post<Tenant>('/tenant', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
};

/** Respuesta de comprobar el registro TXT del dominio propio (FUN-6). */
export interface VerifyDomainResponse {
  verified: boolean;
  message: string;
  tenant?: Tenant;
}

export const verifyCustomDomain = async (): Promise<VerifyDomainResponse> => {
  const { data } = await api.post<VerifyDomainResponse>('/tenant/custom-domain/verify');
  return data;
};
