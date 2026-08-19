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
import { Link } from 'react-router-dom';
import { getDashboardStats } from '../../api/dashboard';
import type { DashboardStats } from '../../api/dashboard';
import { useTenantStore } from '../../stores/tenantStore';
import { formatMoney } from '../../utils/money';

export default function OverviewPage() {
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency);

  const { data: stats, isLoading, isError } = useQuery<DashboardStats>({
    queryKey: ['dashboardStats'],
    queryFn: getDashboardStats,
    refetchInterval: 30000, // Autorefresh every 30s
  });

  if (isLoading) {
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
                      {product.thumbnail_url ? (
                        <img src={product.thumbnail_url} alt={product.name} />
                      ) : (
                        <Package size={16} className="text-muted" />
                      )}
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
                      <span>{new Date(order.created_at).toLocaleDateString()}</span>
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
