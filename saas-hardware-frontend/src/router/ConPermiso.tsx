import { Navigate } from 'react-router-dom';
import { usePuede } from '../stores/authStore';
import type { Permiso } from '../utils/permisos';

interface Props {
  permiso: Permiso;
  children: React.ReactNode;
}

/**
 * Pantalla del panel que pide un permiso por acción (MOD-21). Lo mismo que
 * `SoloAdmin`, pero con el permiso de la pantalla en vez del rol.
 *
 * Quien no lo tiene y llega aquí —por un enlace guardado o escribiendo la
 * URL— va al resumen, que nunca redirige (sin `resumen.ver` enseña un saludo),
 * así que no hay bucle. Mientras no se sabe qué puede no se pinta nada, por el
 * mismo motivo que en `SoloAdmin`.
 */
export default function ConPermiso({ permiso, children }: Props) {
  const puede = usePuede(permiso);

  if (puede === null) {
    return null;
  }

  if (!puede) {
    return <Navigate to="/dashboard" replace />;
  }

  return <>{children}</>;
}
