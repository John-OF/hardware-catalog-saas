import api from './axios';
import type { PaginatedResponse, PapeleraResponse, TipoDePapelera } from '../types';
import type { Order, Product } from '../types';

/**
 * La papelera de la tienda (MOD-8). Solo admin.
 *
 * Un solo tipo por petición —`productos` o `pedidos`—, pero los totales de los
 * dos vienen siempre: las pestañas enseñan su número desde el primer render, sin
 * un parpadeo de "Pedidos (0)".
 */
export const getPapelera = async (params: {
  tipo: TipoDePapelera;
  page?: number;
}): Promise<PapeleraResponse<Product | Order>> => {
  const { data } = await api.get<PapeleraResponse<Product | Order>>('/trash', { params });
  return data;
};

/**
 * Devuelve un elemento a su sitio.
 *
 * Puede fallar con 422 si el plan ya no tiene hueco: un producto en la papelera
 * no ocupa sitio, así que restaurar es una creación más a ojos del plan.
 */
export const restaurarDePapelera = async (tipo: TipoDePapelera, id: string): Promise<void> => {
  await api.post(`/trash/${tipo}/${id}/restore`);
};

/** Lo borra de verdad. No tiene vuelta. */
export const borrarDePapelera = async (tipo: TipoDePapelera, id: string): Promise<void> => {
  await api.delete(`/trash/${tipo}/${id}`);
};

/** Vacía la papelera entera, los dos tipos a la vez. */
export const vaciarPapelera = async (): Promise<{ productos: number; pedidos: number }> => {
  const { data } = await api.delete<{ productos: number; pedidos: number }>('/trash');
  return data;
};

export type { PaginatedResponse };
