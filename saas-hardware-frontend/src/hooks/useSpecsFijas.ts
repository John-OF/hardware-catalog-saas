import { useQuery } from '@tanstack/react-query';
import { getSpecsFijas } from '../api/specsFijas';

/**
 * La definición de las specs fijas (FUN-8), pedida una vez por sesión.
 *
 * No cambia mientras la página está abierta —cambia con un despliegue—, así que
 * no se vuelve a pedir. Mientras no llega, quien la usa pinta las specs con su
 * clave tal cual (ver `utils/specsFijas`): nada se esconde por esperarla.
 */
export function useSpecsFijas() {
  return useQuery({
    queryKey: ['specs-fijas'],
    queryFn: getSpecsFijas,
    staleTime: Infinity,
    gcTime: Infinity,
  });
}
