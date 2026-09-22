import './NewOrderModal.css';

import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { Search, Plus, Minus, Trash2, Loader2, ShoppingBag, AlertTriangle } from 'lucide-react';
import Dialogo from '../ui/Dialogo';
import { getProducts } from '../../api/products';
import { createOrder } from '../../api/orders';
import type { Order, PaginatedResponse, Product, ProductVariant } from '../../types';
import { formatMoney } from '../../utils/money';
import { claveDeLinea, datosDeVenta } from '../../utils/variants';

/**
 * El padre lo monta solo cuando esta abierto, asi no hace falta un efecto que
 * limpie el formulario al cerrar: el estado se va con el desmontaje.
 */
interface NewOrderModalProps {
  onClose: () => void;
  onCreated: (order: Order) => void;
  currency?: string | null;
}

/**
 * Línea del pedido en construcción. Guardamos el producto entero para poder
 * mostrar precio y stock, y la variante vendida si la tiene (MOD-5): el mismo
 * producto en 16 GB y en 32 GB son dos líneas.
 */
type Line = { product: Product; variant: ProductVariant | null; quantity: number };

const claveDe = (line: Line) => claveDeLinea(line.product.id, line.variant?.id);

/**
 * Precio que efectivamente se cobra: el de oferta cuando existe, de la variante
 * si la hay. Igual criterio que el servidor.
 *
 * MOD-15: y con la cantidad, porque el precio por mayor depende de cuantas se
 * lleve esa linea. Sin pasarla, el pie del modal sumaba a precio de lista y el
 * servidor cobraba el tramo: el dueño veia un total y se le guardaba otro.
 */
const priceOf = (line: Pick<Line, 'product' | 'variant'>, cantidad = 1): number =>
  datosDeVenta(line.product, line.variant, cantidad).precio;

export default function NewOrderModal({ onClose, onCreated, currency }: NewOrderModalProps) {
  const money = (n: number | string | null | undefined) => formatMoney(n, currency);

  const [customerName, setCustomerName] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [note, setNote] = useState('');
  const [status, setStatus] = useState<'attended' | 'pending'>('attended');
  const [lines, setLines] = useState<Line[]>([]);
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');

  // Mismo debounce que el buscador del catálogo (PUB-3): sin esto se dispara
  // una petición por tecla mientras el dueño escribe el nombre del producto.
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(timer);
  }, [search]);

  const { data: productsPage, isLoading: isSearching } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['newOrderProducts', debouncedSearch],
    queryFn: () => getProducts({ search: debouncedSearch || undefined, page: 1 }),
  });

  const results = productsPage?.data ?? [];

  const total = useMemo(
    () => lines.reduce((sum, line) => sum + priceOf(line, line.quantity) * line.quantity, 0),
    [lines],
  );

  const addProduct = (product: Product, variant: ProductVariant | null = null) => {
    const clave = claveDeLinea(product.id, variant?.id);
    setLines((current) => {
      const existing = current.find((line) => claveDe(line) === clave);
      if (existing) {
        return current.map((line) =>
          claveDe(line) === clave ? { ...line, quantity: line.quantity + 1 } : line,
        );
      }
      return [...current, { product, variant, quantity: 1 }];
    });
  };

  const setQuantity = (clave: string, quantity: number) => {
    if (quantity < 1) return;
    setLines((current) =>
      current.map((line) => (claveDe(line) === clave ? { ...line, quantity } : line)),
    );
  };

  const removeLine = (clave: string) => {
    setLines((current) => current.filter((line) => claveDe(line) !== clave));
  };

  const createMutation = useMutation({
    mutationFn: () =>
      createOrder({
        customer_name: customerName.trim(),
        customer_phone: customerPhone.trim() || null,
        customer_email: customerEmail.trim() || null,
        customer_note: note.trim() || null,
        status,
        items: lines.map((line) => ({ product_id: line.product.id, variant_id: line.variant?.id ?? null, quantity: line.quantity })),
      }),
    onSuccess: (order) => {
      toast.success('Venta registrada');
      onCreated(order);
      onClose();
    },
    onError: (err: unknown) => {
      const response = (err as { response?: { data?: { message?: string } } }).response;
      const msg = response?.data?.message || 'No se pudo registrar la venta.';
      toast.error(msg);
    },
  });

  // Vender más unidades de las que hay deja el stock en negativo. No se bloquea
  // (el dueño puede tener mercadería sin registrar) pero se avisa.
  const linesOverStock = lines.filter((line) => line.quantity > datosDeVenta(line.product, line.variant).stock);

  const canSubmit = customerName.trim().length > 0 && lines.length > 0 && !createMutation.isPending;

  return (
    <Dialogo titulo="Nueva venta de mostrador" onCerrar={onClose} ancho={900}>
        <div className="new-order-body">
          {/* Buscador + resultados */}
          <div className="new-order-column">
            <label className="column-label">1. Elige los productos</label>
            <div className="no-search">
              <Search size={16} className="no-search-icon" />
              <input
                type="text"
                className="premium-input no-search-input"
                placeholder="Buscar por nombre, marca o código..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                autoFocus
              />
            </div>

            <div className="product-results">
              {isSearching ? (
                <div className="results-empty">
                  <Loader2 className="spinner" size={20} />
                </div>
              ) : results.length === 0 ? (
                <div className="results-empty">Sin resultados</div>
              ) : (
                results.map((product) =>
                  product.variants && product.variants.length > 0 ? (
                    // MOD-5: con variantes se vende una de ellas; cada una es su botón.
                    <div key={product.id} className="product-result-group">
                      <span className="result-name">
                        {product.name}
                        {!product.is_active && <em className="result-flag">no publicado</em>}
                      </span>
                      {product.variants.map((variant) => (
                        <button
                          key={variant.id}
                          type="button"
                          className="product-result variant-result"
                          onClick={() => addProduct(product, variant)}
                        >
                          <span className="result-name">{variant.nombre}</span>
                          <span className="result-meta">
                            {money(priceOf({ product, variant }))} · {variant.stock} u.
                          </span>
                        </button>
                      ))}
                    </div>
                  ) : (
                  <button
                    key={product.id}
                    type="button"
                    className="product-result"
                    onClick={() => addProduct(product)}
                  >
                    <span className="result-name">
                      {product.name}
                      {!product.is_active && <em className="result-flag">no publicado</em>}
                    </span>
                    <span className="result-meta">
                      {money(priceOf({ product, variant: null }))} · {product.stock} u.
                    </span>
                  </button>
                  ),
                )
              )}
            </div>
          </div>

          {/* Líneas + datos de la venta */}
          <div className="new-order-column">
            <label className="column-label">2. Revisa la venta</label>

            <div className="order-lines">
              {lines.length === 0 ? (
                <div className="results-empty">
                  <ShoppingBag size={22} />
                  <p>Todavía no agregaste productos.</p>
                </div>
              ) : (
                lines.map((line) => (
                  <div key={claveDe(line)} className="order-line">
                    <div className="line-info">
                      <span className="line-name">
                        {line.product.name}
                        {line.variant && <span className="line-variant"> · {line.variant.nombre}</span>}
                      </span>
                      <span className="line-price">{money(priceOf(line, line.quantity) * line.quantity)}</span>
                    </div>
                    <div className="line-actions">
                      <button type="button" onClick={() => setQuantity(claveDe(line), line.quantity - 1)}>
                        <Minus size={13} />
                      </button>
                      <input
                        type="number"
                        min={1}
                        value={line.quantity}
                        onChange={(e) => setQuantity(claveDe(line), parseInt(e.target.value, 10) || 1)}
                        className="line-qty"
                      />
                      <button type="button" onClick={() => setQuantity(claveDe(line), line.quantity + 1)}>
                        <Plus size={13} />
                      </button>
                      <button type="button" className="line-remove" onClick={() => removeLine(claveDe(line))}>
                        <Trash2 size={14} />
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>

            {linesOverStock.length > 0 && (
              <p className="stock-warning">
                <AlertTriangle size={14} />
                Estás vendiendo más unidades de las registradas en{' '}
                {linesOverStock.map((line) => (line.variant ? `${line.product.name} (${line.variant.nombre})` : line.product.name)).join(', ')}. El stock quedará en negativo.
              </p>
            )}

            <div className="new-order-fields">
              <div className="form-group">
                <label htmlFor="order-customer">Cliente</label>
                <input
                  id="order-customer"
                  className="premium-input"
                  value={customerName}
                  onChange={(e) => setCustomerName(e.target.value)}
                  placeholder="Nombre de quien compra"
                  maxLength={200}
                />
              </div>
              <div className="form-group">
                <label htmlFor="order-phone">Teléfono (opcional)</label>
                <input
                  id="order-phone"
                  className="premium-input"
                  value={customerPhone}
                  onChange={(e) => setCustomerPhone(e.target.value)}
                  placeholder="Para avisarle por WhatsApp"
                  maxLength={30}
                />
              </div>
              <div className="form-group">
                <label htmlFor="order-email">Correo (opcional)</label>
                {/*
                  FUN-2: una venta de mostrador no manda correo de confirmacion
                  —el cliente esta delante—, pero si se apunta aqui, el aviso de
                  "ya esta listo" sale solo cuando el encargo cambie de estado.
                */}
                <input
                  id="order-email"
                  className="premium-input"
                  type="email"
                  value={customerEmail}
                  onChange={(e) => setCustomerEmail(e.target.value)}
                  placeholder="Para avisarle cuando este listo"
                  maxLength={200}
                />
              </div>
              <div className="form-group full">
                <label htmlFor="order-note">Nota (opcional)</label>
                <input
                  id="order-note"
                  className="premium-input"
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="Forma de pago, garantía, etc."
                  maxLength={1000}
                />
              </div>
              <div className="form-group full">
                <label>Estado</label>
                <div className="status-choices">
                  <button
                    type="button"
                    className={`status-choice ${status === 'attended' ? 'active' : ''}`}
                    onClick={() => setStatus('attended')}
                  >
                    Atendido
                    <em>Venta cerrada: descuenta stock ahora</em>
                  </button>
                  <button
                    type="button"
                    className={`status-choice ${status === 'pending' ? 'active' : ''}`}
                    onClick={() => setStatus('pending')}
                  >
                    Pendiente
                    <em>Reserva o encargo: no toca el stock</em>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="new-order-footer">
          <div className="footer-total">
            <span>Total</span>
            <strong>{money(total)}</strong>
          </div>
          <div className="footer-actions">
            <button type="button" className="btn-secondary" onClick={onClose}>
              Cancelar
            </button>
            <button
              type="button"
              className="btn-primary"
              disabled={!canSubmit}
              onClick={() => createMutation.mutate()}
            >
              {createMutation.isPending ? (
                <>
                  <Loader2 className="spinner" size={16} /> Registrando...
                </>
              ) : (
                'Registrar venta'
              )}
            </button>
          </div>
        </div>
    </Dialogo>
  );
}
