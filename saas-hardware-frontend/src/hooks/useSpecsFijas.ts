import { useQuery } from '@tanstack/react-query';
import { getSpecsFijas } from '../api/specsFijas';

/**
 * La definición de las specs fijas (FUN-8), pedida una vez por sesión.
 *
 * No cambia mientras la página está abierta —cambia con un despliegue—, así que
 * no se vuelve a pedir. Mientras no llega, quien la usa pinta las specs con su
 * clave tal cual (ver `utils/specsFijas`): nada se esconde por esperarla.
 *
 * `activo` es el módulo de ficha técnica de la tienda (MOD-19). Apagado, no se
 * pide y devuelve `undefined` aunque esté en caché de otra tienda: sin
 * definición, cada spec se enseña como libre, con su clave y su valor tal cual.
 */
export function useSpecsFijas(activo = true) {
  const { data } = useQuery({
    queryKey: ['specs-fijas'],
    queryFn: getSpecsFijas,
    staleTime: Infinity,
    gcTime: Infinity,
    enabled: activo,
  });

  return { data: activo ? data : undefined };
}
