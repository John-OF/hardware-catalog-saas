import api from './axios';
import type { Cliente, FichaDeCliente, PaginatedResponse } from '../types';

/** Cómo se ordena la lista de clientes (MOD-10). */
export type OrdenDeClientes = 'recientes' | 'gasto' | 'pedidos';

export interface FiltrosDeClientes {
  search?: string;
  sort?: OrdenDeClientes;
  page?: number;
  /** MOD-24: solo los que llevan esta etiqueta. */
  etiqueta?: string;
  /** MOD-24: solo los que tienen cuenta en el catálogo, o solo los que no. */
  cuenta?: 'con' | 'sin';
}

export const getCustomers = async (params: FiltrosDeClientes = {}): Promise<PaginatedResponse<Cliente>> => {
  const { data } = await api.get<PaginatedResponse<Cliente>>('/customers', { params });
  return data;
};

export const getCustomer = async (id: string): Promise<FichaDeCliente> => {
  const { data } = await api.get<FichaDeCliente>(`/customers/${id}`);
  return data;
};

/** Las etiquetas que usa la tienda, sin repetir (MOD-24). */
export const getEtiquetasDeClientes = async (): Promise<string[]> => {
  const { data } = await api.get<string[]>('/customers/etiquetas');
  return data;
};

/** Lo único que la tienda escribe de una persona: sus notas y sus etiquetas (MOD-24). */
export const updateCustomer = async (
  id: string,
  datos: { notes?: string | null; tags?: string[] },
): Promise<FichaDeCliente> => {
  const { data } = await api.put<FichaDeCliente>(`/customers/${id}`, datos);
  return data;
};

export type ModoDeImportDeClientes = 'omitir' | 'actualizar';

export interface ResultadoDeImportDeClientes {
  created_count: number;
  updated_count: number;
  unchanged_count: number;
  skipped_count: number;
  errors: string[];
}

/** Trae personas al directorio desde un CSV (MOD-24). */
export const importarClientes = async (
  archivo: File,
  modo: ModoDeImportDeClientes,
): Promise<ResultadoDeImportDeClientes> => {
  const fd = new FormData();
  fd.append('file', archivo);
  fd.append('modo', modo);

  const { data } = await api.post<ResultadoDeImportDeClientes>('/customers/import', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
};
