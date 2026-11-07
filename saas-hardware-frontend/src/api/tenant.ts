import api from './axios';
import type { Tenant, TenantTheme } from '../types';
import { agregarMetodosAlFormulario, type MetodoEnFormulario } from '../utils/metodosDePagoEnFormulario';

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
  /**
   * MOD-23. La lista entera, con el QR de cada uno: el backend la toma tal
   * cual y quita lo que no venga. Ausente, no se tocan.
   */
  payment_methods?: MetodoEnFormulario[];
  /** MOD-1. */
  delivery_enabled?: boolean;
  delivery_cost?: number | string;
  /** MOD-2. */
  tax_enabled?: boolean;
  tax_name?: string;
  tax_rate?: number | string;
  tax_included?: boolean;
  /** FUN-26. Vacío quita la fecha de validez de la cotización. */
  quote_validity_days?: number | string | null;
  quote_terms?: string | null;
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

  // MOD-23: payment_methods[i][campo]=valor y payment_method_qr[i]=archivo.
  // `enabled` va como '1'/'0': la regla `boolean` de Laravel no acepta ''.
  if (payload.payment_methods) {
    agregarMetodosAlFormulario(fd, payload.payment_methods);
  }

  if (payload.delivery_enabled !== undefined) {
    fd.append('delivery_enabled', payload.delivery_enabled ? '1' : '0');
  }
  if (payload.delivery_cost !== undefined) {
    fd.append('delivery_cost', String(payload.delivery_cost));
  }

  // MOD-2. Los dos booleanos van como '1'/'0' por lo mismo que `enabled` de los
  // métodos de pago: la regla `boolean` de Laravel no acepta ''.
  if (payload.tax_enabled !== undefined) {
    fd.append('tax_enabled', payload.tax_enabled ? '1' : '0');
  }
  if (payload.tax_name !== undefined) fd.append('tax_name', payload.tax_name);
  if (payload.tax_rate !== undefined) fd.append('tax_rate', String(payload.tax_rate));
  if (payload.tax_included !== undefined) {
    fd.append('tax_included', payload.tax_included ? '1' : '0');
  }

  // FUN-26: vacíos viajan como '' y el backend los guarda como null (sin fecha,
  // sin condiciones), como el resto de campos de texto.
  if (payload.quote_validity_days !== undefined) fd.append('quote_validity_days', payload.quote_validity_days == null ? '' : String(payload.quote_validity_days));
  if (payload.quote_terms !== undefined) fd.append('quote_terms', payload.quote_terms ?? '');

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
