import api from './axios';
import type { Tenant, Product, PaginatedResponse } from '../types';

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
