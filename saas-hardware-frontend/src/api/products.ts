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

/** Qué pasó con un producto del archivo (FUN-19). */
export type AccionDeImport = 'creado' | 'actualizado' | 'sin_cambios' | 'omitido';

export interface CambioDeImport {
  /** Fila del archivo, contando la cabecera como la 1: la misma que usan los errores. */
  fila: number;
  accion: AccionDeImport;
  producto: string;
  /** "precio 900 → 950, stock 1 → 12" al actualizar; "con 2 variantes" al crear. */
  detalle: string | null;
}

export interface ImportReport {
  message: string;
  /** Lo que se escribió: creados más actualizados. */
  success_count: number;
  created_count: number;
  updated_count: number;
  unchanged_count: number;
  skipped_count: number;
  /** Una entrada por producto del archivo, en el orden del archivo. */
  changes: CambioDeImport[];
  errors: string[];
}

/**
 * Qué hacer con un producto del archivo que ya existe en la tienda —mismo
 * nombre, sin mirar mayúsculas ni tildes— (FUN-17). Por defecto `omitir`: es el
 * único con el que subir dos veces el mismo archivo no cambia nada.
 */
export type ModoDeImport = 'omitir' | 'actualizar' | 'duplicar';

export const importProductsCsv = async (file: File, modo: ModoDeImport = 'omitir'): Promise<ImportReport> => {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('modo', modo);
  const response = await api.post<ImportReport>('/products/import', formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  });
  return response.data;
};

export const duplicateProduct = async (id: string): Promise<Product> => {
  const response = await api.post<Product>(`/products/${id}/duplicate`);
  return response.data;
};

export interface BulkActionPayload {
  product_ids?: string[];
  category_id?: string;
  bulk_action: 'activate' | 'deactivate' | 'delete' | 'adjust_price';
  price_adjustment?: number;
}

export const bulkActionProducts = async (payload: BulkActionPayload): Promise<{ message: string }> => {
  const response = await api.post<{ message: string }>('/products/bulk', payload);
  return response.data;
};

export const reorderProducts = async (ids: string[]): Promise<void> => {
  await api.post('/products/reorder', { ids });
};
