import './LandingPage.css';

import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  Cpu,
  Search,
  Scale,
  Wrench,
  ShoppingCart,
  Heart,
  Star,
  MessageCircle,
  Upload,
  PackageCheck,
  Store,
  Palette,
  Users,
  Globe,
  BarChart3,
  FileText,
  Check,
  Minus,
  Loader2,
} from 'lucide-react';
import { getPublicPlans } from '../../api/public';
import type { PublicPlan } from '../../api/public';

/**
 * Landing del SaaS (INF-1).
 *
 * Hasta ahora no existía: `/` sin tienda resuelta mandaba directo a
 * `/login`, así que quien llegaba al dominio de la plataforma sin saber
 * nada del producto no tenía dónde enterarse de qué es, qué trae ni cuánto
 * cuesta antes de registrarse. La monta `CatalogPage` en su rama de "esto es
 * el dominio de la propia app, no el de una tienda" — no es una ruta propia.
 *
 * El contenido de "qué hace" está sacado del README de la raíz (Qué hace) y
 * no inventado: cada bullet es una función que existe y está documentada en
 * `docs/funcionalidades.md`.
 */

/** Mismo texto que usa `PlatformTenantPage` para cada límite del plan, para no decir la clave cruda. */
const NOMBRES_LIMITE: Record<string, string> = {
  products: 'Productos',
  categories: 'Categorías',
  pages: 'Páginas',
  users: 'Equipo',
  images_per_product: 'Imágenes por producto',
  custom_domain: 'Dominio propio',
  csv_import: 'Importar CSV',
};

/** Orden de lectura de los límites en la tarjeta; no es el orden de `config/plans.php`. */
const ORDEN_LIMITES = ['products', 'categories', 'pages', 'images_per_product', 'users', 'custom_domain', 'csv_import'];

const CARACTERISTICAS_TIENDA = [
  { icon: Store, text: 'Alta de tu tienda en minutos, sin que nadie la apruebe' },
  { icon: Upload, text: 'Importa tu catálogo por CSV en vez de cargarlo producto a producto' },
  { icon: PackageCheck, text: 'Pedidos con estados y descuento automático de stock' },
  { icon: MessageCircle, text: 'La venta se cierra por WhatsApp, como ya la cierras hoy' },
  { icon: Star, text: 'Reseñas moderadas, con protección contra bots' },
  { icon: FileText, text: 'Páginas informativas: quiénes somos, garantía, envíos' },
  { icon: Palette, text: 'Personalización visual completa: colores, tipografías, portada, favicon' },
  { icon: Users, text: 'Equipo con roles: tú decides quién administra y quién solo atiende pedidos' },
  { icon: BarChart3, text: 'Métricas de qué se ve y qué se busca en tu catálogo' },
  { icon: Globe, text: 'Dominio propio verificado, sin el nombre de la plataforma en la URL' },
];

const CARACTERISTICAS_COMPRADOR = [
  { icon: Search, text: 'Buscador con filtros por categoría, disponibilidad y especificaciones reales' },
  { icon: Scale, text: 'Comparador de hasta tres productos lado a lado' },
  { icon: Wrench, text: 'Armador de PC que avisa si dos piezas no son compatibles' },
  { icon: ShoppingCart, text: 'Carrito para pedir varias cosas de una vez' },
  { icon: Heart, text: 'Cuenta con favoritos e historial de sus pedidos' },
];

export default function LandingPage() {
  const { data: planes, isLoading } = useQuery<PublicPlan[]>({
    queryKey: ['publicPlans'],
    queryFn: getPublicPlans,
    staleTime: 5 * 60 * 1000,
  });

  return (
    <div className="landing page-landing">
      <header className="landing-nav">
        <div className="landing-brand">
          <span className="landing-logo"><Cpu size={22} /></span>
          Catálogo de Componentes PC
        </div>
        <nav className="landing-nav-links">
          <a href="#planes">Planes</a>
          <Link to="/login">Iniciar sesión</Link>
          <Link to="/register" className="btn-primary landing-nav-cta">Probar gratis 7 días</Link>
        </nav>
      </header>

      <section className="landing-hero">
        <h1>Tu catálogo de componentes de PC, en línea, hoy</h1>
        <p>
          Da de alta tu tienda, sube tu catálogo con las especificaciones reales de cada pieza y
          cierra tus ventas por WhatsApp, como ya lo haces. Los primeros 7 días son gratis, sin
          tarjeta: eliges tu plan al terminar.
        </p>
        <div className="landing-hero-actions">
          <Link to="/register" className="btn-primary landing-hero-cta">Probar gratis 7 días</Link>
          <a href="#planes" className="btn-secondary">Ver planes</a>
        </div>
      </section>

      <section className="landing-section">
        <h2>Todo lo que necesita tu tienda</h2>
        <div className="landing-grid">
          {CARACTERISTICAS_TIENDA.map(({ icon: Icon, text }) => (
            <div className="landing-feature" key={text}>
              <Icon size={20} />
              <span>{text}</span>
            </div>
          ))}
        </div>
      </section>

      <section className="landing-section landing-section-alt">
        <h2>Pensado para quien compra</h2>
        <div className="landing-grid">
          {CARACTERISTICAS_COMPRADOR.map(({ icon: Icon, text }) => (
            <div className="landing-feature" key={text}>
              <Icon size={20} />
              <span>{text}</span>
            </div>
          ))}
        </div>
      </section>

      <section className="landing-section" id="planes">
        <h2>Planes</h2>
        <p className="landing-section-lead">
          Cualquier plan que elijas empieza con <strong>7 días de prueba gratis</strong>, sin
          tarjeta. Sube de plan cuando tu catálogo lo pida — bajar de plan nunca borra lo que ya
          tengas creado.
        </p>

        {isLoading || !planes ? (
          <div className="landing-plans-loading">
            <Loader2 className="spinner" size={28} />
          </div>
        ) : (
          <div className="landing-plans">
            {planes.map((plan) => (
              <article key={plan.key} className={`landing-plan-card${plan.key === 'pro' ? ' landing-plan-destacado' : ''}`}>
                {plan.key === 'pro' && <span className="landing-plan-badge">Más elegido</span>}
                <h3>{plan.label}</h3>
                <p className="landing-plan-precio">
                  US$ {plan.price_usd}<span> /mes</span>
                </p>
                <ul className="landing-plan-limites">
                  {ORDEN_LIMITES.filter((clave) => clave in plan.limits).map((clave) => {
                    const valor = plan.limits[clave];
                    const nombre = NOMBRES_LIMITE[clave] ?? clave;

                    if (typeof valor === 'boolean') {
                      return (
                        <li key={clave} className={valor ? '' : 'landing-plan-limite-no'}>
                          {valor ? <Check size={16} /> : <Minus size={16} />}
                          {nombre}
                        </li>
                      );
                    }

                    return (
                      <li key={clave}>
                        <Check size={16} />
                        {valor === null ? `${nombre}: sin límite` : `${nombre}: hasta ${valor}`}
                      </li>
                    );
                  })}
                </ul>
                <Link to="/register" className={plan.key === 'pro' ? 'btn-primary' : 'btn-secondary'}>
                  Probar gratis 7 días
                </Link>
              </article>
            ))}
          </div>
        )}
      </section>

      <footer className="landing-footer">
        <span>Catálogo de Componentes PC</span>
        <Link to="/login">Iniciar sesión</Link>
      </footer>
    </div>
  );
}
