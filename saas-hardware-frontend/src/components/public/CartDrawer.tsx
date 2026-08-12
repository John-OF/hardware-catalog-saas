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
                  style={{ padding: '0.6rem', background: 'rgba(0,0,0,0.2)', border: '1px solid var(--border)', borderRadius: '6px', color: 'white', fontSize: '0.9rem', width: '90px', outline: 'none' }}
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

      <style>{`
        .cart-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 90; animation: fade 0.2s ease; }
        @keyframes fade { from { opacity: 0; } to { opacity: 1; } }
        .cart-drawer {
          position: fixed; top: 0; right: 0; height: 100dvh; width: 400px; max-width: 92vw;
          background: var(--bg-app); border-left: 1px solid var(--border);
          display: flex; flex-direction: column; z-index: 100;
          transform: translateX(100%); transition: transform 0.28s ease; box-shadow: -12px 0 40px rgba(0,0,0,0.25);
        }
        .cart-drawer.open { transform: translateX(0); }
        .cart-head { display: flex; align-items: center; justify-content: space-between; padding: 1.1rem 1.25rem; border-bottom: 1px solid var(--border); }
        .cart-head h3 { display: flex; align-items: center; gap: 0.5rem; font-family: var(--font-heading); font-size: 1.05rem; color: var(--text-primary); }
        .cart-close { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; display: flex; }
        .cart-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.5rem; color: var(--text-muted); text-align: center; padding: 2rem; }
        .cart-empty p { color: var(--text-secondary); font-weight: 600; }
        .cart-empty span { font-size: 0.85rem; }
        .cart-items { flex: 1; overflow-y: auto; padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: 0.85rem; }
        .cart-item { display: flex; gap: 0.75rem; align-items: center; }
        .cart-item-img { width: 48px; height: 48px; flex-shrink: 0; border-radius: var(--radius-sm); border: 1px solid var(--border); overflow: hidden; display: flex; align-items: center; justify-content: center; color: var(--text-muted); background: rgba(255,255,255,0.03); }
        .cart-item-img img { width: 100%; height: 100%; object-fit: cover; }
        .cart-item-info { flex: 1; min-width: 0; }
        .cart-item-name { font-size: 0.85rem; color: var(--text-primary); font-weight: 500; line-height: 1.25; }
        .cart-item-price { font-size: 0.8rem; color: var(--text-secondary); }
        .cart-item-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 0.4rem; }
        .qty-stepper { display: inline-flex; align-items: center; border: 1px solid var(--border); border-radius: var(--radius-sm); }
        .qty-stepper button { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; padding: 0.25rem 0.4rem; display: flex; }
        .qty-stepper span { min-width: 24px; text-align: center; font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
        .cart-item-remove { background: transparent; border: none; color: var(--text-muted); cursor: pointer; display: flex; }
        .cart-item-remove:hover { color: #ef4444; }
        .cart-checkout { padding: 1rem 1.25rem 1.25rem; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 0.65rem; }
        .cart-total { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.25rem; }
        .cart-total span { color: var(--text-secondary); font-size: 0.9rem; }
        .cart-total strong { color: var(--text-primary); font-size: 1.35rem; font-weight: 800; }
        .cart-checkout textarea.premium-input { resize: vertical; font-family: var(--font-sans); }
        .cart-submit { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 0.25rem; }
        .spin { animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
      `}</style>
    </>
  );
}
