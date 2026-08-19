import './OrdersPage.css';

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
  Plus,
  ShoppingBag,
  Calendar,
  ChevronLeft,
  ChevronRight,
  X
} from 'lucide-react';
import { getOrders, updateOrderStatus, deleteOrder } from '../../api/orders';
import NewOrderModal from '../../components/dashboard/NewOrderModal';
import type { Order, PaginatedResponse } from '../../types';
import { useTenantStore } from '../../stores/tenantStore';
import { formatMoney } from '../../utils/money';

export default function OrdersPage() {
  const queryClient = useQueryClient();
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
  const [isNewOrderOpen, setIsNewOrderOpen] = useState(false);

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
    // Desde 7.5 el telefono es opcional: una venta de mostrador puede no tenerlo.
    if (!order.customer_phone) return;
    const cleanPhone = order.customer_phone.replace(/[^0-9]/g, '');
    const textMessage = getWhatsappMessageForStatus(order, type);
    const msg = encodeURIComponent(textMessage);
    window.open(`https://wa.me/${cleanPhone}?text=${msg}`, '_blank');
  };

  return (
    <div className="orders-page animate-fade-in page-orders">
      <div className="page-header-actions">
        <p className="page-description">
          Administra las solicitudes de tu catálogo público y registra las ventas de mostrador.
        </p>
        <button type="button" className="btn-primary" onClick={() => setIsNewOrderOpen(true)}>
          <Plus size={16} /> Nueva venta
        </button>
      </div>

      {isNewOrderOpen && <NewOrderModal
        onClose={() => setIsNewOrderOpen(false)}
        currency={tenant?.currency}
        onCreated={(order) => {
          // Refrescamos la lista y el resumen: una venta atendida movió el stock.
          queryClient.invalidateQueries({ queryKey: ['orders'] });
          queryClient.invalidateQueries({ queryKey: ['dashboardStats'] });
          queryClient.invalidateQueries({ queryKey: ['products'] });
          setSelectedOrder(order);
        }}
      />}

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
                  <td className="phone-cell">{order.customer_phone || <span className="muted-cell">Mostrador</span>}</td>
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
                      <p style={{ margin: 0, marginRight: '0.5rem', fontWeight: 600 }}>
                        {selectedOrder.customer_phone || 'Venta de mostrador (sin teléfono)'}
                      </p>
                      {/* Sin numero no hay a quien escribir: los botones sobran. */}
                      {selectedOrder.customer_phone && (
                        <>
                          <button onClick={() => handleWhatsappContact(selectedOrder, 'status')} className="btn-whatsapp" title="Enviar notificación automática de acuerdo al estado actual">
                            <Phone size={13} /> Notificar Estado
                          </button>
                          <button onClick={() => handleWhatsappContact(selectedOrder, 'general')} className="btn-whatsapp-secondary" title="Enviar mensaje de contacto general">
                            Contacto General
                          </button>
                        </>
                      )}
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

    </div>
  );
}
