import { Navigate } from 'react-router-dom';
import { useEsAdmin } from '../stores/authStore';

interface Props {
  children: React.ReactNode;
}

/**
 * Pantallas del panel que solo son de admin (FUN-4): configuración, categorías,
 * páginas y equipo.
 *
 * Quien es staff y llega aquí —por un enlace guardado o escribiendo la URL— va
 * al resumen en vez de a una pantalla que se pinta y luego falla con 403 en cada
 * petición. Mientras no se sabe el rol no se pinta nada: `DashboardPage` ya
 * enseña su propio cargador, y adivinar aquí sería enseñarle a staff durante un
 * instante lo que no es suyo o echar a un admin de su pantalla al recargar.
 */
export default function SoloAdmin({ children }: Props) {
  const esAdmin = useEsAdmin();

  if (esAdmin === null) {
    return null;
  }

  if (!esAdmin) {
    return <Navigate to="/dashboard" replace />;
  }

  return <>{children}</>;
}
