import api from './axios';
import type { Order, PaginatedResponse } from '../types';

export interface GetOrdersParams {
  status?: string;
  /** MOD-26: solo los que tienen un comprobante esperando revisión. */
  payment_status?: 'en_revision';
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
  customer_email?: string | null;
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

/** MOD-26: confirmar el pago o rechazar el comprobante (con el motivo que verá el comprador). */
export const revisarPago = async (id: string, decision: 'confirmar' | 'rechazar', motivo?: string): Promise<Order> => {
  const response = await api.post<Order>(`/orders/${id}/pago`, { decision, motivo });
  return response.data;
};

/**
 * MOD-26: el comprobante que mandó el comprador, como blob. Va por axios porque
 * la ruta pide el token y la tienda, que un `<img src>` no manda.
 */
export const getComprobante = async (id: string): Promise<Blob> => {
  const response = await api.get(`/orders/${id}/comprobante`, { responseType: 'blob' });
  return response.data as Blob;
};
