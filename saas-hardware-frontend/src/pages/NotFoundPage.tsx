import './NotFoundPage.css';

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
    <div className="not-found page-not-found">
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

    </div>
  );
}
