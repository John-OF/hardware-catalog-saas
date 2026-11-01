import api from './axios';
import type { ClaveDePlantilla } from '../utils/plantillasWhatsapp';

/**
 * Los mensajes de WhatsApp de la tienda (MOD-25), tal como los ve el panel.
 *
 * `texto` es el que usa la tienda hoy (el suyo o el de fábrica), `de_fabrica`
 * el original —para "restaurar" y para comparar— y `personalizada` si la
 * tienda lo cambió. Leerlos no pide permiso (los usan Pedidos y Lista de
 * espera); guardarlos pide `tienda.configurar`.
 */
export interface PlantillaWhatsapp {
  clave: ClaveDePlantilla;
  /** `comprador`: lo escribe el comprador y te llega a ti. `tienda`: lo mandas tú. */
  grupo: 'comprador' | 'tienda';
  etiqueta: string;
  cuando: string;
  /** Las variables que admite, sin llaves: `['cliente', 'pedido', …]`. */
  variables: string[];
  texto: string;
  de_fabrica: string;
  personalizada: boolean;
}

export interface PlantillasWhatsapp {
  /** Largo máximo de cada texto. */
  max: number;
  /** Qué significa cada variable, para la ayuda del editor. */
  variables: Record<string, string>;
  plantillas: PlantillaWhatsapp[];
}

export const getPlantillasWhatsapp = async (): Promise<PlantillasWhatsapp> => {
  const { data } = await api.get<PlantillasWhatsapp>('/whatsapp-templates');
  return data;
};

/**
 * Guarda las que se mandan; las que no, no se tocan. `null` (o el texto de
 * fábrica, o vacío) vuelve al de fábrica.
 */
export const guardarPlantillasWhatsapp = async (
  plantillas: Partial<Record<ClaveDePlantilla, string | null>>,
): Promise<PlantillasWhatsapp> => {
  const { data } = await api.put<PlantillasWhatsapp>('/whatsapp-templates', { plantillas });
  return data;
};

/** El texto de una plantilla, o vacío mientras no han llegado. */
export const textoDePlantilla = (datos: PlantillasWhatsapp | undefined, clave: ClaveDePlantilla): string =>
  datos?.plantillas.find((p) => p.clave === clave)?.texto ?? '';
