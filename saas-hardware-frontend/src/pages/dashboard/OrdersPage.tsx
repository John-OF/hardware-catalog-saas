import './OrdersPage.css';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { avisarErrorEnSesion } from '../../api/erroresDeFormulario';
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
  Download,
  // Cotizacion en PDF (MOD-2). `Download` ya es el CSV del listado.
  FileDown
} from 'lucide-react';
import { getOrders, updateOrderStatus, deleteOrder } from '../../api/orders';
import { descargarCotizacion, exportarPedidos } from '../../api/exportaciones';
import NewOrderModal from '../../components/dashboard/NewOrderModal';
import type { Order, PaginatedResponse } from '../../types';
import { useTenantStore } from '../../stores/tenantStore';
import { formatearFecha, formatearFechaHora } from '../../utils/fechas';
import { useEsAdmin } from '../../stores/authStore';
import { formatMoney } from '../../utils/money';
import Dialogo from '../../components/ui/Dialogo';
import { nombreConVariante } from '../../utils/variants';

export default function OrdersPage() {
  const queryClient = useQueryClient();
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
  const puedeAdministrar = useEsAdmin() !== false;
  const [exportando, setExportando] = useState(false);

  const handleExport = async () => {
    setExportando(true);

    try {
      await exportarPedidos({ status: status || undefined });
      toast.success('Pedidos exportados.');
    } catch {
      toast.error('No se pudieron exportar los pedidos.');
    } finally {
      setExportando(false);
    }
  };

  // MOD-2: la cotización en PDF de un pedido suelto. Mismo camino que el CSV
  // —por axios y no por <a href>— porque la ruta va detrás del token y del
  // header de tienda.
  const [descargandoPdf, setDescargandoPdf] = useState(false);

  const cotizar = async (pedidoId: string) => {
    setDescargandoPdf(true);

    try {
      await descargarCotizacion(pedidoId);
    } catch {
      toast.error('No se pudo generar la cotización.');
    } finally {
      setDescargandoPdf(false);
    }
  };

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
      avisarErrorEnSesion(err, 'Error al actualizar el pedido');
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
      avisarErrorEnSesion(err, 'Error al eliminar el pedido');
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
      ? order.items.map(item => `${item.quantity}x ${nombreConVariante(item.product_name, item.variant_name)}`).join(', ')
      : 'productos';
    
    // FUN-3: el número va en todos los mensajes porque es el que el cliente ya
    // tiene —se lo llevó en el mensaje de WhatsApp al pedir— y el que el dueño
    // puede buscar en esta misma pantalla cuando el cliente lo repita.
    const ref = `#${order.number}`;

    if (type === 'general') {
      return `Hola ${order.customer_name}, te contacto de la tienda ${tenantName} por tu pedido ${ref} (${itemsDescription}).`;
    }

    switch (order.status) {
      case 'processing':
        return `Hola ${order.customer_name}, tu pedido ${ref} (${itemsDescription}) ya se encuentra en preparación en ${tenantName}. Te avisaremos apenas esté listo.`;
      case 'attended':
        return `¡Hola ${order.customer_name}! Tu pedido ${ref} (${itemsDescription}) por un total de ${totalFormatted} ya está listo en ${tenantName} para ser retirado o entregado. ¡Muchas gracias por tu compra!`;
      case 'cancelled':
        return `Hola ${order.customer_name}, tu pedido ${ref} (${itemsDescription}) ha sido cancelado en ${tenantName}. Si tienes alguna duda o consulta, por favor escríbenos por aquí.`;
      default:
        return `Hola ${order.customer_name}, hemos recibido tu pedido ${ref} (${itemsDescription}) en ${tenantName} por un total de ${totalFormatted}. Pronto iniciaremos su preparación.`;
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
        <div className="page-header-buttons">
          {/* MOD-7: con el filtro de estado puesto, que es como se exporta para
              cuadrar cuentas ("los atendidos de este mes"). */}
          {puedeAdministrar && (
            <button type="button" className="btn-secondary" onClick={handleExport} disabled={exportando}>
              <Download size={16} /> {exportando ? 'Exportando…' : 'Exportar CSV'}
            </button>
          )}
          <button type="button" className="btn-primary" onClick={() => setIsNewOrderOpen(true)}>
            <Plus size={16} /> Nueva venta
          </button>
        </div>
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
            placeholder="Buscar por número, cliente o teléfono..."
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
                  <td className="code-cell">#{order.number}</td>
                  <td className="name-cell">{order.customer_name}</td>
                  <td className="phone-cell">{order.customer_phone || <span className="muted-cell">Mostrador</span>}</td>
                  <td className="date-cell">
                    <div className="date-info">
                      <Calendar size={14} />
                      <span>{formatearFecha(order.created_at, tenant?.timezone)}</span>
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

                      {/* FUN-4: borrar un pedido atendido devuelve su stock y
                          borra la venta; es de admin, no de quien atiende. */}
                      {puedeAdministrar && (
                        <button
                          onClick={() => handleDelete(order.id, order.customer_name)}
                          className="btn-icon delete-btn"
                          title="Eliminar registro"
                          aria-label="Eliminar registro"
                        >
                          <Trash2 size={16} />
                        </button>
                      )}
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
        <Dialogo
          titulo={`Detalle del Pedido #${selectedOrder.number}`}
          subtitulo={
            <span className={`badge ${getStatusBadgeClass(selectedOrder.status)}`}>
              {getStatusText(selectedOrder.status)}
            </span>
          }
          onCerrar={() => setSelectedOrder(null)}
          ancho={600}
          className="page-orders"
        >
            <div className="dialogo-cuerpo">
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
                    <label>Correo:</label>
                    {/*
                      FUN-2: se dice tambien cuando NO hay, porque cambia lo que
                      pasa al mover el estado: con correo el aviso sale solo, sin
                      correo hay que escribir por WhatsApp.
                    */}
                    <p>
                      {selectedOrder.customer_email || <span className="muted-cell">Sin correo (no recibe avisos)</span>}
                    </p>
                  </div>
                  <div>
                    <label>Fecha de Pedido:</label>
                    <p>{formatearFechaHora(selectedOrder.created_at, tenant?.timezone)}</p>
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
                        <span className="item-name">
                          {item.product_name}
                          {item.variant_name && <span className="item-variant"> · {item.variant_name}</span>}
                        </span>
                      </div>
                      <div className="item-price-col">
                        <span>{money(item.unit_price)}</span>
                        <strong>{money(item.subtotal)}</strong>
                      </div>
                    </div>
                  ))}
                </div>

                {/* MOD-4: solo si ESE pedido llevó cupón. El código va escrito
                    porque es lo que el dueño necesita para saber qué campaña se
                    lo llevó. */}
                {selectedOrder.discount_amount != null && (
                  <div className="detail-total-row detail-discount-row">
                    <span>Descuento{selectedOrder.coupon_code ? ` (${selectedOrder.coupon_code})` : ''}</span>
                    <span>-{money(selectedOrder.discount_amount)}</span>
                  </div>
                )}

                {/* MOD-1: null en venta de mostrador y en pedidos de antes de
                    este cambio, así que no se enseña nada en esos casos. */}
                {selectedOrder.delivery_method && (
                  <div className="detail-total-row detail-delivery-row">
                    <span>Entrega</span>
                    <span>
                      {selectedOrder.delivery_method === 'delivery'
                        ? `Delivery (${money(selectedOrder.delivery_cost)})`
                        : 'Recojo en tienda'}
                    </span>
                  </div>
                )}

                {/* MOD-2: el desglose solo aparece si ESTA venta llevó impuesto.
                    Un pedido de antes, o de cuando la tienda no lo cobraba, se
                    pinta como siempre: inventar una línea "IGV 0,00" diría que se
                    cobró un impuesto del cero por ciento. */}
                {selectedOrder.tax_amount != null && (
                  <>
                    <div className="detail-total-row detail-tax-row">
                      <span>Op. gravada</span>
                      <span>{money(selectedOrder.base_imponible ?? 0)}</span>
                    </div>
                    <div className="detail-total-row detail-tax-row">
                      <span>
                        {selectedOrder.tax_name} ({Number(selectedOrder.tax_rate)}%)
                      </span>
                      <span>{money(selectedOrder.tax_amount)}</span>
                    </div>
                  </>
                )}

                <div className="detail-total-row">
                  <span>Total del Pedido</span>
                  <strong>{money(selectedOrder.total)}</strong>
                </div>

                {/* MOD-6: utilidad del pedido, solo para admin. `utilidad` a null
                    es "ningún producto tenía costo", que NO es ganar cero: en ese
                    caso se dice, en vez de pintar un 0. */}
                {puedeAdministrar && selectedOrder.utilidad !== undefined && (
                  <div className="detail-total-row detail-profit-row">
                    <span>Utilidad</span>
                    {selectedOrder.utilidad === null ? (
                      <span className="muted-cell">Sin costos registrados</span>
                    ) : (
                      <span>
                        <strong>{money(selectedOrder.utilidad)}</strong>
                        <small>
                          {' '}(costo {money(selectedOrder.costo_total)}
                          {(selectedOrder.lineas_sin_costo ?? 0) > 0
                            ? `, ${selectedOrder.lineas_sin_costo} producto(s) sin costo`
                            : ''}
                          )
                        </small>
                      </span>
                    )}
                  </div>
                )}
              </div>

              {/* MOD-2: siempre, en cualquier estado. Un pedido pendiente es
                  justo el que se cotiza, y uno atendido, el comprobante que el
                  cliente pide después. */}
              <div className="dialogo-acciones">
                <button
                  type="button"
                  onClick={() => cotizar(selectedOrder.id)}
                  className="btn-secondary"
                  disabled={descargandoPdf}
                >
                  <FileDown size={16} /> {descargandoPdf ? 'Generando…' : 'Descargar cotización (PDF)'}
                </button>
              </div>

              {/* State updates inside details */}
              {selectedOrder.status === 'pending' && (
                <div className="dialogo-acciones">
                  <button
                    onClick={() => handleUpdateStatus(selectedOrder.id, 'processing')}
                    className="btn-primary"
                  >
                    <Loader2 size={16} className="spinner-hover" /> Preparar Pedido
                  </button>
                  <button
                    onClick={() => handleUpdateStatus(selectedOrder.id, 'cancelled')}
                    className="btn-secondary"
                  >
                    <XCircle size={16} /> Cancelar Pedido
                  </button>
                </div>
              )}

              {selectedOrder.status === 'processing' && (
                <div className="dialogo-acciones">
                  <button
                    onClick={() => handleUpdateStatus(selectedOrder.id, 'attended')}
                    className="btn-primary"
                  >
                    <Check size={16} /> Completar / Listo
                  </button>
                  <button
                    onClick={() => handleUpdateStatus(selectedOrder.id, 'cancelled')}
                    className="btn-secondary"
                  >
                    <XCircle size={16} /> Cancelar Pedido
                  </button>
                </div>
              )}
            </div>
        </Dialogo>
      )}

    </div>
  );
}
