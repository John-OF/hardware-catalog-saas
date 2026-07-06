import { create } from 'zustand';
import type { User } from '../types';

interface CustomerAuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  favoriteIds: string[];
  setCustomerAuth: (token: string, user: User) => void;
  clearCustomerAuth: () => void;
  setFavoriteIds: (ids: string[]) => void;
  toggleFavoriteId: (id: string) => void;
}

export const useCustomerAuthStore = create<CustomerAuthState>((set) => {
  const token = localStorage.getItem('customer_token');
  let user: User | null = null;
  try {
    const savedUser = localStorage.getItem('customer_user');
    user = savedUser ? JSON.parse(savedUser) : null;
  } catch {
    user = null;
  }

  return {
    user,
    token,
    isAuthenticated: !!token,
    favoriteIds: [],

    setCustomerAuth: (token, user) => {
      localStorage.setItem('customer_token', token);
      localStorage.setItem('customer_user', JSON.stringify(user));
      set({ token, user, isAuthenticated: true });
    },

    clearCustomerAuth: () => {
      localStorage.removeItem('customer_token');
      localStorage.removeItem('customer_user');
      set({ token: null, user: null, isAuthenticated: false, favoriteIds: [] });
    },

    setFavoriteIds: (ids) => {
      set({ favoriteIds: ids });
    },

    toggleFavoriteId: (id) => {
      set((state) => {
        const exists = state.favoriteIds.includes(id);
        const newIds = exists
          ? state.favoriteIds.filter((favId) => favId !== id)
          : [...state.favoriteIds, id];
        return { favoriteIds: newIds };
      });
    },
  };
});
