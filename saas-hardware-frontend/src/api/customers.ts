import api from './axios';
import type { Cliente, FichaDeCliente, PaginatedResponse } from '../types';

/** Cómo se ordena la lista de clientes (MOD-10). */
export type OrdenDeClientes = 'recientes' | 'gasto' | 'pedidos';

export interface FiltrosDeClientes {
  search?: string;
  sort?: OrdenDeClientes;
  page?: number;
}

export const getCustomers = async (params: FiltrosDeClientes = {}): Promise<PaginatedResponse<Cliente>> => {
  const { data } = await api.get<PaginatedResponse<Cliente>>('/customers', { params });
  return data;
};

export const getCustomer = async (id: string): Promise<FichaDeCliente> => {
  const { data } = await api.get<FichaDeCliente>(`/customers/${id}`);
  return data;
};
