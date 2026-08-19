import './CartDrawer.css';

import { useState, useEffect } from 'react';
import { toast } from 'react-hot-toast';
import { X, Trash2, Plus, Minus, ShoppingCart, Loader2, Send } from 'lucide-react';
import { useCartStore } from '../../stores/cartStore';
import { useCustomerAuthStore } from '../../stores/customerAuthStore';
import { createPublicOrder } from '../../api/public';
import type { Tenant } from '../../types';
import { formatMoney } from '../../utils/money';
import { COUNTRY_CODES, deriveCountryCode, splitPhone } from '../../utils/phone';

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
  const [note, setNote] = useState('');
  const [sending, setSending] = useState(false);

  const { user, isAuthenticated: isCustomerAuthenticated } = useCustomerAuthStore();

  useEffect(() => {
    if (open && isCustomerAuthenticated && user) {
      if (user.name) setName(user.name);
      if (user.phone) {
        const parsed = splitPhone(user.phone);
        setCountryCodeOverride(parsed.code);
        setPhone(parsed.number);
      }
    }
  }, [isCustomerAuthenticated, user, open]);

  const buildWhatsappMessage = (orderId: string) => {
    const lines = items.map((i) => {
      const price = i.product.sale_price !== null && i.product.sale_price !== undefined
        ? Number(i.product.sale_price)
        : Number(i.product.price);
      return `• ${i.quantity} x ${i.product.name} — ${money(price * i.quantity)}`;
    });
    return (
      `Hola ${tenant.name}, quiero hacer este pedido:\n\n` +
      `${lines.join('\n')}\n\n` +
      `*Total: ${money(totalAmount)}*\n\n` +
      `Nombre: ${name}\n` +
      (note ? `Nota: ${note}\n` : '') +
      `\n(Pedido #${orderId.slice(-8)})`
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
        customer_note: note || undefined,
        items: items.map((i) => ({ product_id: i.product.id, quantity: i.quantity })),
      });

      const text = encodeURIComponent(buildWhatsappMessage(order.id));
      const cleanPhone = tenant.whatsapp_number.replace(/[^0-9]/g, '');
      window.open(`https://wa.me/${cleanPhone}?text=${text}`, '_blank');

      toast.success('Pedido enviado. Continúa la conversación por WhatsApp.');
      clear();
      setName('');
      setPhone('');
      setNote('');
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
              {items.map((i) => (
                <div className="cart-item" key={i.product.id}>
                  <div className="cart-item-img">
                    {i.product.thumbnail_url
                      ? <img src={i.product.thumbnail_url} alt={i.product.name} />
                      : <ShoppingCart size={18} />}
                  </div>
                  <div className="cart-item-info">
                    <p className="cart-item-name">{i.product.name}</p>
                    <span className="cart-item-price">
                      {i.product.sale_price !== null && i.product.sale_price !== undefined ? (
                        <>
                          <span className="strike-price" style={{ textDecoration: 'line-through', marginRight: '0.35rem', opacity: 0.6 }}>
                            {money(Number(i.product.price))}
                          </span>
                          <span className="sale-price-active" style={{ color: 'var(--primary)', fontWeight: 600 }}>
                            {money(Number(i.product.sale_price))}
                          </span>
                        </>
                      ) : (
                        money(Number(i.product.price))
                      )}
                    </span>
                  </div>
                  <div className="cart-item-actions">
                    <div className="qty-stepper">
                      <button type="button" onClick={() => setQuantity(i.product.id, i.quantity - 1)} aria-label="Menos"><Minus size={14} /></button>
                      <span>{i.quantity}</span>
                      <button type="button" onClick={() => setQuantity(i.product.id, i.quantity + 1)} aria-label="Más"><Plus size={14} /></button>
                    </div>
                    <button type="button" className="cart-item-remove" onClick={() => removeItem(i.product.id)} aria-label="Quitar"><Trash2 size={15} /></button>
                  </div>
                </div>
              ))}
            </div>

            <form className="cart-checkout" onSubmit={handleSubmit}>
              <div className="cart-total">
                <span>Total</span>
                <strong>{money(totalAmount)}</strong>
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
              <textarea className="premium-input" placeholder="Nota (opcional): forma de entrega, dudas..." value={note} onChange={(e) => setNote(e.target.value)} rows={2} maxLength={1000} />
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
