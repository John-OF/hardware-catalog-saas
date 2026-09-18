import api from './axios';
import type { Coupon, PaginatedResponse } from '../types';

/**
 * Cupones de la tienda (MOD-4). El CRUD es solo admin; la comprobación de un
 * código desde el carrito es pública y vive más abajo.
 */
export interface CouponPayload {
  code: string;
  type: 'percent' | 'fixed';
  value: number | string;
  min_purchase?: number | string | null;
  max_uses?: number | null;
  starts_at?: string | null;
  ends_at?: string | null;
  is_active?: boolean;
}

export const getCoupons = async (page = 1): Promise<PaginatedResponse<Coupon>> => {
  const { data } = await api.get<PaginatedResponse<Coupon>>('/coupons', { params: { page } });
  return data;
};

export const createCoupon = async (payload: CouponPayload): Promise<Coupon> => {
  const { data } = await api.post<Coupon>('/coupons', payload);
  return data;
};

export const updateCoupon = async (id: string, payload: Partial<CouponPayload>): Promise<Coupon> => {
  const { data } = await api.put<Coupon>(`/coupons/${id}`, payload);
  return data;
};

export const deleteCoupon = async (id: string): Promise<void> => {
  await api.delete(`/coupons/${id}`);
};

/** Lo que devuelve la comprobación pública de un código. */
export interface CouponCheck {
  code: string;
  type: 'percent' | 'fixed';
  value: number | string;
  discount: number;
}

/**
 * Comprueba un código desde el carrito, antes de confirmar (MOD-4).
 *
 * El `subtotal` que se manda **no decide nada**: sirve para la compra mínima y
 * para enseñar cuánto descontaría. Lo que se cobra lo vuelve a calcular el
 * servidor al crear el pedido, sobre sus propios precios.
 *
 * Un 422 trae el motivo legible en `errors.coupon_code[0]` ("ese código ya
 * venció", "pide una compra mínima mayor"), que es lo que se enseña debajo del
 * campo en vez de un "código inválido" genérico (UI-11).
 */
export const comprobarCupon = async (
  slug: string,
  code: string,
  subtotal: number,
): Promise<CouponCheck> => {
  const { data } = await api.post<CouponCheck>(`/public/${slug}/coupons/check`, { code, subtotal });
  return data;
};
