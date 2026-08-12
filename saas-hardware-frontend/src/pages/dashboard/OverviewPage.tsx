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
      <div className="overview-loading">
        <Loader2 className="spinner" size={32} />
        <p>Cargando estadísticas...</p>
        <style>{`
          .overview-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 400px;
            color: var(--text-secondary);
            gap: 1rem;
          }
          .spinner {
            animation: spin 1s linear infinite;
            color: var(--primary);
          }
          @keyframes spin {
            to { transform: rotate(360deg); }
          }
        `}</style>
      </div>
    );
  }

  if (isError || !stats) {
    return (
      <div className="overview-error glass-card">
        <h3>Error al cargar métricas</h3>
        <p>No se pudieron calcular las estadísticas de la tienda. Por favor, intenta de nuevo más tarde.</p>
      </div>
    );
  }

  // Helper values
  const pendingCount = stats.orders_by_status.pending;
  const processingCount = stats.orders_by_status.processing;

  return (
    <div className="overview-page animate-fade-in">
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

      <style>{`
        .overview-page {
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
          padding-bottom: 2rem;
        }

        .page-description {
          color: var(--text-secondary);
          font-size: 0.95rem;
          margin-top: -0.5rem;
        }

        .kpi-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
          gap: 1.25rem;
        }

        .kpi-card {
          display: flex;
          align-items: center;
          padding: 1.5rem;
          gap: 1.25rem;
          border: 1px solid var(--border);
        }

        .kpi-icon-wrapper {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 48px;
          height: 48px;
          border-radius: var(--radius-md);
          background: var(--primary-glow);
          color: var(--primary);
        }

        .sales-icon {
          background: rgba(16, 185, 129, 0.08);
          color: #10b981;
        }
        .orders-icon {
          background: rgba(245, 158, 11, 0.08);
          color: #f59e0b;
        }
        .products-icon {
          background: rgba(99, 102, 241, 0.08);
          color: #6366f1;
        }
        .views-icon {
          background: rgba(14, 165, 233, 0.08);
          color: #0ea5e9;
        }

        .kpi-data {
          display: flex;
          flex-direction: column;
          gap: 0.2rem;
        }

        .kpi-label {
          font-size: 0.8rem;
          font-weight: 500;
          color: var(--text-muted);
          text-transform: uppercase;
          letter-spacing: 0.05em;
        }

        .kpi-value {
          font-size: 1.6rem;
          font-weight: 800;
          color: var(--text-primary);
          margin: 0;
          line-height: 1.2;
        }

        .kpi-trend {
          display: inline-flex;
          align-items: center;
          gap: 0.25rem;
          font-size: 0.75rem;
          font-weight: 600;
          margin-top: 0.25rem;
        }

        .kpi-trend.positive {
          color: #10b981;
        }
        .kpi-trend.warning {
          color: #f59e0b;
        }
        .kpi-trend.info {
          color: #6366f1;
        }

        .overview-split {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
          gap: 1.5rem;
        }

        @media (max-width: 768px) {
          .overview-split {
            grid-template-columns: 1fr;
          }
        }

        .split-column {
          display: flex;
          flex-direction: column;
          padding: 1.5rem;
          border: 1px solid var(--border);
        }

        .column-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 1.25rem;
        }

        .column-header h4 {
          font-size: 1.05rem;
          font-weight: 700;
          color: var(--text-primary);
          margin: 0;
        }

        .view-all-link {
          font-size: 0.8rem;
          color: var(--primary);
          text-decoration: none;
          display: flex;
          align-items: center;
          gap: 0.25rem;
          font-weight: 500;
          transition: var(--transition);
        }

        .view-all-link:hover {
          color: var(--primary-light);
          gap: 0.4rem;
        }

        .list-container {
          display: flex;
          flex-direction: column;
          gap: 1rem;
        }

        .empty-text {
          font-size: 0.85rem;
          color: var(--text-muted);
          text-align: center;
          padding: 2rem 0;
          margin: 0;
        }

        .list-item-row {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding-bottom: 0.75rem;
          border-bottom: 1px solid var(--border);
        }

        .list-item-row:last-child {
          border-bottom: none;
          padding-bottom: 0;
        }

        .item-left {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          overflow: hidden;
        }

        .product-thumb {
          width: 40px;
          height: 40px;
          border-radius: var(--radius-sm);
          overflow: hidden;
          background: rgba(var(--overlay-mix), 0.03);
          display: flex;
          align-items: center;
          justify-content: center;
          flex-shrink: 0;
          border: 1px solid var(--border);
        }

        .product-thumb img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .product-info-text {
          overflow: hidden;
        }

        .product-info-text h5 {
          margin: 0;
          font-size: 0.9rem;
          font-weight: 600;
          color: var(--text-primary);
          white-space: nowrap;
          text-overflow: ellipsis;
          overflow: hidden;
        }

        .product-info-text span {
          font-size: 0.75rem;
          color: var(--text-secondary);
        }

        .order-date-icon {
          width: 40px;
          height: 40px;
          border-radius: var(--radius-sm);
          background: rgba(var(--overlay-mix), 0.03);
          border: 1px solid var(--border);
          display: flex;
          align-items: center;
          justify-content: center;
          color: var(--text-secondary);
          flex-shrink: 0;
        }

        .order-info-text h5 {
          margin: 0;
          font-size: 0.9rem;
          font-weight: 600;
          color: var(--text-primary);
        }

        .order-info-text span {
          font-size: 0.75rem;
          color: var(--text-secondary);
        }

        .item-right {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          flex-shrink: 0;
        }

        .views-pill {
          display: inline-flex;
          align-items: center;
          gap: 0.25rem;
          font-size: 0.75rem;
          background: rgba(14, 165, 233, 0.08);
          color: #0ea5e9;
          padding: 0.15rem 0.5rem;
          border-radius: 20px;
          font-weight: 600;
        }

        .stock-pill {
          font-size: 0.75rem;
          padding: 0.15rem 0.5rem;
          border-radius: 4px;
          font-weight: 500;
        }

        .stock-pill.in-stock {
          background: rgba(16, 185, 129, 0.08);
          color: #10b981;
        }

        .stock-pill.out-of-stock {
          background: rgba(244, 63, 94, 0.08);
          color: #f43f5e;
        }

        .order-price-label {
          font-size: 0.9rem;
          font-weight: 700;
          color: var(--text-primary);
        }

        .status-badge-mini {
          font-size: 0.7rem;
          padding: 0.1rem 0.4rem;
          border-radius: 4px;
          font-weight: 600;
          text-transform: uppercase;
          letter-spacing: 0.05em;
        }

        .status-badge-mini.pending {
          background: rgba(245, 158, 11, 0.15);
          color: var(--warning);
        }

        .status-badge-mini.processing {
          background: rgba(14, 165, 233, 0.15);
          color: #0ea5e9;
        }

        .status-badge-mini.attended {
          background: rgba(16, 185, 129, 0.15);
          color: var(--success);
        }

        .status-badge-mini.cancelled {
          background: rgba(244, 63, 94, 0.15);
          color: var(--danger);
        }
      `}</style>
    </div>
  );
}
