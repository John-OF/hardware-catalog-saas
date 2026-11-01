import { create } from 'zustand';
import type { CierreDeTienda, Tenant } from '../types';
import { tieneModulo, type ModuloDeVertical } from '../utils/verticales';

interface TenantState {
  tenant: Tenant | null;
  /**
   * Por qué está cerrada la tienda del panel, o `null` si está abierta (FUN-28).
   * Llega con el login y con `GET /auth/me`, y también con el 403 que da
   * cualquier ruta del panel si la tienda se cierra con la sesión abierta
   * (`api/axios.ts`). Mientras tenga valor, el panel pinta `TiendaCerrada` en
   * vez de sus pantallas.
   */
  cierre: CierreDeTienda | null;
  setTenant: (tenant: Tenant) => void;
  setCierre: (cierre: CierreDeTienda | null) => void;
  clearTenant: () => void;
}

export const useTenantStore = create<TenantState>((set) => ({
  tenant: null,
  cierre: null,
  setTenant: (tenant) => {
    sessionStorage.setItem('tenant_slug', tenant.slug);
    set({ tenant });
  },
  setCierre: (cierre) => set({ cierre }),
  clearTenant: () => {
    sessionStorage.removeItem('tenant_slug');
    set({ tenant: null, cierre: null });
  },
}));

/**
 * Si la tienda del panel tiene un módulo de su vertical (MOD-19): el armador,
 * la ficha técnica… Falla en cerrado mientras la tienda no ha cargado. Ver
 * `utils/verticales.ts`.
 */
export const useModulo = (modulo: ModuloDeVertical): boolean =>
  useTenantStore((s) => tieneModulo(s.tenant, modulo));
