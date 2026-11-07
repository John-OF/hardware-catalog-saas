import './SeguimientoPedidoPage.css';

import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { ArrowLeft, CheckCircle, Clock, Loader2, MessageCircle, Package, Upload, XCircle } from 'lucide-react';
import { enviarComprobante, getPublicTenant, getSeguimiento, resolveTenantDomain } from '../../api/public';
import { mensajeDeError } from '../../api/erroresDeFormulario';
import StoreFooter from '../../components/public/StoreFooter';
import StoreHeader from '../../components/public/StoreHeader';
import Imagen from '../../components/ui/Imagen';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';
import type { SeguimientoDePedido, Tenant } from '../../types';
import { formatearFecha } from '../../utils/fechas';
import { formatMoney } from '../../utils/money';
import { metodosDePagoActivos } from '../../utils/paymentMethods';
import { enlaceDeWhatsapp } from '../../utils/plantillasWhatsapp';
import { aceptaDe, pistaDe, problemaDelArchivo } from '../../utils/subidas';
import { nombreConVariante } from '../../utils/variants';

/** El motivo lo escribe la tienda: si no acaba en punto, se le pone para que la frase siguiente no se pegue. */
const conPunto = (texto: string) => (/[.!?…]$/.test(texto.trim()) ? texto.trim() : `${texto.trim()}.`);

/** Los pasos que ve el comprador, en orden. Cancelado se enseña aparte. */
const PASOS: { estado: SeguimientoDePedido['status']; texto: string }[] = [
  { estado: 'pending', texto: 'Recibido' },
  { estado: 'processing', texto: 'En preparación' },
  { estado: 'attended', texto: 'Listo' },
];

/**
 * El pedido visto por quien lo hizo, sin cuenta (MOD-26).
 *
 * Llega aquí desde su enlace —en su mensaje de WhatsApp, en el correo de
 * confirmación o en lo que le manda la tienda— y justo después de pedir desde
 * el carrito. Ve cómo va su pedido, cómo pagar y manda el comprobante, que la
 * tienda revisa en su panel. Nada de esto cobra: el dinero va directo a la
 * tienda.
 */
export default function SeguimientoPedidoPage() {
  const { slug, token } = useParams<{ slug: string; token: string }>();
  const enDominioPropio = !slug;
  const dominio = window.location.hostname;
  const queryClient = useQueryClient();
  const [archivo, setArchivo] = useState<File | null>(null);

  const { data: tenant, isLoading: cargandoTienda } = useQuery<Tenant>({
    queryKey: ['publicTenant', slug || dominio],
    queryFn: () => (slug ? getPublicTenant(slug) : resolveTenantDomain(dominio)),
  });

  const slugDeLaTienda = tenant?.slug;

  const { data: pedido, isLoading: cargandoPedido, isError } = useQuery<SeguimientoDePedido>({
    queryKey: ['seguimiento', slugDeLaTienda, token],
    queryFn: () => getSeguimiento(slugDeLaTienda!, token!),
    enabled: !!slugDeLaTienda && !!token,
    retry: false,
  });

  useTenantBranding(tenant || null, pedido ? `Pedido #${pedido.number}` : 'Tu pedido');
  useTenantTheme(tenant || null);

  const enviar = useMutation({
    mutationFn: () => enviarComprobante(slugDeLaTienda!, token!, archivo!),
    onSuccess: (actualizado) => {
      queryClient.setQueryData(['seguimiento', slugDeLaTienda, token], actualizado);
      setArchivo(null);
      toast.success('Comprobante enviado. La tienda lo revisará.');
    },
    onError: (err) => toast.error(mensajeDeError(err, { contexto: 'catalogo' })),
  });

  const rutaDelCatalogo = enDominioPropio ? '/' : `/${slugDeLaTienda ?? slug}`;

  if (cargandoTienda || cargandoPedido) {
    return (
      <div className="loading-fullscreen page-seguimiento">
        <Loader2 className="spinner" size={48} />
        <p>Cargando tu pedido...</p>
      </div>
    );
  }

  if (isError || !pedido || !tenant) {
    return (
      <div className="error-fullscreen page-seguimiento">
        <Package size={48} />
        <h3>No encontramos este pedido</h3>
        <p>Revisa que el enlace esté completo. Si el problema sigue, escríbele a la tienda.</p>
        <Link to={rutaDelCatalogo} className="btn-primary">Volver al catálogo</Link>
      </div>
    );
  }

  const money = (n: number | string | null | undefined) => formatMoney(n, tenant.currency);
  const fecha = (iso: string | null | undefined) => formatearFecha(iso, tenant.timezone);
  const metodos = metodosDePagoActivos(tenant.payment_methods);
  const pago = pedido.payment;
  const cancelado = pedido.status === 'cancelled';
  const pasoActual = PASOS.findIndex((p) => p.estado === pedido.status);

  return (
    <div className="public-catalog-container font-family-custom page-seguimiento">
      <StoreHeader tenant={tenant}>
        <Link to={rutaDelCatalogo} className="btn-secondary seguimiento-volver">
          <ArrowLeft size={16} /> Volver al catálogo
        </Link>
        <a href={enlaceDeWhatsapp(tenant.whatsapp_number)} target="_blank" rel="noopener noreferrer" className="btn-primary whatsapp-header-btn">
          <MessageCircle size={18} /> Contactar
        </a>
      </StoreHeader>

      <main className="seguimiento-main">
        <section className="glass-card seguimiento-card">
          <div className="seguimiento-cabecera">
            <div>
              <h1>Pedido #{pedido.number}</h1>
              <p className="seguimiento-muted">Hecho el {fecha(pedido.created_at)} a nombre de {pedido.customer_name}</p>
            </div>
          </div>

          {cancelado ? (
            <p className="seguimiento-estado cancelado">
              <XCircle size={18} /> Este pedido se canceló. Si tienes alguna duda, escríbele a la tienda.
            </p>
          ) : (
            <ol className="seguimiento-pasos">
              {PASOS.map((paso, i) => (
                <li key={paso.estado} className={i <= pasoActual ? 'hecho' : ''}>
                  <span className="seguimiento-punto">{i < pasoActual || pedido.status === 'attended' ? <CheckCircle size={14} /> : i + 1}</span>
                  {paso.texto}
                </li>
              ))}
            </ol>
          )}

          <ul className="seguimiento-lineas">
            {pedido.items.map((linea, i) => (
              <li key={i}>
                <span>{linea.quantity} × {nombreConVariante(linea.product_name, linea.variant_name)}</span>
                <span>{money(linea.subtotal)}</span>
              </li>
            ))}
          </ul>

          <dl className="seguimiento-totales">
            {pedido.discount_amount != null && Number(pedido.discount_amount) > 0 && (
              <div><dt>Descuento{pedido.coupon_code ? ` (${pedido.coupon_code})` : ''}</dt><dd>−{money(pedido.discount_amount)}</dd></div>
            )}
            {pedido.delivery_method === 'delivery' && (
              <div><dt>Envío</dt><dd>{money(pedido.delivery_cost)}</dd></div>
            )}
            {pedido.delivery_method === 'pickup' && (
              <div><dt>Entrega</dt><dd>Recojo en tienda</dd></div>
            )}
            {pedido.tax_rate != null && Number(pedido.tax_amount) > 0 && (
              <div><dt>{pedido.tax_name} ({Number(pedido.tax_rate)} %)</dt><dd>{money(pedido.tax_amount)}</dd></div>
            )}
            <div className="seguimiento-total"><dt>Total</dt><dd>{money(pedido.total)}</dd></div>
          </dl>
        </section>

        {!cancelado && (
          <section className="glass-card seguimiento-card">
            <h2>Pago</h2>

            {pago.status === 'confirmado' && (
              <p className="seguimiento-estado confirmado"><CheckCircle size={18} /> La tienda confirmó tu pago. ¡Gracias!</p>
            )}
            {pago.status === 'en_revision' && (
              <p className="seguimiento-estado revision">
                <Clock size={18} /> Recibimos tu comprobante el {fecha(pago.proof_sent_at)}. La tienda lo está revisando.
              </p>
            )}
            {pago.status === 'rechazado' && (
              <p className="seguimiento-estado rechazado">
                <XCircle size={18} /> La tienda no pudo confirmar tu pago{pago.rejection_reason ? `: ${conPunto(pago.rejection_reason)}` : '.'} Puedes mandar otro comprobante.
              </p>
            )}

            {pago.status !== 'confirmado' && metodos.length > 0 && (
              <>
                <p className="seguimiento-muted">Puedes pagar por:</p>
                <ul className="seguimiento-metodos">
                  {metodos.map((m) => (
                    <li key={m.clave}>
                      <div>
                        <strong>{m.etiqueta}</strong>{m.detalle && ` · ${m.detalle}`}
                        {m.instrucciones && <span className="seguimiento-muted seguimiento-instrucciones">{m.instrucciones}</span>}
                      </div>
                      {m.qr && (
                        <a href={m.qr} target="_blank" rel="noopener noreferrer" className="seguimiento-qr" title={`Ver el QR de ${m.etiqueta}`}>
                          <Imagen src={m.qr} alt={`QR de ${m.etiqueta}`} loading="lazy" />
                        </a>
                      )}
                    </li>
                  ))}
                </ul>
              </>
            )}

            {pago.can_upload && (
              <div className="seguimiento-subir">
                <label htmlFor="seguimiento-archivo">
                  {pago.status === 'en_revision' ? '¿Te equivocaste de archivo? Manda otro' : 'Manda tu comprobante de pago'}
                  <span className="seguimiento-muted"> ({pistaDe('comprobante')})</span>
                </label>
                <input
                  id="seguimiento-archivo"
                  type="file"
                  accept={aceptaDe('comprobante')}
                  className="premium-input"
                  onChange={(e) => {
                    const elegido = e.target.files?.[0] ?? null;
                    const problema = elegido ? problemaDelArchivo(elegido, 'comprobante') : null;
                    if (problema) {
                      toast.error(problema);
                      e.target.value = '';
                      setArchivo(null);
                      return;
                    }
                    setArchivo(elegido);
                  }}
                />
                <button type="button" className="btn-primary" disabled={!archivo || enviar.isPending} onClick={() => enviar.mutate()}>
                  {enviar.isPending ? <Loader2 size={16} className="spinner" /> : <Upload size={16} />} Enviar comprobante
                </button>
              </div>
            )}
          </section>
        )}
      </main>

      <StoreFooter tenant={tenant} buildPath={(ruta) => (enDominioPropio ? ruta : `/${tenant.slug}${ruta}`)} />
    </div>
  );
}
