import api from './axios';
import type { Product, Order } from '../types';

export interface DashboardStats {
  total_sales: number;
  total_orders: number;
  total_products: number;
  catalog_views: number;
  most_viewed_products: Product[];
  recent_orders: Order[];
  orders_by_status: {
    pending: number;
    processing: number;
    attended: number;
    cancelled: number;
  };
}

export const getDashboardStats = async (): Promise<DashboardStats> => {
  const { data } = await api.get<DashboardStats>('/dashboard/stats');
  return data;
};
