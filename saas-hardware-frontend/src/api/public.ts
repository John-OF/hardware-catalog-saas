import api from './axios';
import type { Tenant, Product, PaginatedResponse, Order } from '../types';

export interface CreateOrderPayload {
  customer_name: string;
  customer_phone: string;
  customer_note?: string;
  items: { product_id: string; quantity: number }[];
}

export const getPublicTenant = async (slug: string): Promise<Tenant> => {
  const response = await api.get<Tenant>(`/public/${slug}`);
  return response.data;
};

export const getPublicProducts = async (
  slug: string,
  params?: {
    category_id?: string;
    search?: string;
    in_stock?: boolean;
    page?: number;
  }
): Promise<PaginatedResponse<Product>> => {
  const response = await api.get<PaginatedResponse<Product>>(`/public/${slug}/products`, { params });
  return response.data;
};

export const getPublicProduct = async (slug: string, productId: string): Promise<Product> => {
  const response = await api.get<Product>(`/public/${slug}/products/${productId}`);
  return response.data;
};

export const createPublicOrder = async (slug: string, payload: CreateOrderPayload): Promise<Order> => {
  const response = await api.post<Order>(`/public/${slug}/orders`, payload);
  return response.data;
};
