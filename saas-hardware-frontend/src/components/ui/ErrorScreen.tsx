/**
 * Pantalla de "algo se rompió" (PUB-5).
 *
 * La comparten las dos redes de seguridad, que hacen falta las dos:
 * - `ErrorBoundary` (clase) cubre lo que está fuera del router.
 * - `RouteErrorFallback` cubre lo que revienta dentro de una ruta, que React
 *   Router intercepta antes de que llegue a ningún boundary de React.
 */
import './ErrorScreen.css';

export default function ErrorScreen({ detail }: { detail?: string | null }) {
  return (
    <div className="error-screen">
      <div className="error-screen-card">
        <h1>Algo se rompió de nuestro lado</h1>
        <p>
          No pudimos mostrar esta página. Puedes recargar o volver al inicio; si vuelve a pasar,
          avísale a la tienda.
        </p>

        {/* En producción `detail` va vacío: el mensaje de un error interno no le
            sirve al comprador y puede filtrar detalles de implementación. */}
        {detail && <pre className="error-screen-detail">{detail}</pre>}

        <div className="error-screen-actions">
          <button type="button" className="btn-primary" onClick={() => window.location.reload()}>
            Recargar la página
          </button>
          <a className="btn-secondary error-screen-link" href="/">
            Ir al inicio
          </a>
        </div>
      </div>

    </div>
  );
}
