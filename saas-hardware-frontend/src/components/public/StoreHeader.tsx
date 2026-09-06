import type { ReactNode } from 'react';
import { Store } from 'lucide-react';
import type { Tenant } from '../../types';
import './StoreHeader.css';

interface StoreHeaderProps {
  /** Solo hace falta para la marca por defecto; con `start` sobra. */
  tenant?: Tenant;
  /**
   * Sustituye el bloque de marca de la izquierda. Lo usa el armador, que en vez
   * del logo pone el enlace de volver y el titulo de la pantalla.
   */
  start?: ReactNode;
  /** Acciones de la derecha. Cada pantalla trae las suyas. */
  children?: ReactNode;
}

/**
 * Cabecera de las pantallas publicas (UI-6).
 *
 * El componente se queda con lo que de verdad compartian -la barra, el bloque
 * de marca y el contenedor de acciones- y deja fuera las acciones, que son
 * distintas en cada pantalla y deben serlo: el catalogo lleva armador, cuenta,
 * carrito y contacto; la informativa, volver y contacto; el armador, pedir el
 * armado. Forzarlas a un molde unico habria sido cambiar duplicacion por
 * parametros, que es peor.
 */
export default function StoreHeader({ tenant, start, children }: StoreHeaderProps) {
  return (
    <header className="catalog-header glass-card">
      {start ?? (tenant && (
        <div className="header-logo-area">
          <div className="store-logo">
            {tenant.logo_url ? (
              <img src={tenant.logo_url} alt={tenant.name} />
            ) : (
              <Store size={28} />
            )}
          </div>
          <h2>{tenant.name}</h2>
        </div>
      ))}
      {children && <div className="header-contact">{children}</div>}
    </header>
  );
}
