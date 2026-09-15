/**
 * Si el navegador está en el dominio de la propia plataforma y no en el dominio
 * propio de una tienda (INF-9).
 *
 * Sin slug en la URL hay dos casos que se ven igual: la portada del SaaS (que
 * enseña la landing) y una tienda con dominio propio (que se resuelve por el
 * host). Antes se distinguían mirando si el host era `localhost`, así que en
 * producción el dominio real de la plataforma se trataba como el de una tienda,
 * `resolve-domain` respondía 404 y la landing no se veía nunca.
 *
 * La lista sale de `VITE_PLATFORM_HOSTS` (hosts separados por comas) y no de
 * `FRONTEND_URL`, que es del backend y el navegador no ve. Es de build, como
 * `VITE_API_URL`. Sin ella valen `localhost` y `127.0.0.1`, que es lo que había.
 */

const HOSTS_POR_DEFECTO = ['localhost', '127.0.0.1'];

/**
 * Se aceptan también entradas escritas como URL ("https://plataforma.com/"):
 * se quedan en el host, sin protocolo, puerto ni ruta.
 */
export function hostsDeLaPlataforma(valor: string | undefined = import.meta.env.VITE_PLATFORM_HOSTS): string[] {
  const hosts = (valor ?? '')
    .split(',')
    .map((entrada) => entrada.trim().toLowerCase().replace(/^[a-z]+:\/\//, '').split(/[/:]/)[0])
    .filter(Boolean);

  return hosts.length > 0 ? hosts : HOSTS_POR_DEFECTO;
}

export function esHostDeLaPlataforma(
  hostname: string = window.location.hostname,
  hosts: string[] = hostsDeLaPlataforma(),
): boolean {
  return hosts.includes(hostname.toLowerCase());
}
