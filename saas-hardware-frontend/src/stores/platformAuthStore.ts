import { create } from 'zustand';
import type { User } from '../types';
import { PLATFORM_TOKEN_KEY } from '../api/platform';

/**
 * Sesión del operador de la plataforma (SAAS-4).
 *
 * Store aparte del de tiendas y con su propia clave en sessionStorage: son dos
 * sesiones distintas y no deben pisarse si alguien abre las dos.
 */
interface PlatformAuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  setPlatformAuth: (token: string, user: User) => void;
  clearPlatformAuth: () => void;
}

export const usePlatformAuthStore = create<PlatformAuthState>((set) => ({
  user: null,
  token: sessionStorage.getItem(PLATFORM_TOKEN_KEY),
  isAuthenticated: !!sessionStorage.getItem(PLATFORM_TOKEN_KEY),

  setPlatformAuth: (token, user) => {
    sessionStorage.setItem(PLATFORM_TOKEN_KEY, token);
    set({ token, user, isAuthenticated: true });
  },

  clearPlatformAuth: () => {
    sessionStorage.removeItem(PLATFORM_TOKEN_KEY);
    set({ token: null, user: null, isAuthenticated: false });
  },
}));
