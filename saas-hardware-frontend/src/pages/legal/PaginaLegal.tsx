import './PaginaLegal.css';

import { useEffect, type ReactNode } from 'react';
import { Link, NavLink } from 'react-router-dom';
import { AlertTriangle, Cpu } from 'lucide-react';
import { datosLegales, NOMBRE_DEL_SERVICIO, PAGINAS_LEGALES, ULTIMA_ACTUALIZACION } from '../../utils/legal';

/**
 * El marco común de las tres páginas legales de la plataforma (INF-15):
 * términos, privacidad y reembolsos. Son de la plataforma —de quien presta el
 * servicio a las tiendas—, no de ninguna tienda: una tienda escribe las suyas
 * en *Panel → Páginas*.
 */

/** El nombre del titular, o un aviso visible si no está configurado. */
export function Titular() {
  const { titular } = datosLegales();
  return titular ? <>{titular}</> : <span className="legal-falta">[falta el nombre del titular]</span>;
}

/** El correo de contacto como enlace, o un aviso visible si no está configurado. */
export function Correo() {
  const { correo } = datosLegales();
  return correo ? <a href={`mailto:${correo}`}>{correo}</a> : <span className="legal-falta">[falta el correo de contacto]</span>;
}

type Props = { titulo: string; children: ReactNode };

export default function PaginaLegal({ titulo, children }: Props) {
  const { faltan } = datosLegales();

  useEffect(() => {
    document.title = `${titulo} — ${NOMBRE_DEL_SERVICIO}`;
  }, [titulo]);

  return (
    <div className="page-legal">
      <header className="legal-cabecera">
        <Link to="/" className="legal-marca">
          <span className="legal-logo"><Cpu size={20} /></span>
          {NOMBRE_DEL_SERVICIO}
        </Link>
        <nav className="legal-nav" aria-label="Páginas legales">
          {PAGINAS_LEGALES.map(({ ruta, titulo: nombre }) => (
            <NavLink key={ruta} to={ruta}>{nombre}</NavLink>
          ))}
        </nav>
      </header>

      <main className="legal-cuerpo">
        {faltan.length > 0 && (
          <div className="legal-aviso" role="alert">
            <AlertTriangle size={18} />
            <span>
              Faltan datos del titular ({faltan.join(', ')}): esta página no está lista para publicarse.
            </span>
          </div>
        )}

        <article className="legal-articulo">
          <h1>{titulo}</h1>
          <p className="legal-fecha">Última actualización: {ULTIMA_ACTUALIZACION}</p>
          {children}
        </article>
      </main>

      <footer className="legal-pie">
        <span>{NOMBRE_DEL_SERVICIO}</span>
        <nav aria-label="Otras páginas legales">
          {PAGINAS_LEGALES.map(({ ruta, titulo: nombre }) => (
            <Link key={ruta} to={ruta}>{nombre}</Link>
          ))}
        </nav>
      </footer>
    </div>
  );
}
