import api from './axios';
import type { Order, PaginatedResponse } from '../types';

export interface GetOrdersParams {
  status?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

export const getOrders = async (params?: GetOrdersParams): Promise<PaginatedResponse<Order>> => {
  const response = await api.get<PaginatedResponse<Order>>('/orders', { params });
  return response.data;
};

/** Venta de mostrador creada desde el panel (OWN-3). El total lo calcula el servidor. */
export interface CreateOrderPayload {
  customer_name: string;
  customer_phone?: string | null;
  customer_note?: string | null;
  status: 'pending' | 'processing' | 'attended';
  items: { product_id: string; quantity: number }[];
}

export const createOrder = async (payload: CreateOrderPayload): Promise<Order> => {
  const response = await api.post<Order>('/orders', payload);
  return response.data;
};

export const getOrder = async (id: string): Promise<Order> => {
  const response = await api.get<Order>(`/orders/${id}`);
  return response.data;
};

export const updateOrderStatus = async (id: string, status: 'pending' | 'processing' | 'attended' | 'cancelled'): Promise<Order> => {
  const response = await api.put<Order>(`/orders/${id}`, { status });
  return response.data;
};

export const deleteOrder = async (id: string): Promise<void> => {
  await api.delete(`/orders/${id}`);
};
