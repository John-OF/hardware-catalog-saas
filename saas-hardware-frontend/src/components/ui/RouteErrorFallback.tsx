import { useRouteError } from 'react-router-dom';
import ErrorScreen from './ErrorScreen';

/**
 * `errorElement` de las rutas (PUB-5).
 *
 * React Router captura los errores lanzados dentro de una ruta y los resuelve
 * él mismo, así que nunca llegan a un boundary de React: sin esto, el usuario
 * veía la pantalla de desarrollo de React Router ("Unexpected Application
 * Error!") con el stack trace entero.
 */
export default function RouteErrorFallback() {
  const error = useRouteError();

  console.error('Error no controlado en una ruta:', error);

  // El detalle solo en desarrollo: en producción no le sirve al comprador y
  // puede filtrar detalles de implementación.
  const detail = import.meta.env.DEV && error instanceof Error ? error.message : null;

  return <ErrorScreen detail={detail} />;
}
