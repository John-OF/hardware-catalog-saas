import api from './axios';
import type { StockNotification, PaginatedResponse } from '../types';

/**
 * Lista de espera de "avísame cuando llegue" (FUN-1b).
 *
 * No hay `create`: las filas las crea el catálogo público cuando un cliente se
 * apunta a un producto agotado. Desde el panel solo se miran, se marcan como
 * avisadas o se borran.
 */
export const getStockNotifications = async (params?: {
  status?: 'pending' | 'notified';
  product_id?: string;
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<StockNotification>> => {
  const { data } = await api.get<PaginatedResponse<StockNotification>>('/stock-notifications', { params });
  return data;
};

/** Marcar como avisado a mano, o devolverlo a pendiente si fue un error de dedo. */
export const setStockNotificationNotified = async (
  id: string,
  notified: boolean,
): Promise<StockNotification> => {
  const { data } = await api.put<StockNotification>(`/stock-notifications/${id}`, { notified });
  return data;
};

export const deleteStockNotification = async (id: string): Promise<void> => {
  await api.delete(`/stock-notifications/${id}`);
};
