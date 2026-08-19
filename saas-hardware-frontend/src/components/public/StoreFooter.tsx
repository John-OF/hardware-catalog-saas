import { Link } from 'react-router-dom';
import { Clock, MapPin, Receipt } from 'lucide-react';
import type { Page, Tenant } from '../../types';
import { footerData, hasFooterData, socialLinks } from '../../utils/branding';

interface Props {
  tenant: Tenant;
  /** Páginas informativas publicadas. La tienda que no tenga ninguna no ve la fila. */
  pages?: Page[];
  /** Cómo se arma una ruta pública en esta página: cambia si la tienda va por dominio propio. */
  buildPath: (path: string) => string;
}

/**
 * Pie de la tienda pública (PERS-7 / 10.4).
 *
 * Antes era una lista de páginas y el copyright, igual en todas las tiendas.
 * Ahora enseña los datos que dan confianza —dónde está, cuándo abre, su
 * identificación fiscal y sus redes—, que es lo que un comprador busca en el
 * pie antes de escribir por WhatsApp a un desconocido.
 *
 * Es un componente y no el bloque suelto que había en `CatalogPage` porque el
 * mismo pie va también en las páginas informativas: duplicado, la tienda
 * enseñaría sus datos en el catálogo y no en "Sobre nosotros", que es
 * justamente donde alguien los va a buscar.
 *
 * Cada dato aparece solo si está relleno: el pie de una tienda que no rellenó
 * nada queda exactamente como estaba antes de 10.4.
 */
export default function StoreFooter({ tenant, pages = [], buildPath }: Props) {
  const { address, hours, taxId } = footerData(tenant.theme);
  const redes = socialLinks(tenant.theme);
  const hayMarca = hasFooterData(tenant.theme);

  return (
    <footer className="catalog-footer text-muted">
      {hayMarca && (
        <div className="footer-brand">
          <div className="footer-data">
            {address && (
              <span className="footer-item"><MapPin size={15} /> {address}</span>
            )}
            {hours && (
              <span className="footer-item"><Clock size={15} /> {hours}</span>
            )}
            {taxId && (
              <span className="footer-item"><Receipt size={15} /> {taxId}</span>
            )}
          </div>

          {redes.length > 0 && (
            <div className="footer-social">
              {/* rel="noopener" es obligatorio con target="_blank": sin él la
                  página de la red recibe window.opener y puede redirigir la
                  pestaña de la tienda. Y estos enlaces los escribe el dueño. */}
              {redes.map((red) => (
                <a
                  key={red.key}
                  href={red.url}
                  target="_blank"
                  rel="noopener noreferrer nofollow"
                  className="footer-link"
                >
                  {red.label}
                </a>
              ))}
            </div>
          )}
        </div>
      )}

      {pages.length > 0 && (
        <div className="footer-links">
          {pages.map((page) => (
            <Link
              key={page.id}
              to={buildPath(`/p/${page.slug}`)}
              className="footer-link"
            >
              {page.title}
            </Link>
          ))}
        </div>
      )}

      <p>&copy; {new Date().getFullYear()} {tenant.name}. Todos los derechos reservados.</p>

      <style>{`
        .catalog-footer {
          display: flex; flex-direction: column; align-items: center; gap: 1.5rem;
          padding: 3rem 0; margin-top: 3rem;
          border-top: 1px solid var(--border);
          font-size: 0.85rem;
        }
        .footer-brand { display: flex; flex-direction: column; align-items: center; gap: 0.85rem; }
        /* Fila que se parte sola: son datos sueltos, no columnas. Con tres o
           cuatro cabe en una linea en escritorio y se apila en movil sin una
           sola media query. */
        .footer-data { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.6rem 1.5rem; }
        .footer-item { display: inline-flex; align-items: center; gap: 0.4rem; color: var(--text-secondary); }
        .footer-item svg { color: var(--primary); flex: none; }
        .footer-social { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.5rem; }
        .footer-social .footer-link {
          padding: 0.35rem 0.85rem;
          border: 1px solid var(--border); border-radius: var(--radius-md);
          font-weight: 600;
          transition: var(--transition);
        }
        .footer-social .footer-link:hover { border-color: var(--primary); color: var(--primary); }
        .footer-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 1.5rem; }
        .footer-link { color: var(--text-secondary); text-decoration: none; }
        .footer-link:hover { color: var(--primary); }
      `}</style>
    </footer>
  );
}
