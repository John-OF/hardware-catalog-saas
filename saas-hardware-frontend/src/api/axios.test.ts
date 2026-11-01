import { AxiosError, type AxiosAdapter, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import api from './axios';
import { useAuthStore } from '../stores/authStore';
import { useCustomerAuthStore } from '../stores/customerAuthStore';
import { useTenantStore } from '../stores/tenantStore';
import { PLATFORM_TOKEN_KEY } from './platform';

vi.mock('react-hot-toast', () => {
  const toast = { success: vi.fn(), error: vi.fn() };
  return { toast, default: toast };
});

/** Sustituye la red: guarda la petición tal como sale y responde con `status`. */
const responderCon = (status: number) => {
  const enviadas: InternalAxiosRequestConfig[] = [];
  const adapter: AxiosAdapter = async (config) => {
    enviadas.push(config);
    const response: AxiosResponse = { data: {}, status, statusText: '', headers: {}, config };
    if (status >= 400) {
      throw new AxiosError('fallo', 'ERR_BAD_REQUEST', config, {}, response);
    }
    return response;
  };
  api.defaults.adapter = adapter;
  return enviadas;
};

const adapterOriginal = api.defaults.adapter;

describe('api/axios', () => {
  beforeEach(() => {
    useAuthStore.setState({ token: 'tok-panel', user: null, isAuthenticated: true, soporte: false });
    useCustomerAuthStore.setState({ token: 'tok-cliente', user: null, isAuthenticated: true });
  });

  afterEach(() => {
    api.defaults.adapter = adapterOriginal;
  });

  it('el panel lleva el token de sessionStorage y la tienda en X-Tenant', async () => {
    const enviadas = responderCon(200);
    sessionStorage.setItem('token', 'tok-panel');
    sessionStorage.setItem('tenant_slug', 'tienda-demo');
    localStorage.setItem('customer_token', 'tok-cliente');

    await api.get('/products');

    expect(enviadas[0].headers.Authorization).toBe('Bearer tok-panel');
    expect(enviadas[0].headers['X-Tenant']).toBe('tienda-demo');
  });

  it('el catálogo público lleva el token del cliente y no el del panel', async () => {
    const enviadas = responderCon(200);
    sessionStorage.setItem('token', 'tok-panel');
    localStorage.setItem('customer_token', 'tok-cliente');
    localStorage.setItem('visitor_id', 'visitante-1');

    await api.get('/public/tienda-demo/products');

    expect(enviadas[0].headers.Authorization).toBe('Bearer tok-cliente');
    expect(enviadas[0].headers['X-Visitor-Id']).toBe('visitante-1');
  });

  it('sin token no manda Authorization', async () => {
    const enviadas = responderCon(200);

    await api.get('/products');

    expect(enviadas[0].headers.Authorization).toBeUndefined();
  });

  it('un 401 del panel cierra la sesión de tienda pero no la de plataforma (INF-2)', async () => {
    responderCon(401);
    sessionStorage.setItem('token', 'tok-panel');
    sessionStorage.setItem('tenant_slug', 'tienda-demo');
    sessionStorage.setItem(PLATFORM_TOKEN_KEY, 'tok-plataforma');
    // jsdom no navega: basta con que la asignación no rompa.
    vi.spyOn(console, 'error').mockImplementation(() => {});

    await expect(api.get('/products')).rejects.toBeInstanceOf(AxiosError);

    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(sessionStorage.getItem('token')).toBeNull();
    expect(sessionStorage.getItem('tenant_slug')).toBeNull();
    expect(sessionStorage.getItem(PLATFORM_TOKEN_KEY)).toBe('tok-plataforma');
    // Y el comprador con sesión en el catálogo no se ve afectado.
    expect(useCustomerAuthStore.getState().isAuthenticated).toBe(true);
  });

  it('un 401 del catálogo cierra solo la sesión del cliente', async () => {
    responderCon(401);
    localStorage.setItem('customer_token', 'tok-cliente');
    localStorage.setItem('customer_user', '{"id":"c-1"}');

    await expect(api.get('/public/tienda-demo/auth/me')).rejects.toBeInstanceOf(AxiosError);

    expect(useCustomerAuthStore.getState().isAuthenticated).toBe(false);
    expect(localStorage.getItem('customer_token')).toBeNull();
    expect(localStorage.getItem('customer_user')).toBeNull();
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
  });

  /** Responde 403 con el cuerpo dado. */
  const prohibidoCon = (data: unknown) => {
    api.defaults.adapter = async (config) => {
      throw new AxiosError('fallo', 'ERR_BAD_REQUEST', config, {}, { data, status: 403, statusText: '', headers: {}, config });
    };
  };

  it('un 403 de tienda cerrada guarda el cierre, que es lo que cambia el panel de pantalla (FUN-28)', async () => {
    const cierre = { motivo: 'plan', desde: '2026-11-08T15:00:00+00:00', plan: 'Pro' };
    prohibidoCon({ message: 'Tu tienda está cerrada…', motivo: 'tienda_cerrada', cierre });
    useTenantStore.setState({ cierre: null });

    await expect(api.get('/dashboard/stats')).rejects.toBeInstanceOf(AxiosError);

    expect(useTenantStore.getState().cierre).toEqual(cierre);
    // La sesión sigue: con la tienda cerrada todavía se puede ver por qué y salir.
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
  });

  it('un 403 por otro motivo no toca el cierre', async () => {
    prohibidoCon({ message: 'No tienes permiso para esto.' });
    useTenantStore.setState({ cierre: null });

    await expect(api.get('/products')).rejects.toBeInstanceOf(AxiosError);

    expect(useTenantStore.getState().cierre).toBeNull();
  });
});
