/**
 * Pantalla de "algo se rompió" (PUB-5).
 *
 * La comparten las dos redes de seguridad, que hacen falta las dos:
 * - `ErrorBoundary` (clase) cubre lo que está fuera del router.
 * - `RouteErrorFallback` cubre lo que revienta dentro de una ruta, que React
 *   Router intercepta antes de que llegue a ningún boundary de React.
 */
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

      <style>{`
        .error-screen {
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 100vh;
          padding: 1.5rem;
          background: var(--bg-app);
        }

        .error-screen-card {
          max-width: 520px;
          text-align: center;
          display: flex;
          flex-direction: column;
          gap: 1rem;
        }

        .error-screen-card h1 {
          font-size: 1.5rem;
          color: var(--text-primary);
        }

        .error-screen-card p {
          color: var(--text-secondary);
          line-height: 1.6;
        }

        .error-screen-detail {
          text-align: left;
          font-size: 0.78rem;
          color: var(--text-muted);
          background: rgba(0, 0, 0, 0.25);
          border: 1px solid var(--border);
          border-radius: var(--radius-md);
          padding: 0.75rem;
          overflow-x: auto;
          white-space: pre-wrap;
          word-break: break-word;
        }

        .error-screen-actions {
          display: flex;
          gap: 0.75rem;
          justify-content: center;
          flex-wrap: wrap;
        }

        .error-screen-link {
          display: inline-flex;
          align-items: center;
          text-decoration: none;
        }
      `}</style>
    </div>
  );
}
