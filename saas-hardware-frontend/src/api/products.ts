import api from './axios';
import type { Product, PaginatedResponse } from '../types';

export const getProducts = async (params?: {
  category_id?: string;
  search?: string;
  active_only?: boolean;
  page?: number;
}): Promise<PaginatedResponse<Product>> => {
  const response = await api.get<PaginatedResponse<Product>>('/products', { params });
  return response.data;
};

export const getProduct = async (id: string): Promise<Product> => {
  const response = await api.get<Product>(`/products/${id}`);
  return response.data;
};

export const createProduct = async (formData: FormData): Promise<Product> => {
  const response = await api.post<Product>('/products', formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  });
  return response.data;
};

export const updateProduct = async (id: string, formData: FormData): Promise<Product> => {
  // Laravel no soporta peticiones multipart/form-data directamente en PUT,
  // por lo que enviamos un POST agregando el campo _method con valor 'PUT'.
  formData.append('_method', 'PUT');
  const response = await api.post<Product>(`/products/${id}`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  });
  return response.data;
};

export const deleteProduct = async (id: string): Promise<void> => {
  await api.delete(`/products/${id}`);
};

export interface ImportReport {
  message: string;
  success_count: number;
  errors: string[];
}

export const importProductsCsv = async (file: File): Promise<ImportReport> => {
  const formData = new FormData();
  formData.append('file', file);
  const response = await api.post<ImportReport>('/products/import', formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  });
  return response.data;
};
