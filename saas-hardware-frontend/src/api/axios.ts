import axios from 'axios';

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
    if (error.response?.status === 401) {
      const isPublicRequest = error.config?.url?.includes('public/');
      if (isPublicRequest) {
        localStorage.removeItem('customer_token');
        localStorage.removeItem('customer_user');
        // Opcional: recargar para limpiar estado global de Zustand si es necesario
      } else {
        sessionStorage.clear();
        window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  }
);

export default api;
