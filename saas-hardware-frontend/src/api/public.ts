import api from './axios';
import type { Tenant, Product, PaginatedResponse, Order, Review } from '../types';

export interface CreateOrderPayload {
  customer_name: string;
  customer_phone: string;
  /** Opcional (FUN-2): si lo deja, recibe confirmacion y avisos de estado. */
  customer_email?: string;
  customer_note?: string;
  /** `variant_id` obligatorio para un producto con variantes (MOD-5). */
  items: { product_id: string; variant_id?: string | null; quantity: number }[];
  /**
   * MOD-1. Solo el método viaja; el costo lo calcula el servidor con
   * `tenant.delivery_cost` en ese momento — mandarlo desde aquí no cobraría
   * nada ni valdría como oferta. Se omite si la tienda no tiene envío.
   */
  delivery_method?: 'pickup' | 'delivery';
  /**
   * MOD-4. Igual que el envío: solo viaja el CÓDIGO. El descuento lo calcula el
   * servidor sobre sus propios precios, así que mandarlo desde aquí no
   * descontaría nada.
   */
  coupon_code?: string;
}

export const getPublicTenant = async (slug: string): Promise<Tenant> => {
  const response = await api.get<Tenant>(`/public/${slug}`);
  return response.data;
};

export const getPublicProducts = async (
  slug: string,
  params?: {
    category_id?: string;
    /**
     * Filtra por lo que vende la categoria y no por una categoria concreta
     * (FUN-8): el armador pide "procesadores" y una tienda puede tenerlos
     * partidos en dos categorias por marca.
     */
    component_type?: string;
    search?: string;
    in_stock?: boolean;
    specs?: Record<string, string>;
    /** Whitelist del backend: price_asc | price_desc | newest | name. */
    sort?: string;
    page?: number;
    /**
     * Solo estos productos (MOD-20): las piezas de un armado compartido por
     * enlace. Con `ids`, el resto de filtros no se mira.
     */
    ids?: string[];
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

/**
 * Un plan de LA PLATAFORMA, para la landing (INF-1). No confundir con
 * `PlanInfo` (`types/index.ts`), que es el plan de una tienda YA autenticada
 * y con su consumo — aquí no hay tienda ni sesión, es el catálogo entero.
 */
export interface PublicPlan {
  key: 'free' | 'pro' | 'enterprise';
  label: string;
  /** Precio mensual de la plataforma, en USD. Nada que ver con `tenant.currency`. */
  price_usd: number;
  /** Un número es un tope, `null` sin tope, un booleano una función incluida o no. */
  limits: Record<string, number | boolean | null>;
}

export const getPublicPlans = async (): Promise<PublicPlan[]> => {
  const { data } = await api.get<{ plans: PublicPlan[] }>('/public/plans');
  return data.plans;
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
  /** La variante agotada que espera (MOD-5); obligatoria si el producto tiene variantes. */
  variant_id?: string | null;
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

/**
 * Una incidencia del armado. `unknown` es "no se pudo comprobar": falta un
 * dato, y el mensaje dice cuál y en qué producto. Nunca se pinta como bueno.
 */
export interface IncidenciaDelArmado {
  tipo: 'error' | 'warning' | 'unknown';
  /** Qué regla la dio (`socket`, `largo_gpu`…): para la clave de la lista, no para decidir nada. */
  regla: string;
  mensaje: string;
}

export type EtiquetaDeCandidato = 'compatible' | 'advertencia' | 'incompatible' | 'sin_datos' | 'sin_comprobar';

export interface CompatibilidadDelArmado {
  /** Mientras se elige. */
  armado: { incidencias: IncidenciaDelArmado[]; comprobadas: number };
  /**
   * El mismo armado como si se pidiera ya: lo que falta deja de ser "todavía"
   * (un procesador sin gráficos y sin tarjeta es un error). Viene en la misma
   * respuesta para mirarlo al pulsar "Pedir armado" sin esperar a nada: abrir
   * WhatsApp después de una petición es lo que el navegador bloquea.
   */
  al_pedir: { incidencias: IncidenciaDelArmado[]; comprobadas: number };
  /** Null sin ninguna pieza elegida. `asumido`: alguna pieza no dice su consumo y se usó uno típico. */
  consumo: { estimado: number; recomendado: number; asumido: boolean } | null;
  /** Lo que aporta cada candidato del cajón, sin heredar lo que ya fallaba entre otras piezas. */
  candidatos: Record<string, { etiqueta: EtiquetaDeCandidato; incidencias: IncidenciaDelArmado[] }>;
  /** Piezas elegidas que ya no están en el catálogo: se trataron como no elegidas. */
  no_disponibles: string[];
}

/**
 * El veredicto del armador de PC (FUN-8, 13.5). Las quince reglas viven en el
 * servidor (`App\Support\Compatibilidad`); aquí no hay ninguna copia.
 *
 * Con `paso` y `candidatos` (el cajón abierto), trae además la etiqueta de cada
 * candidato.
 */
export const getCompatibilidad = async (
  slug: string,
  params: { seleccion: Record<string, string>; paso?: string; candidatos?: string[] },
): Promise<CompatibilidadDelArmado> => {
  const response = await api.get<CompatibilidadDelArmado>(`/public/${slug}/builder/compatibility`, { params });
  return response.data;
};
