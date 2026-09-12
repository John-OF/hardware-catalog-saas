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

/* --- Resumen del negocio (INF-2) ------------------------------------------ */

/**
 * Lo que devuelve `GET /platform/stats`.
 *
 * **No hay ninguna cifra de dinero y es deliberado**: cada tienda factura en su
 * moneda, así que sumar los pedidos de todas daría un número sin significado.
 * El dinero llega con la facturación (7.7b). Ver el porqué en el controlador.
 */
export interface PlatformStats {
  tiendas: {
    total: number;
    activas: number;
    suspendidas: number;
    publicadas: number;
    /** Activas que no han subido ni un producto: se registraron y no arrancaron. */
    sin_arrancar: number;
  };
  altas: { hoy: number; semana: number; mes: number };
  /** Cuántas tiendas hay en cada plan, con la clave del plan como propiedad. */
  planes: Record<string, number>;
  catalogo: { productos: number; pedidos: number; equipo: number };
  actividad: { pedidos_semana: number };
  ultimas_altas: Array<
    Pick<Tenant, 'id' | 'name' | 'slug' | 'plan' | 'is_active' | 'is_published'> & { created_at: string }
  >;
}

export const getPlatformStats = async (): Promise<PlatformStats> => {
  const { data } = await platformApi.get<PlatformStats>('/platform/stats');
  return data;
};

/* --- Ficha de una tienda --------------------------------------------------- */

export interface ActivityLogEntry {
  id: string;
  tenant_id: string | null;
  actor_email: string | null;
  actor_role: string | null;
  action: string;
  description: string;
  /** Detalle de la acción; incluye el nombre de la tienda por si se borró. */
  context: Record<string, unknown> | null;
  ip: string | null;
  created_at: string;
  tenant?: { id: string; name: string; slug: string } | null;
}

export interface PlatformTenantDetail {
  tenant: PlatformTenant;
  plan: {
    clave: string;
    label: string;
    /** Un número es un tope, `null` sin tope, un booleano una función. */
    limites: Record<string, number | boolean | null>;
    /** Consumo actual, con las mismas claves que `limites`. */
    uso: Record<string, number>;
  };
  equipo: User[];
  /** Clientes registrados del catálogo: comparten tabla con el equipo pero no cuentan para el plan. */
  clientes: number;
  ultimos_pedidos: Array<{
    id: string;
    number: number;
    customer_name: string;
    status: string;
    total: string;
    created_at: string;
  }>;
  bitacora: ActivityLogEntry[];
}

export const getPlatformTenant = async (id: string): Promise<PlatformTenantDetail> => {
  const { data } = await platformApi.get<PlatformTenantDetail>(`/platform/tenants/${id}`);
  return data;
};

/* --- Entrar como soporte --------------------------------------------------- */

export interface ImpersonationResponse {
  /** Token del panel de ESA tienda: caduca en `expira_en` minutos y solo lee. */
  token: string;
  user: User;
  tenant: Tenant;
  expira_en: number;
  message: string;
}

export const impersonateTenant = async (id: string): Promise<ImpersonationResponse> => {
  const { data } = await platformApi.post<ImpersonationResponse>(`/platform/tenants/${id}/impersonate`);
  return data;
};

/* --- Rescate: nombrar admin cuando la tienda se queda sin ninguno ---------- */

/**
 * Puerta de emergencia, no un reparto de roles (INF-2). Solo funciona si la
 * tienda de verdad no tiene ningún administrador activo —el backend lo
 * comprueba igual, esto solo evita ofrecerlo cuando no hace falta—, y solo
 * puede elegirse a un colaborador ya existente y activo de esa misma tienda.
 */
export const rescueAdmin = async (tenantId: string, userId: string): Promise<User> => {
  const { data } = await platformApi.post<User>(`/platform/tenants/${tenantId}/rescue-admin`, { user_id: userId });
  return data;
};

/* --- Bitácora -------------------------------------------------------------- */

export const getPlatformLogs = async (params?: {
  tenant_id?: string;
  action?: string;
  page?: number;
}): Promise<PaginatedResponse<ActivityLogEntry>> => {
  const { data } = await platformApi.get<PaginatedResponse<ActivityLogEntry>>('/platform/logs', { params });
  return data;
};
