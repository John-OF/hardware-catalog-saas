import './OverviewPage.css';

import { useQuery } from '@tanstack/react-query';
import { 
  DollarSign, 
  ShoppingBag, 
  Package, 
  Eye, 
  Loader2, 
  TrendingUp, 
  Calendar,
  ArrowRight
} from 'lucide-react';
import Imagen from '../../components/ui/Imagen';
import { Link } from 'react-router-dom';
import { getDashboardStats } from '../../api/dashboard';
import type { DashboardStats } from '../../api/dashboard';
import { useTenantStore } from '../../stores/tenantStore';
import { useAuthStore, usePuede } from '../../stores/authStore';
import { formatearFecha } from '../../utils/fechas';
import { formatMoney } from '../../utils/money';
import type { Permiso } from '../../utils/permisos';

/**
 * Las pantallas a las que se puede ir desde el saludo de quien no ve el
 * resumen (MOD-21), con el permiso que abre cada una: el mismo de `ConPermiso`.
 */
const PANTALLAS: { permiso: Permiso; ruta: string; nombre: string }[] = [
  { permiso: 'pedidos.ver', ruta: '/dashboard/orders', nombre: 'Pedidos' },
  { permiso: 'productos.ver', ruta: '/dashboard/products', nombre: 'Productos' },
  { permiso: 'clientes.ver', ruta: '/dashboard/customers', nombre: 'Clientes' },
  { permiso: 'reportes.ver', ruta: '/dashboard/reports', nombre: 'Reportes' },
  { permiso: 'resenas.gestionar', ruta: '/dashboard/reviews', nombre: 'Reseñas' },
  { permiso: 'espera.gestionar', ruta: '/dashboard/waitlist', nombre: 'Lista de espera' },
  { permiso: 'cupones.gestionar', ruta: '/dashboard/coupons', nombre: 'Cupones' },
  { permiso: 'categorias.gestionar', ruta: '/dashboard/categories', nombre: 'Categorías' },
  { permiso: 'paginas.gestionar', ruta: '/dashboard/pages', nombre: 'Páginas' },
  { permiso: 'actividad.ver', ruta: '/dashboard/activity', nombre: 'Actividad' },
  { permiso: 'papelera.gestionar', ruta: '/dashboard/trash', nombre: 'Papelera' },
  { permiso: 'tienda.configurar', ruta: '/dashboard/settings', nombre: 'Configuración' },
];

/**
 * Lo que ve en `/dashboard` quien no tiene `resumen.ver` (MOD-21). Es también
 * a donde manda `ConPermiso`, así que no puede redirigir a ningún sitio.
 */
function SinResumen() {
  const user = useAuthStore((s) => s.user);
  const permisos = user?.permisos ?? [];
  const pantallas = PANTALLAS.filter((p) => permisos.includes(p.permiso));

  return (
    <div className="overview-page animate-fade-in page-overview">
      <div className="state-card overview-sin-resumen">
        <h3>Hola{user?.name ? `, ${user.name}` : ''}</h3>
        {pantallas.length > 0 ? (
          <>
            <p>
              Tu rol{user?.team_role ? ` «${user.team_role.name}»` : ''} no incluye el resumen de la tienda. Puedes
              ir a:
            </p>
            <div className="overview-sin-resumen-enlaces">
              {pantallas.map((p) => (
                <Link key={p.ruta} to={p.ruta} className="btn-secondary">
                  {p.nombre}
                </Link>
              ))}
            </div>
          </>
        ) : (
          <p>
            Tu rol todavía no tiene ningún permiso en el panel. Pídele a un administrador de la tienda que te dé acceso
            a lo que necesites.
          </p>
        )}
      </div>
    </div>
  );
}

export default function OverviewPage() {
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency);
  const puedeVerResumen = usePuede('resumen.ver');

  const { data: stats, isLoading, isError } = useQuery<DashboardStats>({
    queryKey: ['dashboardStats'],
    queryFn: getDashboardStats,
    refetchInterval: 30000, // Autorefresh every 30s
    // MOD-21: sin el permiso, la petición sería un 403 cada 30 segundos.
    enabled: puedeVerResumen === true,
  });

  if (puedeVerResumen === false) {
    return <SinResumen />;
  }

  if (isLoading || puedeVerResumen === null) {
    return (
      <div className="overview-loading page-overview">
        <Loader2 className="spinner" size={32} />
        <p>Cargando estadísticas...</p>
      </div>
    );
  }

  if (isError || !stats) {
    return (
      <div className="overview-error glass-card page-overview">
        <h3>Error al cargar métricas</h3>
        <p>No se pudieron calcular las estadísticas de la tienda. Por favor, intenta de nuevo más tarde.</p>
      </div>
    );
  }

  // Helper values
  const pendingCount = stats.orders_by_status.pending;
  const processingCount = stats.orders_by_status.processing;

  return (
    <div className="overview-page animate-fade-in page-overview">
      <div className="overview-welcome">
        <p className="page-description">
          Monitorea el rendimiento de tu catálogo, visitas de clientes y flujo de pedidos de hardware en tiempo real.
        </p>
      </div>

      {/* KPI Cards Grid */}
      <div className="kpi-grid">
        {/* Ventas Totales */}
        <div className="kpi-card glass-card">
          <div className="kpi-icon-wrapper sales-icon">
            <DollarSign size={22} />
          </div>
          <div className="kpi-data">
            <span className="kpi-label">Ventas Totales</span>
            <h3 className="kpi-value">{money(stats.total_sales)}</h3>
            <span className="kpi-trend positive">
              <TrendingUp size={14} /> Atendidos
            </span>
          </div>
        </div>

        {/* Pedidos Totales */}
        <div className="kpi-card glass-card">
          <div className="kpi-icon-wrapper orders-icon">
            <ShoppingBag size={22} />
          </div>
          <div className="kpi-data">
            <span className="kpi-label">Pedidos Recibidos</span>
            <h3 className="kpi-value">{stats.total_orders}</h3>
            <span className="kpi-trend warning">
              {pendingCount + processingCount} activos
            </span>
          </div>
        </div>

        {/* Productos Activos */}
        <div className="kpi-card glass-card">
          <div className="kpi-icon-wrapper products-icon">
            <Package size={22} />
          </div>
          <div className="kpi-data">
            <span className="kpi-label">Productos Activos</span>
            <h3 className="kpi-value">{stats.total_products}</h3>
            <span className="kpi-trend info">
              En exhibición
            </span>
          </div>
        </div>

        {/* Visitas al Catálogo */}
        <div className="kpi-card glass-card">
          <div className="kpi-icon-wrapper views-icon">
            <Eye size={22} />
          </div>
          <div className="kpi-data">
            <span className="kpi-label">Visitas de Clientes</span>
            <h3 className="kpi-value">{stats.catalog_views}</h3>
            <span className="kpi-trend positive">
              <TrendingUp size={14} /> Total clics
            </span>
          </div>
        </div>
      </div>

      {/* Main content split */}
      <div className="overview-split">
        {/* Most Viewed Products */}
        <div className="split-column glass-card">
          <div className="column-header">
            <h4>Componentes Más Vistos</h4>
            <Link to="/dashboard/products" className="view-all-link">
              Ver inventario <ArrowRight size={14} />
            </Link>
          </div>

          <div className="list-container">
            {stats.most_viewed_products.length === 0 ? (
              <p className="empty-text">Aún no hay visitas registradas en tus productos.</p>
            ) : (
              stats.most_viewed_products.map((product) => (
                <div key={product.id} className="list-item-row">
                  <div className="item-left">
                    <div className="product-thumb">
                      <Imagen
                        loading="lazy" decoding="async" src={product.thumbnail_url} alt={product.name}
                        respaldo={<Package size={16} className="text-muted" />}
                      />
                    </div>
                    <div className="product-info-text">
                      <h5>{product.name}</h5>
                      <span>{product.brand || 'Genérico'}</span>
                    </div>
                  </div>
                  <div className="item-right">
                    <span className="views-pill">
                      <Eye size={12} /> {product.views_count}
                    </span>
                    <span className={`stock-pill ${product.stock > 0 ? 'in-stock' : 'out-of-stock'}`}>
                      {product.stock > 0 ? `${product.stock} u.` : 'Agotado'}
                    </span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Recent Orders */}
        <div className="split-column glass-card">
          <div className="column-header">
            <h4>Pedidos Recientes</h4>
            <Link to="/dashboard/orders" className="view-all-link">
              Ver pedidos <ArrowRight size={14} />
            </Link>
          </div>

          <div className="list-container">
            {stats.recent_orders.length === 0 ? (
              <p className="empty-text">No has recibido pedidos aún.</p>
            ) : (
              stats.recent_orders.map((order) => (
                <div key={order.id} className="list-item-row">
                  <div className="item-left">
                    <div className="order-date-icon">
                      <Calendar size={16} />
                    </div>
                    <div className="order-info-text">
                      <h5>{order.customer_name}</h5>
                      <span>{formatearFecha(order.created_at, tenant?.timezone)}</span>
                    </div>
                  </div>
                  <div className="item-right">
                    <strong className="order-price-label">{money(order.total)}</strong>
                    <span className={`status-badge-mini ${order.status}`}>
                      {order.status === 'attended' && 'Listo'}
                      {order.status === 'processing' && 'En proceso'}
                      {order.status === 'pending' && 'Pendiente'}
                      {order.status === 'cancelled' && 'Cancelado'}
                    </span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>

    </div>
  );
}
