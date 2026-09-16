import './CartDrawer.css';

import { useState, useEffect } from 'react';
import { toast } from 'react-hot-toast';
import { X, Trash2, Plus, Minus, ShoppingCart, Loader2, Send, Store, Truck, Wallet } from 'lucide-react';
import { useCartStore } from '../../stores/cartStore';
import { useCustomerAuthStore } from '../../stores/customerAuthStore';
import { createPublicOrder } from '../../api/public';
import type { Tenant } from '../../types';
import { formatMoney } from '../../utils/money';
import { metodosDePagoActivos } from '../../utils/paymentMethods';
import { COUNTRY_CODES, deriveCountryCode, splitPhone } from '../../utils/phone';
import { claveDeLinea, datosDeVenta, nombreConVariante } from '../../utils/variants';

interface CartDrawerProps {
  open: boolean;
  onClose: () => void;
  slug: string;
  tenant: Tenant;
}

export default function CartDrawer({ open, onClose, slug, tenant }: CartDrawerProps) {
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency);

  const items = useCartStore((s) => s.items);
  const setQuantity = useCartStore((s) => s.setQuantity);
  const removeItem = useCartStore((s) => s.removeItem);
  const clear = useCartStore((s) => s.clear);
  const totalAmount = useCartStore((s) => s.totalAmount());

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  // El prefijo por defecto sale del WhatsApp de la tienda (PUB-4): en una tienda
  // peruana el checkout aparece con +51. Se guarda solo la elección manual y se
  // resuelve al leer, para no depender de un efecto que espere al tenant.
  const [countryCodeOverride, setCountryCodeOverride] = useState<string | null>(null);
  const countryCode = countryCodeOverride ?? deriveCountryCode(tenant?.whatsapp_number);
  const [email, setEmail] = useState('');
  const [note, setNote] = useState('');
  const [sending, setSending] = useState(false);

  // Envío (MOD-1). Nace en "recojo" a propósito: es la opción que nunca
  // cobra, así que un comprador que no toca nada no ve subir el total.
  const [deliveryMethod, setDeliveryMethod] = useState<'pickup' | 'delivery'>('pickup');
  const conEnvio = tenant?.delivery_enabled ?? false;
  const costoEnvio = conEnvio && deliveryMethod === 'delivery' ? Number(tenant.delivery_cost) : 0;
  const totalConEnvio = totalAmount + costoEnvio;

  const metodosDePago = metodosDePagoActivos(tenant?.payment_methods);

  const { user, isAuthenticated: isCustomerAuthenticated } = useCustomerAuthStore();

  useEffect(() => {
    if (open && isCustomerAuthenticated && user) {
      if (user.name) setName(user.name);
      if (user.phone) {
        const parsed = splitPhone(user.phone);
        setCountryCodeOverride(parsed.code);
        setPhone(parsed.number);
      }
      // FUN-2: quien tiene cuenta ya nos dio su correo al registrarse; pedirselo
      // otra vez es friccion gratis. Sigue siendo editable por si quiere que la
      // confirmacion le llegue a otra direccion.
      if (user.email) setEmail(user.email);
    }
  }, [isCustomerAuthenticated, user, open]);

  /**
   * `total` llega del pedido ya creado, no se recalcula aquí: es lo que de
   * verdad va a cobrar la tienda (incluido el envío, calculado en el
   * servidor) y no puede desalinearse de `totalConEnvio`.
   */
  const buildWhatsappMessage = (orderNumber: number, total: number) => {
    const lines = items.map((i) => {
      const { precio } = datosDeVenta(i.product, i.variant);
      return `• ${i.quantity} x ${nombreConVariante(i.product.name, i.variant?.nombre)} — ${money(precio * i.quantity)}`;
    });

    // MOD-1: solo se menciona si la tienda de verdad tiene envío. "Recojo en
    // tienda" con una sola opción posible no le dice nada nuevo a nadie.
    const lineaDeEnvio = conEnvio
      ? `Entrega: ${deliveryMethod === 'delivery' ? `Delivery (${money(costoEnvio)})` : 'Recojo en tienda'}\n`
      : '';

    // MOD-3: cómo pagar, para que no tenga que preguntarlo por chat.
    const lineaDePago = metodosDePago.length > 0
      ? `\nFormas de pago: ${metodosDePago.map((m) => m.etiqueta + (m.detalle ? ` (${m.detalle})` : '')).join(', ')}\n`
      : '';

    return (
      `Hola ${tenant.name}, quiero hacer este pedido:\n\n` +
      `${lines.join('\n')}\n\n` +
      `*Total: ${money(total)}*\n\n` +
      `Nombre: ${name}\n` +
      lineaDeEnvio +
      (note ? `Nota: ${note}\n` : '') +
      lineaDePago +
      // FUN-3: el correlativo de la tienda. Antes iba un trozo del UUID, que ni
      // el comprador podía leer en voz alta ni el dueño buscar en el panel.
      `\n(Pedido #${orderNumber})`
    );
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (items.length === 0) return;
    setSending(true);
    try {
      const submittedPhone = phone.trim() ? (countryCode + phone.trim()) : '';

      const order = await createPublicOrder(slug, {
        customer_name: name,
        customer_phone: submittedPhone,
        customer_email: email.trim() || undefined,
        customer_note: note || undefined,
        items: items.map((i) => ({ product_id: i.product.id, variant_id: i.variant?.id ?? null, quantity: i.quantity })),
        // MOD-1: solo se manda si la tienda tiene envío — mandar un método sin
        // que exista la opción no vale nada, y así el backend distingue "no
        // ofrece envío" de "no eligió".
        delivery_method: conEnvio ? deliveryMethod : undefined,
      });

      // El total del pedido creado, no `totalConEnvio`: es lo que el
      // servidor de verdad cobró.
      const text = encodeURIComponent(buildWhatsappMessage(order.number, Number(order.total)));
      const cleanPhone = tenant.whatsapp_number.replace(/[^0-9]/g, '');
      window.open(`https://wa.me/${cleanPhone}?text=${text}`, '_blank');

      toast.success('Pedido enviado. Continúa la conversación por WhatsApp.');
      clear();
      setName('');
      setPhone('');
      setEmail('');
      setNote('');
      setDeliveryMethod('pickup');
      onClose();
    } catch (err: any) {
      const msg = err?.response?.data?.message ?? 'No se pudo enviar el pedido. Intenta de nuevo.';
      toast.error(msg);
    } finally {
      setSending(false);
    }
  };

  return (
    <>
      {open && <div className="cart-overlay" onClick={onClose} />}

      <aside className={`cart-drawer ${open ? 'open' : ''}`} aria-hidden={!open}>
        <header className="cart-head">
          <h3><ShoppingCart size={18} /> Mi pedido</h3>
          <button className="cart-close" onClick={onClose} aria-label="Cerrar"><X size={20} /></button>
        </header>

        {items.length === 0 ? (
          <div className="cart-empty">
            <ShoppingCart size={40} />
            <p>Tu pedido está vacío.</p>
            <span>Agrega productos del catálogo para solicitarlos.</span>
          </div>
        ) : (
          <>
            <div className="cart-items">
              {items.map((i) => {
                // MOD-5: precio y foto de la variante elegida, si la hay.
                const venta = datosDeVenta(i.product, i.variant);
                const miniatura = i.variant?.thumbnail_url ?? i.product.thumbnail_url;
                const varianteId = i.variant?.id ?? null;

                return (
                <div className="cart-item" key={claveDeLinea(i.product.id, varianteId)}>
                  <div className="cart-item-img">
                    {miniatura
                      ? <img loading="lazy" decoding="async" src={miniatura} alt={i.product.name} />
                      : <ShoppingCart size={18} />}
                  </div>
                  <div className="cart-item-info">
                    <p className="cart-item-name">{i.product.name}</p>
                    {i.variant && <p className="cart-item-variant">{i.variant.nombre}</p>}
                    <span className="cart-item-price">
                      {venta.sale_price !== null ? (
                        <>
                          <span className="strike-price" style={{ textDecoration: 'line-through', marginRight: '0.35rem', opacity: 0.6 }}>
                            {money(venta.price)}
                          </span>
                          <span className="sale-price-active" style={{ color: 'var(--primary)', fontWeight: 600 }}>
                            {money(venta.sale_price)}
                          </span>
                        </>
                      ) : (
                        money(venta.price)
                      )}
                    </span>
                  </div>
                  <div className="cart-item-actions">
                    <div className="qty-stepper">
                      <button type="button" onClick={() => setQuantity(i.product.id, i.quantity - 1, varianteId)} aria-label="Menos"><Minus size={14} /></button>
                      <span>{i.quantity}</span>
                      <button type="button" onClick={() => setQuantity(i.product.id, i.quantity + 1, varianteId)} aria-label="Más"><Plus size={14} /></button>
                    </div>
                    <button type="button" className="cart-item-remove" onClick={() => removeItem(i.product.id, varianteId)} aria-label="Quitar"><Trash2 size={15} /></button>
                  </div>
                </div>
                );
              })}
            </div>

            <form className="cart-checkout" onSubmit={handleSubmit}>
              {/* MOD-1: solo si la tienda tiene envío. Va antes del total
                  para que el comprador vea subir el número justo al elegir. */}
              {conEnvio && (
                <div className="cart-delivery">
                  <span className="cart-delivery-label">Entrega</span>
                  <div className="cart-delivery-options">
                    <label className={`cart-delivery-option ${deliveryMethod === 'pickup' ? 'active' : ''}`}>
                      <input
                        type="radio"
                        name="delivery_method"
                        checked={deliveryMethod === 'pickup'}
                        onChange={() => setDeliveryMethod('pickup')}
                      />
                      <Store size={15} />
                      <span>Recojo en tienda</span>
                    </label>
                    <label className={`cart-delivery-option ${deliveryMethod === 'delivery' ? 'active' : ''}`}>
                      <input
                        type="radio"
                        name="delivery_method"
                        checked={deliveryMethod === 'delivery'}
                        onChange={() => setDeliveryMethod('delivery')}
                      />
                      <Truck size={15} />
                      <span>Delivery (+{money(tenant.delivery_cost)})</span>
                    </label>
                  </div>
                </div>
              )}
              <div className="cart-total">
                <span>Total</span>
                <strong>{money(totalConEnvio)}</strong>
              </div>
              <input className="premium-input" placeholder="Tu nombre" value={name} onChange={(e) => setName(e.target.value)} maxLength={200} required />
              <div style={{ display: 'flex', gap: '0.25rem' }}>
                <select
                  value={countryCode}
                  onChange={(e) => setCountryCodeOverride(e.target.value)}
                  style={{ padding: '0.6rem', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: '6px', color: 'var(--text-primary)', fontSize: '0.9rem', width: '90px', outline: 'none' }}
                >
                  {COUNTRY_CODES.map(({ code, label }) => (
                    <option key={code} value={code}>{label}</option>
                  ))}
                  <option value="">Otro</option>
                </select>
                <input 
                  className="premium-input" 
                  placeholder="Tu WhatsApp / teléfono" 
                  value={phone} 
                  onChange={(e) => setPhone(e.target.value.replace(/[^0-9]/g, ''))} 
                  maxLength={30} 
                  required 
                  style={{ flex: 1, margin: 0 }}
                />
              </div>
              {/*
                FUN-2: opcional a proposito. Obligarlo aseguraria que la
                confirmacion llegue siempre, pero este es el unico paso donde de
                verdad se pierden ventas. El texto de ayuda dice que se gana al
                dejarlo, que es lo que hace que la gente lo escriba.
              */}
              <input
                className="premium-input"
                type="email"
                placeholder="Tu correo (opcional)"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                maxLength={200}
                autoComplete="email"
              />
              <p className="cart-field-hint">Si lo dejas, te enviamos la confirmacion y te avisamos cuando tu pedido este listo.</p>
              <textarea className="premium-input" placeholder="Nota (opcional): forma de entrega, dudas..." value={note} onChange={(e) => setNote(e.target.value)} rows={2} maxLength={1000} />

              {/* MOD-3: informativo. El pago se coordina por WhatsApp; esto
                  solo evita que el comprador tenga que preguntarlo. */}
              {metodosDePago.length > 0 && (
                <div className="cart-payment-methods">
                  <span className="cart-payment-methods-label"><Wallet size={14} /> Formas de pago</span>
                  <ul>
                    {metodosDePago.map((m) => (
                      <li key={m.clave}>
                        <strong>{m.etiqueta}</strong>{m.detalle && ` · ${m.detalle}`}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              <button type="submit" className="btn-primary cart-submit" disabled={sending}>
                {sending ? <Loader2 className="spin" size={18} /> : <Send size={18} />}
                {sending ? 'Enviando...' : 'Enviar pedido por WhatsApp'}
              </button>
            </form>
          </>
        )}
      </aside>

    </>
  );
}
