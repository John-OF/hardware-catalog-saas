import api from './axios';

/**
 * Reportes de la tienda (MOD-9).
 *
 * Es otra cosa que `dashboard.ts`: aquel trae los totales de siempre para la
 * pantalla de Resumen, y este responde "cuánto vendí en agosto", "qué se vende
 * de verdad" y "qué se me está acabando".
 *
 * **Las claves de costo son opcionales a propósito.** El backend no las manda a
 * staff (MOD-6): no llegan vacías, no llegan. Por eso van con `?` y la pantalla
 * pregunta si existen en vez de mirar el rol por su cuenta — así lo que se pinta
 * es lo que el servidor de verdad dejó ver.
 */

export type Agrupacion = 'dia' | 'mes';

export interface RangoDelReporte {
  desde: string;
  hasta: string;
  agrupacion: Agrupacion;
}

export interface ResumenDelReporte {
  /** Lo cobrado en los pedidos atendidos del rango, envío incluido. */
  ventas: number;
  envio: number;
  pedidos: number;
  ticket_promedio: number;
  unidades: number;
  /** Todo lo que entró en el rango, se atendiera o no. */
  recibidos: number;
  cancelados: number;
  costo?: number;
  utilidad?: number;
  /** Porcentaje, ya calculado: 17.6 es 17,6 %. */
  margen?: number;
  lineas_sin_costo?: number;
}

/** Un punto de la gráfica. `periodo` es `2026-09-15` o `2026-09` según agrupación. */
export interface PuntoDelReporte {
  periodo: string;
  ventas: number;
  pedidos: number;
  unidades: number;
  utilidad?: number;
}

export interface ProductoVendido {
  /** `null` cuando la ficha ya no existe: el nombre es el de la venta. */
  product_id: string | null;
  nombre: string;
  unidades: number;
  ventas: number;
  utilidad?: number;
}

export interface FilaDeStockBajo {
  product_id: string;
  /** "Memoria Kingston (32 GB)" cuando lo que está bajo es una variante. */
  nombre: string;
  sku: string | null;
  stock: number;
  umbral: number;
}

export interface Reporte {
  rango: RangoDelReporte;
  resumen: ResumenDelReporte;
  serie: PuntoDelReporte[];
  mas_vendidos: ProductoVendido[];
  stock_bajo: FilaDeStockBajo[];
}

export interface FiltrosDelReporte {
  desde?: string;
  hasta?: string;
  agrupacion?: Agrupacion;
}

export const getReport = async (filtros: FiltrosDelReporte = {}): Promise<Reporte> => {
  const { data } = await api.get<Reporte>('/reports', { params: filtros });
  return data;
};
