import { create } from 'zustand';
import type { Tenant } from '../types';

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
