import { Link } from 'react-router-dom';
import { Compass } from 'lucide-react';

/**
 * Ruta inexistente (PUB-5).
 *
 * Antes cualquier URL rara caía en `/:slug`, que intentaba resolver una tienda
 * con ese nombre; ahora hay una respuesta clara.
 */
export default function NotFoundPage() {
  return (
    <div className="not-found">
      <div className="not-found-card">
        <div className="not-found-badge">
          <Compass size={30} />
        </div>
        <h1>Esta página no existe</h1>
        <p>
          El enlace puede estar mal escrito o la tienda que buscas ya no está disponible.
        </p>
        <Link to="/login" className="btn-primary not-found-cta">
          Ir al panel
        </Link>
      </div>

      <style>{`
        .not-found {
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 100vh;
          padding: 1.5rem;
          background: var(--bg-app);
        }

        .not-found-card {
          max-width: 420px;
          text-align: center;
          display: flex;
          flex-direction: column;
          align-items: center;
          gap: 1rem;
        }

        .not-found-badge {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 60px;
          height: 60px;
          border-radius: 16px;
          background: rgba(37, 99, 235, 0.08);
          border: 1px solid rgba(37, 99, 235, 0.2);
          color: var(--primary);
        }

        .not-found-card h1 {
          font-size: 1.5rem;
          color: var(--text-primary);
        }

        .not-found-card p {
          color: var(--text-secondary);
          line-height: 1.6;
        }

        .not-found-cta {
          text-decoration: none;
        }
      `}</style>
    </div>
  );
}
