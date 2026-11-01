import axios from 'axios';
import toast from 'react-hot-toast';
import { rutaDeSalidaDelPanel, useAuthStore } from '../stores/authStore';
import { useCustomerAuthStore } from '../stores/customerAuthStore';
import { useTenantStore } from '../stores/tenantStore';

/** El `motivo` del 403 del panel cuando la tienda está cerrada (`InitializeTenantByHeader`). */
export const MOTIVO_TIENDA_CERRADA = 'tienda_cerrada';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Antes de cada petición: inyectar token y header de tenant
api.interceptors.request.use((config) => {
  const isPublicRequest = config.url?.includes('public/');
  const token = isPublicRequest 
    ? localStorage.getItem('customer_token')
    : sessionStorage.getItem('token');
  const tenant = sessionStorage.getItem('tenant_slug');
  const visitorId = localStorage.getItem('visitor_id');

  if (token)  config.headers['Authorization'] = `Bearer ${token}`;
  if (tenant) config.headers['X-Tenant'] = tenant;
  if (visitorId) config.headers['X-Visitor-Id'] = visitorId;

  return config;
});

// Si el servidor responde 401: limpiar sesión y redirigir (solo para admins)
api.interceptors.response.use(
  (response) => response,
  (error) => {
    // AUD-2: el catálogo público ya tiene rate limit, así que el 429 es una
    // respuesta que un comprador legítimo puede ver (varias personas detrás de
    // la misma IP, por ejemplo). Sin esto se quedaría mirando un error genérico
    // sin entender que basta con esperar.
    //
    // El `id` fijo es lo que impide la avalancha: una carga del catálogo son
    // ~6 peticiones y todas fallarían a la vez; con id se refresca un único
    // toast en vez de apilar seis.
    if (error.response?.status === 429) {
      toast.error('Demasiadas peticiones seguidas. Espera unos segundos y vuelve a intentarlo.', {
        id: 'rate-limit',
      });
    }

    // FUN-28: la tienda se cerró con la sesión abierta (venció la prueba o el
    // plan, o la suspendió el operador). Cualquier ruta del panel responde este
    // 403 con el motivo, y basta con guardarlo: el panel deja de pintar sus
    // pantallas, que ya solo darían errores, y pinta la de tienda cerrada.
    if (
      error.response?.status === 403
      && error.response?.data?.motivo === MOTIVO_TIENDA_CERRADA
      && !error.config?.url?.includes('public/')
    ) {
      useTenantStore.getState().setCierre(error.response.data.cierre ?? { motivo: 'suspendida', desde: null, plan: '' });
    }

    if (error.response?.status === 401) {
      const isPublicRequest = error.config?.url?.includes('public/');
      if (isPublicRequest) {
        useCustomerAuthStore.getState().clearCustomerAuth();
      } else {
        // Solo las claves del panel, no `sessionStorage.clear()` (INF-2): el
        // panel de plataforma guarda su token en una clave propia para poder
        // convivir con esta sesión, y un 401 aquí se llevaba también aquella.
        // Con el modo soporte pasa constantemente: el token de soporte caduca a
        // los 15 minutos, y ese 401 echaba al operador de su propio panel.
        // El destino se calcula ANTES de limpiar: depende del estado de sesión.
        // Al operador se le devuelve a su panel, no al login de tiendas: no
        // tiene cuenta ahí y su sesión de plataforma sigue viva.
        const destino = rutaDeSalidaDelPanel();
        useAuthStore.getState().clearPanelSession();
        window.location.href = destino;
      }
    }
    return Promise.reject(error);
  }
);

export default api;
