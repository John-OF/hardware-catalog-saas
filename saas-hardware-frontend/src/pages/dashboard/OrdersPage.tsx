import './OrdersPage.css';

import { useState } from 'react';
import { Link } from 'react-router-dom';
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
import { usePuede } from '../../stores/authStore';
import { formatMoney } from '../../utils/money';
import Dialogo from '../../components/ui/Dialogo';
import { getPlantillasWhatsapp, textoDePlantilla } from '../../api/plantillasWhatsapp';
import {
  enlaceDeWhatsapp,
  plantillaDelEstado,
  PLANTILLAS_DE_PEDIDO,
  rellenarPlantilla,
  valoresDePedido,
  type PlantillaDePedido,
} from '../../utils/plantillasWhatsapp';

export default function OrdersPage() {
  const queryClient = useQueryClient();
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
  // MOD-21: cada acción con su permiso, `!== false` mientras getMe() no
  // responde (lo normal es ser admin; el backend manda igual).
  const puedeGestionar = usePuede('pedidos.gestionar') !== false;
  const puedeBorrar = usePuede('pedidos.borrar') !== false;
  const puedeExportar = usePuede('pedidos.exportar') !== false;
  const puedeVerCostos = usePuede('costos.ver') !== false;
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

  /**
   * El mensaje de WhatsApp del detalle (MOD-25), con las plantillas de la
   * tienda. Sale elegido el del estado del pedido; el dueño puede cambiarlo
   * por otro (el recordatorio de pago, el contacto general…) y ve el texto
   * antes de abrir WhatsApp.
   *
   * La elección va atada al pedido: al abrir otro, vuelve a salir la de su
   * estado sin tener que limpiar nada al cerrar.
   */
  const { data: plantillas, isError: errorEnPlantillas } = useQuery({
    queryKey: ['plantillas-whatsapp'],
    queryFn: getPlantillasWhatsapp,
    staleTime: 5 * 60 * 1000,
  });
  const [eleccion, setEleccion] = useState<{ pedido: string; clave: PlantillaDePedido } | null>(null);
  const puedeEditarMensajes = usePuede('tienda.configurar') !== false;

  const mensajeElegido: PlantillaDePedido | null = selectedOrder
    ? (eleccion?.pedido === selectedOrder.id ? eleccion.clave : plantillaDelEstado(selectedOrder.status))
    : null;

  const mensajeDelPedido = selectedOrder && mensajeElegido
    ? rellenarPlantilla(textoDePlantilla(plantillas, mensajeElegido), valoresDePedido(selectedOrder, tenant))
    : '';

  const etiquetaDeMensaje = (clave: PlantillaDePedido) =>
    plantillas?.plantillas.find((p) => p.clave === clave)?.etiqueta ?? clave;

  const escribirPorWhatsapp = () => {
    // Desde 7.5 el telefono es opcional: una venta de mostrador puede no tenerlo.
    if (!selectedOrder?.customer_phone || !mensajeDelPedido) return;
    window.open(enlaceDeWhatsapp(selectedOrder.customer_phone, mensajeDelPedido), '_blank');
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
          {puedeExportar && (
            <button type="button" className="btn-secondary" onClick={handleExport} disabled={exportando}>
              <Download size={16} /> {exportando ? 'Exportando…' : 'Exportar CSV'}
            </button>
          )}
          {puedeGestionar && (
            <button type="button" className="btn-primary" onClick={() => setIsNewOrderOpen(true)}>
              <Plus size={16} /> Nueva venta
            </button>
          )}
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
        <>
        <p className="aviso-scroll-x">Desliza la tabla hacia los lados para ver todas las columnas.</p>
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

                      {puedeGestionar && order.status === 'pending' && (
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

                      {puedeGestionar && order.status === 'processing' && (
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
                          borra la venta; no es atender, es su propio permiso. */}
                      {puedeBorrar && (
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
        </>
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
                    <p style={{ margin: 0, fontWeight: 600 }}>
                      {selectedOrder.customer_phone || 'Venta de mostrador (sin teléfono)'}
                    </p>
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

                {/* MOD-25. Sin número no hay a quién escribir: no sale. */}
                {selectedOrder.customer_phone && mensajeElegido && (
                  <div className="whatsapp-composer">
                    {/* En el orden en que se usa: elegir, leer y mandar. */}
                    <label htmlFor="mensaje-whatsapp">Mensaje de WhatsApp:</label>
                    <select
                      id="mensaje-whatsapp"
                      className="premium-input"
                      value={mensajeElegido}
                      onChange={(e) => setEleccion({ pedido: selectedOrder.id, clave: e.target.value as PlantillaDePedido })}
                    >
                      {PLANTILLAS_DE_PEDIDO.map((clave) => (
                        <option key={clave} value={clave}>
                          {etiquetaDeMensaje(clave)}
                          {clave === plantillaDelEstado(selectedOrder.status) ? ' (por su estado)' : ''}
                        </option>
                      ))}
                    </select>
                    <p className="whatsapp-preview">
                      {mensajeDelPedido || (errorEnPlantillas ? 'No se pudieron cargar los mensajes. Recarga la página.' : 'Cargando el mensaje…')}
                    </p>
                    <div className="whatsapp-composer-actions">
                      {puedeEditarMensajes
                        ? <Link to="/dashboard/whatsapp" className="whatsapp-composer-link">Cambiar estos mensajes</Link>
                        : <span />}
                      <button type="button" className="btn-whatsapp" onClick={escribirPorWhatsapp} disabled={!mensajeDelPedido}>
                        <Phone size={13} /> Escribir por WhatsApp
                      </button>
                    </div>
                  </div>
                )}
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

                {/* MOD-6: utilidad del pedido, solo con `costos.ver`. `utilidad`
                    a null es "ningún producto tenía costo", que NO es ganar cero:
                    en ese caso se dice, en vez de pintar un 0. */}
                {puedeVerCostos && selectedOrder.utilidad !== undefined && (
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
              {puedeGestionar && selectedOrder.status === 'pending' && (
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

              {puedeGestionar && selectedOrder.status === 'processing' && (
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
