import api from './axios';

/**
 * Descargar el catálogo y los pedidos en CSV (MOD-7).
 *
 * **Por qué no es un enlace normal.** Las dos rutas van detrás de `auth:sanctum`
 * y del header `X-Tenant`, y un `<a href>` no manda ni el token ni el tenant: el
 * navegador abriría una pestaña con un 401 en JSON. Así que se piden por axios
 * —que ya inyecta los dos— y el archivo se guarda desde el navegador.
 */

/** Los filtros del listado de productos, tal cual los entiende el backend. */
export interface FiltrosDeCatalogo {
  category_id?: string;
  search?: string;
  active_only?: boolean;
}

/** Los del listado de pedidos, más el rango de fechas que solo existe al exportar. */
export interface FiltrosDePedidos {
  status?: string;
  desde?: string;
  hasta?: string;
}

export const exportarCatalogo = (filtros: FiltrosDeCatalogo = {}): Promise<void> =>
  descargar('/products/export', filtros);

export const exportarPedidos = (filtros: FiltrosDePedidos = {}): Promise<void> =>
  descargar('/orders/export', filtros);

/**
 * Pide el CSV y lo guarda con el nombre que manda el servidor.
 *
 * El nombre sale de `Content-Disposition` y no se arma aquí para que el archivo
 * se llame igual venga de donde venga; si la cabecera no llega —un proxy que la
 * recorta— se usa uno de repuesto en vez de dejar al dueño con un "descarga".
 */
async function descargar(ruta: string, params: object): Promise<void> {
  const respuesta = await api.get(ruta, {
    params: limpiar(params),
    responseType: 'blob',
  });

  const url = URL.createObjectURL(respuesta.data as Blob);
  const enlace = document.createElement('a');

  enlace.href = url;
  enlace.download = nombreDeArchivo(respuesta.headers['content-disposition'], ruta);
  document.body.appendChild(enlace);
  enlace.click();
  enlace.remove();

  // Sin esto el blob se queda en memoria hasta que se recargue la página, y
  // exportar varias veces seguidas va acumulando catálogos enteros.
  URL.revokeObjectURL(url);
}

/** Los filtros vacíos no se mandan: `?search=` haría buscar la cadena vacía. */
function limpiar(params: object): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(params).filter(([, valor]) => valor !== undefined && valor !== null && valor !== ''),
  );
}

export function nombreDeArchivo(cabecera: unknown, ruta: string): string {
  const encontrado = typeof cabecera === 'string' ? /filename=([^;]+)/i.exec(cabecera) : null;

  if (encontrado) {
    return encontrado[1].trim().replace(/^"|"$/g, '');
  }

  return ruta.includes('orders') ? 'pedidos.csv' : 'catalogo.csv';
}
