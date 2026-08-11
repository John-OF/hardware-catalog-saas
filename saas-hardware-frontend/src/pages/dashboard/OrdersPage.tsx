import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
  Search,
  Eye,
  Check,
  XCircle,
  Trash2,
  Loader2,
  Phone,
  ShoppingBag,
  Calendar,
  ChevronLeft,
  ChevronRight,
  X
} from 'lucide-react';
import { getOrders, updateOrderStatus, deleteOrder } from '../../api/orders';
import type { Order, PaginatedResponse } from '../../types';
import { useTenantStore } from '../../stores/tenantStore';
import { formatMoney } from '../../utils/money';

export default function OrdersPage() {
  const queryClient = useQueryClient();
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number | string) => formatMoney(n, tenant?.currency);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);

  // Fetch orders
  const { data, isLoading } = useQuery<PaginatedResponse<Order>>({
    queryKey: ['orders', search, status, page],
    queryFn: () => getOrders({
      search: search || undefined,
      status: status || undefined,
      page,
      per_page: 10
    }),
  });

  const orders = data?.data || [];
  const pagination = data;

  // Mutation to update status
  const updateStatusMutation = useMutation({
    mutationFn: ({ id, status }: { id: string; status: 'attended' | 'cancelled' | 'pending' | 'processing' }) =>
      updateOrderStatus(id, status),
    onSuccess: (updatedOrder) => {
      queryClient.invalidateQueries({ queryKey: ['orders'] });
      toast.success('Estado del pedido actualizado');
      if (selectedOrder?.id === updatedOrder.id) {
        setSelectedOrder(updatedOrder);
      }
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al actualizar el pedido';
      toast.error(msg);
    }
  });

  // Mutation to delete order
  const deleteMutation = useMutation({
    mutationFn: deleteOrder,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['orders'] });
      toast.success('Pedido eliminado del historial');
      setSelectedOrder(null);
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al eliminar el pedido';
      toast.error(msg);
    }
  });

  const handleUpdateStatus = (id: string, newStatus: 'attended' | 'cancelled' | 'pending' | 'processing') => {
    updateStatusMutation.mutate({ id, status: newStatus });
  };

  const handleDelete = (id: string, customerName: string) => {
    if (window.confirm(`¿Seguro que deseas eliminar permanentemente el pedido de "${customerName}" del historial?`)) {
      deleteMutation.mutate(id);
    }
  };

  const getStatusBadgeClass = (statusVal: string) => {
    if (statusVal === 'attended') return 'badge-success';
    if (statusVal === 'processing') return 'badge-info';
    if (statusVal === 'cancelled') return 'badge-danger';
    return 'badge-warning';
  };

  const getStatusText = (statusVal: string) => {
    if (statusVal === 'attended') return 'Atendido / Listo';
    if (statusVal === 'processing') return 'En Proceso';
    if (statusVal === 'cancelled') return 'Cancelado';
    return 'Pendiente';
  };

  const getWhatsappMessageForStatus = (order: Order, type: 'status' | 'general' = 'status') => {
    const totalFormatted = money(order.total);
    const tenantName = tenant?.name || 'nuestra tienda';
    const itemsDescription = order.items && order.items.length > 0
      ? order.items.map(item => `${item.quantity}x ${item.product_name}`).join(', ')
      : 'productos';
    
    if (type === 'general') {
      return `Hola ${order.customer_name}, te contacto de la tienda ${tenantName} por tu pedido de: ${itemsDescription}.`;
    }

    switch (order.status) {
      case 'processing':
        return `Hola ${order.customer_name}, tu pedido de: ${itemsDescription} ya se encuentra en preparación en ${tenantName}. Te avisaremos apenas esté listo.`;
      case 'attended':
        return `¡Hola ${order.customer_name}! Tu pedido de: ${itemsDescription} por un total de ${totalFormatted} ya está listo en ${tenantName} para ser retirado o entregado. ¡Muchas gracias por tu compra!`;
      case 'cancelled':
        return `Hola ${order.customer_name}, tu pedido de: ${itemsDescription} ha sido cancelado en ${tenantName}. Si tienes alguna duda o consulta, por favor escríbenos por aquí.`;
      default:
        return `Hola ${order.customer_name}, hemos recibido tu pedido de: ${itemsDescription} en ${tenantName} por un total de ${totalFormatted}. Pronto iniciaremos su preparación.`;
    }
  };

  const handleWhatsappContact = (order: Order, type: 'status' | 'general' = 'status') => {
    const cleanPhone = order.customer_phone.replace(/[^0-9]/g, '');
    const textMessage = getWhatsappMessageForStatus(order, type);
    const msg = encodeURIComponent(textMessage);
    window.open(`https://wa.me/${cleanPhone}?text=${msg}`, '_blank');
  };

  return (
    <div className="orders-page animate-fade-in">
      <div className="page-header-actions">
        <p className="page-description">
          Administra las solicitudes de compras que los clientes realizan en tu catálogo público.
        </p>
      </div>

      {/* Filters Bar */}
      <div className="filters-bar glass-card">
        <div className="search-box">
          <Search size={18} className="search-icon" />
          <input
            type="text"
            className="premium-input search-input"
            placeholder="Buscar por cliente o teléfono..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </div>

        <select
          className="premium-input status-select"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
        >
          <option value="">Todos los estados</option>
          <option value="pending">Pendientes</option>
          <option value="processing">En Proceso</option>
          <option value="attended">Atendidos</option>
          <option value="cancelled">Cancelados</option>
        </select>
      </div>

      {/* Orders List / Table */}
      {isLoading ? (
        <div className="inner-loader">
          <Loader2 className="spinner" size={32} />
          <p>Cargando pedidos...</p>
        </div>
      ) : orders.length === 0 ? (
        <div className="empty-state glass-card">
          <ShoppingBag size={48} />
          <h3>No se encontraron pedidos</h3>
          <p>Los pedidos que realicen los clientes en tu catálogo virtual aparecerán en esta sección.</p>
        </div>
      ) : (
        <div className="table-container glass-card">
          <table className="orders-table">
            <thead>
              <tr>
                <th>Código</th>
                <th>Cliente</th>
                <th>Teléfono</th>
                <th>Fecha</th>
                <th>Artículos</th>
                <th>Total</th>
                <th>Estado</th>
                <th className="actions-header">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {orders.map((order) => (
                <tr key={order.id}>
                  <td className="code-cell">#{order.id.slice(-8)}</td>
                  <td className="name-cell">{order.customer_name}</td>
                  <td className="phone-cell">{order.customer_phone}</td>
                  <td className="date-cell">
                    <div className="date-info">
                      <Calendar size={14} />
                      <span>{new Date(order.created_at).toLocaleDateString()}</span>
                    </div>
                  </td>
                  <td>{order.items_count} u.</td>
                  <td className="total-cell">{money(order.total)}</td>
                  <td>
                    <span className={`badge ${getStatusBadgeClass(order.status)}`}>
                      {getStatusText(order.status)}
                    </span>
                  </td>
                  <td className="actions-cell">
                    <div className="action-buttons">
                      <button
                        onClick={() => setSelectedOrder(order)}
                        className="btn-icon"
                        title="Ver detalle"
                        aria-label="Ver detalle"
                      >
                        <Eye size={16} />
                      </button>

                      {order.status === 'pending' && (
                        <>
                          <button
                            onClick={() => handleUpdateStatus(order.id, 'processing')}
                            className="btn-icon process-btn"
                            title="Marcar en Proceso"
                            aria-label="Marcar en Proceso"
                          >
                            <Loader2 size={16} className="spinner-hover" />
                          </button>
                          <button
                            onClick={() => handleUpdateStatus(order.id, 'cancelled')}
                            className="btn-icon cancel-btn"
                            title="Marcar como Cancelado"
                            aria-label="Marcar como Cancelado"
                          >
                            <XCircle size={16} />
                          </button>
                        </>
                      )}

                      {order.status === 'processing' && (
                        <>
                          <button
                            onClick={() => handleUpdateStatus(order.id, 'attended')}
                            className="btn-icon check-btn"
                            title="Marcar como Atendido / Listo"
                            aria-label="Marcar como Atendido / Listo"
                          >
                            <Check size={16} />
                          </button>
                          <button
                            onClick={() => handleUpdateStatus(order.id, 'cancelled')}
                            className="btn-icon cancel-btn"
                            title="Marcar como Cancelado"
                            aria-label="Marcar como Cancelado"
                          >
                            <XCircle size={16} />
                          </button>
                        </>
                      )}

                      <button
                        onClick={() => handleDelete(order.id, order.customer_name)}
                        className="btn-icon delete-btn"
                        title="Eliminar registro"
                        aria-label="Eliminar registro"
                      >
                        <Trash2 size={16} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Pagination */}
          {pagination && pagination.last_page > 1 && (
            <div className="pagination-bar">
              <button
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(p - 1, 1))}
                className="btn-secondary pag-btn"
              >
                <ChevronLeft size={16} /> Anterior
              </button>
              <span className="pag-info">
                Página {page} de {pagination.last_page}
              </span>
              <button
                disabled={page >= pagination.last_page}
                onClick={() => setPage((p) => Math.min(p + 1, pagination.last_page))}
                className="btn-secondary pag-btn"
              >
                Siguiente <ChevronRight size={16} />
              </button>
            </div>
          )}
        </div>
      )}

      {/* Detail Modal / Drawer */}
      {selectedOrder && (
        <div className="modal-overlay" onClick={() => setSelectedOrder(null)}>
          <div className="modal-content glass-card animate-scale-in" onClick={(e) => e.stopPropagation()}>
            <header className="modal-header">
              <div>
                <h3>Detalle del Pedido #{selectedOrder.id.slice(-8)}</h3>
                <span className={`badge ${getStatusBadgeClass(selectedOrder.status)}`}>
                  {getStatusText(selectedOrder.status)}
                </span>
              </div>
              <button className="close-btn" onClick={() => setSelectedOrder(null)} aria-label="Cerrar modal">
                <X size={20} />
              </button>
            </header>

            <div className="modal-body">
              {/* Customer summary */}
              <div className="customer-summary-card">
                <h4>Información de Contacto</h4>
                <div className="info-grid">
                  <div>
                    <label>Nombre:</label>
                    <p>{selectedOrder.customer_name}</p>
                  </div>
                  <div>
                    <label>Teléfono:</label>
                    <div className="phone-row" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                      <p style={{ margin: 0, marginRight: '0.5rem', fontWeight: 600 }}>{selectedOrder.customer_phone}</p>
                      <button onClick={() => handleWhatsappContact(selectedOrder, 'status')} className="btn-whatsapp" title="Enviar notificación automática de acuerdo al estado actual">
                        <Phone size={13} /> Notificar Estado
                      </button>
                      <button onClick={() => handleWhatsappContact(selectedOrder, 'general')} className="btn-whatsapp-secondary" title="Enviar mensaje de contacto general">
                        Contacto General
                      </button>
                    </div>
                  </div>
                  <div>
                    <label>Fecha de Pedido:</label>
                    <p>{new Date(selectedOrder.created_at).toLocaleString()}</p>
                  </div>
                  {selectedOrder.customer_note && (
                    <div className="full-width">
                      <label>Nota del cliente:</label>
                      <p className="note-text">{selectedOrder.customer_note}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Items summary */}
              <div className="items-summary-card">
                <h4>Detalle de Productos</h4>
                <div className="detail-items-list">
                  {selectedOrder.items?.map((item) => (
                    <div className="detail-item-row" key={item.id}>
                      <div className="item-name-col">
                        <span className="item-qty">{item.quantity}x</span>
                        <span className="item-name">{item.product_name}</span>
                      </div>
                      <div className="item-price-col">
                        <span>{money(item.unit_price)}</span>
                        <strong>{money(item.subtotal)}</strong>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="detail-total-row">
                  <span>Total del Pedido</span>
                  <strong>{money(selectedOrder.total)}</strong>
                </div>
              </div>

              {/* State updates inside details */}
              {selectedOrder.status === 'pending' && (
                <div className="modal-actions-bar" style={{ display: 'flex', gap: '1rem', marginTop: '2rem', borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }}>
                  <button
                    onClick={() => handleUpdateStatus(selectedOrder.id, 'processing')}
                    className="btn-primary"
                    style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.5rem' }}
                  >
                    <Loader2 size={16} className="spinner-hover" /> Preparar Pedido
                  </button>
                  <button
                    onClick={() => handleUpdateStatus(selectedOrder.id, 'cancelled')}
                    className="btn-secondary"
                    style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.5rem' }}
                  >
                    <XCircle size={16} /> Cancelar Pedido
                  </button>
                </div>
              )}

              {selectedOrder.status === 'processing' && (
                <div className="modal-actions-bar" style={{ display: 'flex', gap: '1rem', marginTop: '2rem', borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }}>
                  <button
                    onClick={() => handleUpdateStatus(selectedOrder.id, 'attended')}
                    className="btn-primary"
                    style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.5rem' }}
                  >
                    <Check size={16} /> Completar / Listo
                  </button>
                  <button
                    onClick={() => handleUpdateStatus(selectedOrder.id, 'cancelled')}
                    className="btn-secondary"
                    style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.5rem' }}
                  >
                    <XCircle size={16} /> Cancelar Pedido
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      <style>{`
        .orders-page {
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
          padding-bottom: 2rem;
        }

        .page-header-actions {
          margin-top: -0.5rem;
        }

        .page-description {
          color: var(--text-secondary);
          font-size: 0.95rem;
        }

        .filters-bar {
          display: flex;
          gap: 1rem;
          padding: 1.25rem;
          align-items: center;
        }

        .search-box {
          position: relative;
          flex: 1;
        }

        .search-icon {
          position: absolute;
          left: 0.95rem;
          top: 50%;
          transform: translateY(-50%);
          color: var(--text-muted);
        }

        .search-input {
          padding-left: 2.5rem;
        }

        .status-select {
          width: 220px;
          cursor: pointer;
        }

        .inner-loader {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          padding: 5rem 0;
          color: var(--text-muted);
          gap: 0.75rem;
        }

        .spinner {
          animation: spin 1s linear infinite;
          color: var(--primary);
        }

        @keyframes spin {
          to { transform: rotate(360deg); }
        }

        .empty-state {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          padding: 5rem 2rem;
          text-align: center;
          color: var(--text-muted);
          gap: 0.5rem;
        }

        .empty-state h3 {
          color: var(--text-primary);
          margin-top: 0.5rem;
          font-size: 1.2rem;
        }

        .empty-state p {
          max-width: 400px;
          font-size: 0.9rem;
        }

        .table-container {
          overflow-x: auto;
          border: 1px solid var(--border);
          padding: 0.25rem;
        }

        .orders-table {
          width: 100%;
          border-collapse: collapse;
          text-align: left;
          font-size: 0.9rem;
        }

        .orders-table th, .orders-table td {
          padding: 1rem 1.25rem;
          border-bottom: 1px solid var(--border);
        }

        .orders-table th {
          font-weight: 600;
          color: var(--text-secondary);
          text-transform: uppercase;
          font-size: 0.75rem;
          letter-spacing: 0.05em;
        }

        .orders-table tr:last-child td {
          border-bottom: none;
        }

        .orders-table tbody tr:hover {
          background: rgba(var(--overlay-mix), 0.015);
        }

        .code-cell {
          font-family: monospace;
          font-weight: 700;
          color: var(--text-primary);
        }

        .name-cell {
          font-weight: 500;
          color: var(--text-primary);
        }

        .total-cell {
          font-weight: 700;
          color: var(--text-primary);
        }

        .date-info {
          display: flex;
          align-items: center;
          gap: 0.4rem;
          color: var(--text-secondary);
        }

        .actions-header {
          text-align: right;
        }

        .actions-cell {
          text-align: right;
        }

        .action-buttons {
          display: inline-flex;
          gap: 0.35rem;
        }

        .btn-icon {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          width: 32px;
          height: 32px;
          border-radius: var(--radius-sm);
          border: 1px solid var(--border);
          background: transparent;
          color: var(--text-secondary);
          cursor: pointer;
          transition: var(--transition);
        }

        .btn-icon:hover {
          border-color: var(--primary);
          color: var(--primary);
          background: var(--primary-glow);
        }

        .check-btn:hover {
          border-color: var(--success);
          color: var(--success);
          background: var(--success-glow);
        }

        .cancel-btn:hover {
          border-color: var(--danger);
          color: var(--danger);
          background: var(--danger-glow);
        }

        .delete-btn:hover {
          border-color: #f43f5e;
          color: #f43f5e;
          background: rgba(244, 63, 94, 0.1);
        }

        .badge-warning {
          background: rgba(245, 158, 11, 0.15);
          color: var(--warning);
          border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .pagination-bar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          padding: 1rem 1.25rem;
          border-top: 1px solid var(--border);
        }

        .pag-info {
          font-size: 0.85rem;
          color: var(--text-secondary);
        }

        .pag-btn {
          padding: 0.5rem 1rem;
          font-size: 0.85rem;
        }

        /* Modal styling */
        .modal-overlay {
          position: fixed;
          inset: 0;
          background: rgba(0, 0, 0, 0.5);
          z-index: 150;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 1.5rem;
          backdrop-filter: blur(4px);
        }

        .modal-content {
          width: 100%;
          max-width: 600px;
          background: var(--glass-bg);
          border: 1px solid var(--glass-border);
          box-shadow: var(--shadow-xl);
          border-radius: var(--radius-lg);
          display: flex;
          flex-direction: column;
          max-height: 90vh;
        }

        .modal-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 1.25rem 1.5rem;
          border-bottom: 1px solid var(--border);
        }

        .modal-header h3 {
          font-size: 1.15rem;
          color: var(--text-primary);
          margin-bottom: 0.25rem;
        }

        .close-btn {
          background: transparent;
          border: none;
          color: var(--text-secondary);
          cursor: pointer;
          display: flex;
        }

        .modal-body {
          padding: 1.5rem;
          overflow-y: auto;
          display: flex;
          flex-direction: column;
          gap: 1.25rem;
        }

        .customer-summary-card, .items-summary-card {
          background: rgba(var(--overlay-mix), 0.015);
          border: 1px solid var(--border);
          border-radius: var(--radius-md);
          padding: 1.25rem;
        }

        .customer-summary-card h4, .items-summary-card h4 {
          font-size: 0.95rem;
          color: var(--text-primary);
          margin-bottom: 1rem;
          font-family: var(--font-heading);
        }

        .info-grid {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 1rem;
        }

        .info-grid label {
          display: block;
          font-size: 0.75rem;
          color: var(--text-muted);
          text-transform: uppercase;
          margin-bottom: 0.2rem;
        }

        .info-grid p {
          color: var(--text-primary);
          font-weight: 500;
          font-size: 0.9rem;
        }

        .full-width {
          grid-column: span 2;
        }

        .phone-row {
          display: flex;
          align-items: center;
          gap: 0.75rem;
        }

        .btn-whatsapp {
          display: inline-flex;
          align-items: center;
          gap: 0.25rem;
          padding: 0.2rem 0.5rem;
          background: var(--success-glow);
          border: 1px solid rgba(16, 185, 129, 0.3);
          color: var(--success);
          font-size: 0.75rem;
          border-radius: 4px;
          cursor: pointer;
          font-weight: 600;
          transition: var(--transition);
        }

        .btn-whatsapp:hover {
          background: var(--success);
          color: #fff;
        }

        .note-text {
          font-style: italic;
          background: rgba(var(--overlay-mix), 0.02);
          padding: 0.5rem 0.75rem;
          border-radius: var(--radius-sm);
          border-left: 2px solid var(--primary);
        }

        .detail-items-list {
          display: flex;
          flex-direction: column;
          gap: 0.75rem;
          max-height: 200px;
          overflow-y: auto;
          padding-right: 0.5rem;
        }

        .detail-item-row {
          display: flex;
          justify-content: space-between;
          align-items: center;
          font-size: 0.9rem;
          padding-bottom: 0.5rem;
          border-bottom: 1px dashed var(--border);
        }

        .detail-item-row:last-child {
          border-bottom: none;
        }

        .item-name-col {
          display: flex;
          align-items: center;
          gap: 0.65rem;
        }

        .item-qty {
          font-weight: 700;
          color: var(--primary);
          font-family: monospace;
          background: var(--primary-glow);
          padding: 0.1rem 0.4rem;
          border-radius: 4px;
          font-size: 0.8rem;
        }

        .item-name {
          color: var(--text-primary);
        }

        .item-price-col {
          display: flex;
          flex-direction: column;
          align-items: flex-end;
        }

        .item-price-col span {
          font-size: 0.75rem;
          color: var(--text-secondary);
        }

        .item-price-col strong {
          color: var(--text-primary);
          font-size: 0.85rem;
        }

        .detail-total-row {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-top: 1rem;
          padding-top: 1rem;
          border-top: 1px solid var(--border);
        }

        .detail-total-row span {
          font-size: 0.9rem;
          color: var(--text-secondary);
        }

        .detail-total-row strong {
          font-size: 1.35rem;
          font-weight: 800;
          color: var(--primary);
        }

        .modal-actions-bar {
          display: flex;
          gap: 0.75rem;
          margin-top: 0.5rem;
        }

        .modal-actions-bar button {
          flex: 1;
        }

        .badge-info {
          background: rgba(14, 165, 233, 0.15);
          color: #0ea5e9;
          border: 1px solid rgba(14, 165, 233, 0.2);
        }

        .process-btn:hover {
          border-color: #0ea5e9;
          color: #0ea5e9;
          background: rgba(14, 165, 233, 0.1);
        }

        .btn-whatsapp-secondary {
          display: inline-flex;
          align-items: center;
          gap: 0.25rem;
          padding: 0.3rem 0.6rem;
          background: rgba(var(--overlay-mix), 0.03);
          border: 1px solid var(--border);
          color: var(--text-secondary);
          font-size: 0.75rem;
          border-radius: 4px;
          cursor: pointer;
          font-weight: 600;
          transition: var(--transition);
        }

        .btn-whatsapp-secondary:hover {
          background: rgba(var(--overlay-mix), 0.06);
          color: var(--text-primary);
        }

        .spinner-hover {
          transition: transform 0.8s ease;
        }
        .process-btn:hover .spinner-hover {
          transform: rotate(360deg);
        }
      `}</style>
    </div>
  );
}
