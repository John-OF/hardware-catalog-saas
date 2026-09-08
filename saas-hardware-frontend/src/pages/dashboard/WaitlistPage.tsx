import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
  Bell,
  Check,
  Undo2,
  Trash2,
  Loader2,
  Phone,
  Mail,
  Package,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import {
  getStockNotifications,
  setStockNotificationNotified,
  deleteStockNotification,
} from '../../api/stockNotifications';
import type { StockNotification, PaginatedResponse } from '../../types';
import './WaitlistPage.css';

/**
 * Quién espera que vuelva el stock (FUN-1b).
 *
 * El formulario público existía desde 5.4 y guardaba filas que no veía nadie: la
 * lista de productos solo enseñaba un contador de cuántos esperaban, sin decir
 * quiénes ni dar forma de escribirles.
 *
 * Al reponer stock, a quien dejó un CORREO le llega el aviso solo. A quien dejó un
 * TELÉFONO no —y por eso sigue pendiente aquí, con su botón de WhatsApp: en este
 * rubro el dueño va a escribir por ahí de todas formas.
 */
export default function WaitlistPage() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const [page, setPage] = useState(1);

  // El filtro por producto llega desde la insignia "N en espera" de Productos.
  const productId = searchParams.get('product') || undefined;
  const status = (searchParams.get('status') as 'pending' | 'notified' | null) || '';

  const { data, isLoading } = useQuery<PaginatedResponse<StockNotification>>({
    queryKey: ['waitlist', status, productId, page],
    queryFn: () => getStockNotifications({
      status: status || undefined,
      product_id: productId,
      page,
      per_page: 15,
    }),
  });

  const esperas = data?.data || [];
  const pagination = data;

  const marcarMutation = useMutation({
    mutationFn: ({ id, notified }: { id: string; notified: boolean }) =>
      setStockNotificationNotified(id, notified),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['waitlist'] });
      // Cambia la insignia "N en espera" de la lista de productos.
      queryClient.invalidateQueries({ queryKey: ['products'] });
      toast.success(variables.notified ? 'Marcado como avisado' : 'Vuelve a estar pendiente');
    },
    onError: (err: any) => {
      toast.error(err.response?.data?.message || 'No se pudo actualizar el aviso');
    },
  });

  const borrarMutation = useMutation({
    mutationFn: deleteStockNotification,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['waitlist'] });
      queryClient.invalidateQueries({ queryKey: ['products'] });
      toast.success('Espera eliminada');
    },
    onError: (err: any) => {
      toast.error(err.response?.data?.message || 'No se pudo eliminar la espera');
    },
  });

  const cambiarFiltro = (clave: 'status' | 'product', valor: string) => {
    const siguiente = new URLSearchParams(searchParams);
    if (valor) {
      siguiente.set(clave, valor);
    } else {
      siguiente.delete(clave);
    }
    setSearchParams(siguiente);
    setPage(1);
  };

  const esCorreo = (contacto: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contacto.trim());

  const escribirPorWhatsapp = (espera: StockNotification) => {
    const telefono = espera.customer_contact.replace(/[^0-9]/g, '');
    const producto = espera.product?.name ?? 'el producto que esperabas';
    const mensaje = encodeURIComponent(
      `Hola ${espera.customer_name}, te escribimos porque ${producto} volvio a estar disponible. ` +
      `Avisanos si todavia lo quieres y te lo separamos.`
    );
    window.open(`https://wa.me/${telefono}?text=${mensaje}`, '_blank');
  };

  const handleBorrar = (espera: StockNotification) => {
    if (window.confirm(`¿Eliminar la espera de "${espera.customer_name}"?`)) {
      borrarMutation.mutate(espera.id);
    }
  };

  return (
    <div className="waitlist-page animate-fade-in page-waitlist">
      <div className="page-header">
        <div>
          <h1>Lista de espera</h1>
          <p className="page-description">
            Clientes que pidieron que les avisaras cuando un producto agotado vuelva a estar
            disponible. A quien dejó un correo se le avisa solo al reponer stock; a quien dejó un
            teléfono tienes que escribirle tú.
          </p>
        </div>
      </div>

      <div className="glass-card filters-bar">
        <select
          className="premium-input filter-select"
          value={status}
          onChange={(e) => cambiarFiltro('status', e.target.value)}
        >
          <option value="">Todas</option>
          <option value="pending">Pendientes de avisar</option>
          <option value="notified">Ya avisadas</option>
        </select>

        {productId && (
          <button type="button" className="filter-chip" onClick={() => cambiarFiltro('product', '')}>
            Filtrado por un producto · quitar filtro
          </button>
        )}
      </div>

      {isLoading ? (
        <div className="glass-card state-card">
          <Loader2 size={32} className="spinner" />
        </div>
      ) : esperas.length === 0 ? (
        <div className="glass-card state-card">
          <Bell size={48} />
          <h3>No hay nadie esperando</h3>
          <p>
            Cuando un cliente pulse "Avísame cuando llegue" en un producto agotado, aparecerá aquí.
          </p>
        </div>
      ) : (
        <div className="glass-card table-card">
          <div className="table-wrapper">
            <table className="dashboard-table">
              <thead>
                <tr>
                  <th>Producto</th>
                  <th>Cliente</th>
                  <th>Contacto</th>
                  <th>Esperando desde</th>
                  <th>Estado</th>
                  <th className="actions-header">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {esperas.map((espera) => {
                  const porCorreo = esCorreo(espera.customer_contact);
                  const pendiente = espera.notified_at === null;

                  return (
                    <tr key={espera.id}>
                      <td>
                        <div className="product-cell">
                          {espera.product?.thumbnail_url ? (
                            <img
                              loading="lazy"
                              decoding="async"
                              className="product-thumb"
                              src={espera.product.thumbnail_url}
                              alt={espera.product.name}
                            />
                          ) : (
                            <div className="product-thumb product-thumb-empty">
                              <Package size={16} />
                            </div>
                          )}
                          <div className="product-info">
                            <span className="product-name" title={espera.product?.name}>
                              {espera.product?.name || 'Producto eliminado'}
                            </span>
                            {espera.product && (
                              <span className={`product-stock ${espera.product.stock > 0 ? 'in' : 'out'}`}>
                                {espera.product.stock > 0
                                  ? `${espera.product.stock} en stock`
                                  : 'Sigue agotado'}
                              </span>
                            )}
                          </div>
                        </div>
                      </td>
                      <td className="name-cell">{espera.customer_name}</td>
                      <td>
                        <span className="contact-cell">
                          {porCorreo ? <Mail size={13} /> : <Phone size={13} />}
                          {espera.customer_contact}
                        </span>
                      </td>
                      <td className="date-cell">
                        {new Date(espera.created_at).toLocaleDateString()}
                      </td>
                      <td>
                        {pendiente ? (
                          <span className="badge badge-warning">
                            {porCorreo ? 'Se avisa al reponer' : 'Avisar a mano'}
                          </span>
                        ) : (
                          <span className="badge badge-success" title={espera.notified_at ?? undefined}>
                            Avisado
                          </span>
                        )}
                      </td>
                      <td>
                        <div className="action-buttons">
                          {/* Sin correo no hay aviso automatico: el boton de WhatsApp
                              es lo unico que tiene el dueno para cerrar esa espera. */}
                          {!porCorreo && (
                            <button
                              type="button"
                              className="btn-whatsapp"
                              onClick={() => escribirPorWhatsapp(espera)}
                              title="Escribir por WhatsApp"
                            >
                              <Phone size={13} /> WhatsApp
                            </button>
                          )}

                          {pendiente ? (
                            <button
                              type="button"
                              className="btn-action btn-action-approve"
                              onClick={() => marcarMutation.mutate({ id: espera.id, notified: true })}
                              disabled={marcarMutation.isPending}
                              title="Marcar como avisado"
                            >
                              <Check size={15} />
                            </button>
                          ) : (
                            <button
                              type="button"
                              className="btn-action"
                              onClick={() => marcarMutation.mutate({ id: espera.id, notified: false })}
                              disabled={marcarMutation.isPending}
                              title="Volver a dejarlo pendiente"
                            >
                              <Undo2 size={15} />
                            </button>
                          )}

                          <button
                            type="button"
                            className="btn-action btn-action-danger"
                            onClick={() => handleBorrar(espera)}
                            disabled={borrarMutation.isPending}
                            title="Eliminar la espera"
                          >
                            <Trash2 size={15} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {pagination && pagination.last_page > 1 && (
            <div className="pagination-bar">
              <span className="pagination-info">
                Página {pagination.current_page} de {pagination.last_page} · {pagination.total} en total
              </span>
              <div className="pagination-buttons">
                <button
                  type="button"
                  className="page-btn"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1}
                >
                  <ChevronLeft size={14} /> Anterior
                </button>
                <button
                  type="button"
                  className="page-btn"
                  onClick={() => setPage((p) => Math.min(pagination.last_page, p + 1))}
                  disabled={page >= pagination.last_page}
                >
                  Siguiente <ChevronRight size={14} />
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
