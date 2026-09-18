import './CouponsPage.css';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Loader2, Plus, Ticket, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { createCoupon, deleteCoupon, getCoupons, updateCoupon, type CouponPayload } from '../../api/coupons';
import { mensajeDeError } from '../../api/erroresDeFormulario';
import { useTenantStore } from '../../stores/tenantStore';
import { formatearFecha } from '../../utils/fechas';
import { formatMoney } from '../../utils/money';
import Dialogo from '../../components/ui/Dialogo';
import type { Coupon } from '../../types';

const VACIO: CouponPayload = {
  code: '',
  type: 'percent',
  value: '',
  min_purchase: '',
  max_uses: null,
  starts_at: null,
  ends_at: null,
  is_active: true,
};

/**
 * Cupones de descuento (MOD-4). Solo admin.
 *
 * Un cupón es dinero que se deja de cobrar, así que lo decide quien decide los
 * precios (FUN-4). El descuento se aplica sobre los productos —nunca sobre el
 * envío— y antes del impuesto; eso lo calcula el servidor, aquí solo se
 * administran los códigos.
 */
export default function CouponsPage() {
  const tenant = useTenantStore((s) => s.tenant);
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [editando, setEditando] = useState<Coupon | null>(null);
  const [abierto, setAbierto] = useState(false);
  const [form, setForm] = useState<CouponPayload>(VACIO);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['coupons', page],
    queryFn: () => getCoupons(page),
  });

  const refrescar = () => queryClient.invalidateQueries({ queryKey: ['coupons'] });

  const guardar = useMutation({
    mutationFn: (payload: CouponPayload) =>
      editando ? updateCoupon(editando.id, payload) : createCoupon(payload),
    onSuccess: () => {
      toast.success(editando ? 'Cupón actualizado.' : 'Cupón creado.');
      cerrar();
      refrescar();
    },
    // El 422 trae el motivo concreto —código repetido, fechas al revés, techo de
    // cupones— y es lo que hay que enseñar, no un "no se pudo guardar" (UI-11).
    onError: (error) => toast.error(mensajeDeError(error, { contexto: 'panel' })),
  });

  const borrar = useMutation({
    mutationFn: (id: string) => deleteCoupon(id),
    onSuccess: () => {
      toast.success('Cupón borrado.');
      refrescar();
    },
    onError: (error) => toast.error(mensajeDeError(error, { contexto: 'panel' })),
  });

  const abrirNuevo = () => {
    setEditando(null);
    setForm(VACIO);
    setAbierto(true);
  };

  const abrirEdicion = (cupon: Coupon) => {
    setEditando(cupon);
    setForm({
      code: cupon.code,
      type: cupon.type,
      value: String(cupon.value),
      min_purchase: cupon.min_purchase != null ? String(cupon.min_purchase) : '',
      max_uses: cupon.max_uses,
      // El <input type="date"> quiere YYYY-MM-DD y el servidor manda ISO entero.
      starts_at: cupon.starts_at ? cupon.starts_at.slice(0, 10) : null,
      ends_at: cupon.ends_at ? cupon.ends_at.slice(0, 10) : null,
      is_active: cupon.is_active,
    });
    setAbierto(true);
  };

  const cerrar = () => {
    setAbierto(false);
    setEditando(null);
  };

  const enviar = (e: React.FormEvent) => {
    e.preventDefault();

    // Los vacíos se mandan como null y no como '': `null` es "sin límite", y ''
    // haría fallar las reglas `numeric`/`date` del backend.
    guardar.mutate({
      ...form,
      code: form.code.trim().toUpperCase(),
      min_purchase: form.min_purchase === '' ? null : form.min_purchase,
      max_uses: form.max_uses || null,
      starts_at: form.starts_at || null,
      ends_at: form.ends_at || null,
    });
  };

  const confirmarBorrado = (cupon: Coupon) => {
    if (window.confirm(`Se borrará el cupón ${cupon.code}. Los pedidos que ya lo usaron no cambian.`)) {
      borrar.mutate(cupon.id);
    }
  };

  const cupones = data?.data ?? [];

  const descuentoDe = (cupon: Coupon) =>
    cupon.type === 'percent'
      ? `${Number(cupon.value)}%`
      : formatMoney(cupon.value, tenant?.currency);

  return (
    <div className="coupons-page animate-fade-in page-coupons">
      <div className="page-header">
        <div>
          <h1>Cupones</h1>
          <p className="page-description">
            Códigos de descuento para tus campañas. El comprador lo escribe en el carrito y se
            descuenta del subtotal de los productos, antes del impuesto y sin tocar el envío.
          </p>
        </div>

        <button type="button" className="btn-primary" onClick={abrirNuevo}>
          <Plus size={16} /> Nuevo cupón
        </button>
      </div>

      {isLoading ? (
        <div className="glass-card state-card">
          <Loader2 size={32} className="spinner" />
        </div>
      ) : isError ? (
        <div className="glass-card state-card">
          <h3>No se pudieron cargar los cupones</h3>
          <p>Vuelve a intentarlo en unos segundos.</p>
        </div>
      ) : cupones.length === 0 ? (
        <div className="glass-card state-card">
          <Ticket size={48} />
          <h3>Todavía no tienes cupones</h3>
          <p>Crea un código, ponle una vigencia y compártelo: el comprador lo usa en el carrito.</p>
        </div>
      ) : (
        <div className="glass-card list-card">
          <ul className="coupon-list">
            {cupones.map((cupon) => (
              <li key={cupon.id} className={`coupon-item ${cupon.is_active ? '' : 'apagado'}`}>
                <div className="coupon-main">
                  <span className="coupon-code">{cupon.code}</span>
                  <span className="coupon-value">{descuentoDe(cupon)}</span>
                  {!cupon.is_active && <span className="coupon-badge">Apagado</span>}
                </div>

                <div className="coupon-meta">
                  {/* Los límites, solo los que tenga puestos: una línea que diga
                      "sin compra mínima, sin tope, sin vigencia" no informa. */}
                  {cupon.min_purchase != null && (
                    <span>Desde {formatMoney(cupon.min_purchase, tenant?.currency)}</span>
                  )}
                  {cupon.max_uses != null && (
                    <span>
                      {cupon.used_count} de {cupon.max_uses} usos
                    </span>
                  )}
                  {cupon.max_uses == null && cupon.used_count > 0 && (
                    <span>{cupon.used_count} usos</span>
                  )}
                  {(cupon.starts_at || cupon.ends_at) && (
                    <span>
                      {cupon.starts_at ? formatearFecha(cupon.starts_at, tenant?.timezone) : '…'}
                      {' → '}
                      {cupon.ends_at ? formatearFecha(cupon.ends_at, tenant?.timezone) : '…'}
                    </span>
                  )}
                </div>

                <div className="coupon-actions">
                  <button type="button" className="btn-secondary" onClick={() => abrirEdicion(cupon)}>
                    Editar
                  </button>
                  <button
                    type="button"
                    className="btn-danger-ghost"
                    onClick={() => confirmarBorrado(cupon)}
                    disabled={borrar.isPending}
                  >
                    <Trash2 size={14} /> Borrar
                  </button>
                </div>
              </li>
            ))}
          </ul>

          {data && data.last_page > 1 && (
            <div className="pagination-bar">
              <span className="pagination-info">
                Página {data.current_page} de {data.last_page} · {data.total} en total
              </span>
              <div className="pagination-buttons">
                <button type="button" className="page-btn" onClick={() => setPage(page - 1)} disabled={page <= 1}>
                  <ChevronLeft size={14} /> Anterior
                </button>
                <button
                  type="button"
                  className="page-btn"
                  onClick={() => setPage(page + 1)}
                  disabled={page >= data.last_page}
                >
                  Siguiente <ChevronRight size={14} />
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {abierto && (
        <Dialogo
          titulo={editando ? `Editar ${editando.code}` : 'Nuevo cupón'}
          onCerrar={cerrar}
          className="page-coupons"
        >
          <form className="dialogo-cuerpo" onSubmit={enviar}>
            <div className="form-group">
              <label>Código</label>
              <input
                className="premium-input"
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
                maxLength={40}
                required
              />
              <span className="helper-text">
                Sin espacios ni signos. Se guarda en mayúsculas y el comprador puede escribirlo como
                quiera.
              </span>
            </div>

            <div className="form-row">
              <div className="form-group">
                <label>Tipo</label>
                <select
                  className="premium-input"
                  value={form.type}
                  onChange={(e) => setForm({ ...form, type: e.target.value as 'percent' | 'fixed' })}
                >
                  <option value="percent">Porcentaje</option>
                  <option value="fixed">Monto fijo</option>
                </select>
              </div>

              <div className="form-group">
                <label>{form.type === 'percent' ? 'Porcentaje (%)' : 'Monto'}</label>
                <input
                  className="premium-input"
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={form.value}
                  onChange={(e) => setForm({ ...form, value: e.target.value })}
                  required
                />
              </div>
            </div>

            <div className="form-row">
              <div className="form-group">
                <label>Compra mínima</label>
                <input
                  className="premium-input"
                  type="number"
                  min="0"
                  step="0.01"
                  value={form.min_purchase ?? ''}
                  onChange={(e) => setForm({ ...form, min_purchase: e.target.value })}
                  placeholder="Sin mínimo"
                />
              </div>

              <div className="form-group">
                <label>Usos máximos</label>
                <input
                  className="premium-input"
                  type="number"
                  min="1"
                  step="1"
                  value={form.max_uses ?? ''}
                  onChange={(e) => setForm({ ...form, max_uses: e.target.value ? Number(e.target.value) : null })}
                  placeholder="Sin tope"
                />
              </div>
            </div>

            <div className="form-row">
              <div className="form-group">
                <label>Desde</label>
                <input
                  className="premium-input"
                  type="date"
                  value={form.starts_at ?? ''}
                  onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
                />
              </div>

              <div className="form-group">
                <label>Hasta</label>
                <input
                  className="premium-input"
                  type="date"
                  value={form.ends_at ?? ''}
                  onChange={(e) => setForm({ ...form, ends_at: e.target.value })}
                />
              </div>
            </div>

            <label className="coupon-toggle">
              <input
                type="checkbox"
                checked={form.is_active ?? true}
                onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
              />
              <span>Activo</span>
            </label>
            <span className="helper-text">
              Apagarlo lo saca de circulación sin perder su contador de usos; borrarlo sí lo pierde.
            </span>

            <div className="dialogo-acciones">
              <button type="button" className="btn-secondary" onClick={cerrar}>
                Cancelar
              </button>
              <button type="submit" className="btn-primary" disabled={guardar.isPending}>
                {guardar.isPending ? 'Guardando…' : 'Guardar'}
              </button>
            </div>
          </form>
        </Dialogo>
      )}
    </div>
  );
}
