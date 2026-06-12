import { create } from 'zustand';
import type { User } from '../types';

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  setAuth: (token: string, user: User) => void;
  clearAuth: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  user:            null,
  token:           sessionStorage.getItem('token'),
  isAuthenticated: !!sessionStorage.getItem('token'),

  setAuth: (token, user) => {
    sessionStorage.setItem('token', token);
    set({ token, user, isAuthenticated: true });
  },

  clearAuth: () => {
    sessionStorage.clear();
    set({ token: null, user: null, isAuthenticated: false });
  },
}));
