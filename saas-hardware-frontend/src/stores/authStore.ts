import { create } from 'zustand';
import type { User } from '../types';
import { PLATFORM_TOKEN_KEY } from '../api/platform';

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  /**
   * Si esta sesión es el operador del SaaS mirando la tienda como soporte
   * (INF-2). Lo decide el token y lo dice el backend en `GET /auth/me`; aquí
   * solo se guarda lo que él contestó. No se persiste a propósito: al recargar
   * vuelve a preguntarse, que es lo correcto — la verdad está en el token.
   */
  soporte: boolean;
  setAuth: (token: string, user: User) => void;
  setSoporte: (soporte: boolean) => void;
  clearAuth: () => void;
  clearPanelSession: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  user:            null,
  token:           sessionStorage.getItem('token'),
  isAuthenticated: !!sessionStorage.getItem('token'),
  soporte:         false,

  setAuth: (token, user) => {
    sessionStorage.setItem('token', token);
    set({ token, user, isAuthenticated: true });
  },

  setSoporte: (soporte) => set({ soporte }),

  clearAuth: () => {
    useAuthStore.getState().clearPanelSession();
  },

  /**
   * Cerrar la sesión del panel SIN tocar nada más del navegador.
   *
   * Antes esto era `sessionStorage.clear()`, y eso se llevaba por delante
   * también la sesión del panel de plataforma, que guarda su token en una clave
   * propia justamente para poder convivir con la de tienda. Con el modo soporte
   * (INF-2) dejó de ser teórico: al salir de una tienda, o ante cualquier 401
   * del panel, el operador perdía a la vez la sesión a la que volvía.
   *
   * Las dos claves de aquí son todo lo que el panel guarda en sessionStorage;
   * el tema del panel vive en localStorage y no es una credencial.
   */
  clearPanelSession: () => {
    sessionStorage.removeItem('token');
    sessionStorage.removeItem('tenant_slug');
    set({ token: null, user: null, isAuthenticated: false, soporte: false });
  },
}));

/**
 * A dónde mandar a quien sale del panel de tienda: por logout, por un 401 o
 * porque la sesión venció al recargar (INF-2).
 *
 * Al operador que salía de una sesión de soporte se le mandaba a `/login`, el
 * login de tiendas, donde no tiene cuenta. `soporte` no basta para evitarlo: no
 * se persiste, así que tras recargar vale `false` hasta que `getMe()` responde,
 * y si el token de soporte ya caducó ese `getMe()` es justo el que falla. Por
 * eso se mira también si en esta pestaña hay sesión de plataforma viva
 * —sessionStorage es por pestaña—: si la hay, quien está aquí es el operador.
 *
 * Solo decide una redirección, no da ningún permiso.
 */
export const rutaDeSalidaDelPanel = (): string =>
  useAuthStore.getState().soporte || sessionStorage.getItem(PLATFORM_TOKEN_KEY)
    ? '/platform/tenants'
    : '/login';

/**
 * Si quien usa el panel es admin (FUN-4). Tres valores y no dos a propósito:
 * `null` es "todavía no lo sé", porque tras recargar la página el usuario llega
 * con `getMe()` un instante después del primer pintado. Quien decide con esto
 * tiene que elegir qué hace mientras tanto: el menú muestra (lo normal es ser
 * admin y así no salta al cargar) y la guarda de rutas espera.
 *
 * Solo esconde: la barrera de verdad es el backend, que responde 403 a staff en
 * todo lo que es de admin.
 */
export const useEsAdmin = (): boolean | null =>
  useAuthStore((s) => (s.user ? s.user.role === 'admin' : null));
