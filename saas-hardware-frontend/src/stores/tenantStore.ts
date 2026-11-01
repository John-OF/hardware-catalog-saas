import { create } from 'zustand';
import type { Tenant } from '../types';
import { tieneModulo, type ModuloDeVertical } from '../utils/verticales';

interface TenantState {
  tenant: Tenant | null;
  setTenant: (tenant: Tenant) => void;
  clearTenant: () => void;
}

export const useTenantStore = create<TenantState>((set) => ({
  tenant: null,
  setTenant: (tenant) => {
    sessionStorage.setItem('tenant_slug', tenant.slug);
    set({ tenant });
  },
  clearTenant: () => {
    sessionStorage.removeItem('tenant_slug');
    set({ tenant: null });
  },
}));

/**
 * Si la tienda del panel tiene un módulo de su vertical (MOD-19): el armador,
 * la ficha técnica… Falla en cerrado mientras la tienda no ha cargado. Ver
 * `utils/verticales.ts`.
 */
export const useModulo = (modulo: ModuloDeVertical): boolean =>
  useTenantStore((s) => tieneModulo(s.tenant, modulo));
