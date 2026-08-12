import api from './axios';
import type { Tenant, Product, PaginatedResponse, Order, Review } from '../types';

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
    specs?: Record<string, string>;
    /** Whitelist del backend: price_asc | price_desc | newest | name. */
    sort?: string;
    page?: number;
  }
): Promise<PaginatedResponse<Product>> => {
  const response = await api.get<PaginatedResponse<Product>>(`/public/${slug}/products`, { params });
  return response.data;
};

/**
 * Valores de specs de TODO el catálogo, para los filtros (PUB-2).
 *
 * Van en una petición aparte de los productos a propósito: no deben cambiar al
 * paginar ni al filtrar por specs, solo al cambiar de categoría.
 */
export const getPublicFacets = async (
  slug: string,
  params?: { category_id?: string },
): Promise<{ specs: Record<string, string[]> }> => {
  const response = await api.get<{ specs: Record<string, string[]> }>(`/public/${slug}/facets`, { params });
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

export const resolveTenantDomain = async (domain: string): Promise<Tenant> => {
  const response = await api.get<Tenant>('/public/resolve-domain', { params: { domain } });
  return response.data;
};

export interface CreateReviewPayload {
  customer_name: string;
  customer_email?: string;
  rating: number;
  comment?: string;
}

export const createPublicReview = async (
  slug: string,
  productId: string,
  payload: CreateReviewPayload
): Promise<Review> => {
  const response = await api.post<Review>(`/public/${slug}/products/${productId}/reviews`, payload);
  return response.data;
};

export interface StockNotificationPayload {
  customer_name: string;
  customer_contact: string;
}

export const subscribeStockNotification = async (
  slug: string,
  productId: string,
  payload: StockNotificationPayload
): Promise<{ message: string }> => {
  const response = await api.post<{ message: string }>(
    `/public/${slug}/products/${productId}/notify-me`,
    payload
  );
  return response.data;
};
