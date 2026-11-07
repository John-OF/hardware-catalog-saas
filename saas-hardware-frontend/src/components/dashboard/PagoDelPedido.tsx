import './PagoDelPedido.css';

import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { CheckCircle, Clock, Copy, FileText, Loader2, XCircle } from 'lucide-react';
import { getComprobante, revisarPago } from '../../api/orders';
import { avisarErrorEnSesion } from '../../api/erroresDeFormulario';
import Imagen from '../ui/Imagen';
import type { Order, Tenant } from '../../types';
import { formatearFechaHora } from '../../utils/fechas';
import { enlaceDeSeguimiento } from '../../utils/plantillasWhatsapp';

interface PagoDelPedidoProps {
  pedido: Order;
  tienda: Tenant | null;
  /** Si quien mira puede confirmar o rechazar (`pedidos.gestionar`). */
  puedeGestionar: boolean;
  onActualizado: (pedido: Order) => void;
}

/** Cómo se llama en el panel cada estado del pago (MOD-26). */
export const etiquetaDePago = (estado: Order['payment_status']): string | null =>
  estado === 'en_revision' ? 'Pago en revisión'
    : estado === 'confirmado' ? 'Pago confirmado'
      : estado === 'rechazado' ? 'Pago rechazado'
        : null;

/**
 * El pago de un pedido en su detalle (MOD-26): el comprobante que mandó el
 * comprador desde su enlace, y confirmar o rechazar. Va aparte del estado del
 * pedido, que es lo que mueve el stock: confirmar el pago no lo atiende.
 *
 * El comprobante se pide al pulsar y se enseña aquí mismo, no en otra pestaña:
 * abrir una ventana después de esperar una petición lo bloquea el navegador.
 */
export default function PagoDelPedido({ pedido, tienda, puedeGestionar, onActualizado }: PagoDelPedidoProps) {
  const [vista, setVista] = useState<{ url: string; tipo: string } | null>(null);
  const [cargando, setCargando] = useState(false);
  const [rechazando, setRechazando] = useState(false);
  const [motivo, setMotivo] = useState('');
  const enlace = enlaceDeSeguimiento(tienda, pedido.tracking_token);
  const vivo = pedido.status !== 'cancelled';

  // El blob se suelta al cambiar de pedido o al cerrar: si no, se acumulan en memoria.
  useEffect(() => () => { if (vista) URL.revokeObjectURL(vista.url); }, [vista]);
  useEffect(() => { setVista(null); setRechazando(false); setMotivo(''); }, [pedido.id]);

  const verComprobante = async () => {
    setCargando(true);
    try {
      const blob = await getComprobante(pedido.id);
      setVista({ url: URL.createObjectURL(blob), tipo: blob.type });
    } catch (err) {
      avisarErrorEnSesion(err, 'No se pudo abrir el comprobante.');
    } finally {
      setCargando(false);
    }
  };

  const revisar = useMutation({
    mutationFn: (decision: 'confirmar' | 'rechazar') => revisarPago(pedido.id, decision, decision === 'rechazar' ? motivo.trim() : undefined),
    onSuccess: (actualizado, decision) => {
      toast.success(decision === 'confirmar' ? 'Pago confirmado' : 'Comprobante rechazado: el cliente lo verá en su enlace');
      setRechazando(false);
      setMotivo('');
      onActualizado(actualizado);
    },
    onError: (err) => avisarErrorEnSesion(err, 'No se pudo guardar la revisión del pago.'),
  });

  const copiarEnlace = async () => {
    try {
      await navigator.clipboard.writeText(enlace);
      toast.success('Enlace copiado');
    } catch {
      toast.error('No se pudo copiar. Selecciónalo y cópialo a mano.');
    }
  };

  return (
    <div className="pago-del-pedido">
      <h4>Pago</h4>

      {pedido.payment_status === 'en_revision' && (
        <p className="pago-estado revision">
          <Clock size={15} /> El cliente mandó su comprobante el {formatearFechaHora(pedido.payment_proof_uploaded_at, tienda?.timezone)}. Revísalo en tu banco y confírmalo.
        </p>
      )}
      {pedido.payment_status === 'confirmado' && (
        <p className="pago-estado confirmado">
          <CheckCircle size={15} /> Confirmaste el pago el {formatearFechaHora(pedido.payment_reviewed_at, tienda?.timezone)}.
        </p>
      )}
      {pedido.payment_status === 'rechazado' && (
        <p className="pago-estado rechazado">
          <XCircle size={15} /> Rechazaste el comprobante: {pedido.payment_rejection_reason}. El cliente puede mandar otro.
        </p>
      )}
      {!pedido.payment_status && (
        <p className="pago-estado">Todavía no hay comprobante. El cliente puede mandarlo desde su enlace.</p>
      )}

      {pedido.tiene_comprobante && !vista && (
        <button type="button" className="btn-secondary btn-small" onClick={verComprobante} disabled={cargando}>
          {cargando ? <Loader2 size={14} className="spinner" /> : <FileText size={14} />} Ver comprobante
        </button>
      )}

      {vista && (
        <div className="pago-comprobante">
          {vista.tipo === 'application/pdf' ? (
            <iframe src={vista.url} title={`Comprobante del pedido #${pedido.number}`} />
          ) : (
            <Imagen src={vista.url} alt={`Comprobante del pedido #${pedido.number}`} />
          )}
          <a href={vista.url} download={`comprobante-pedido-${pedido.number}`} className="pago-descargar">Descargar</a>
        </div>
      )}

      {puedeGestionar && vivo && (
        <div className="pago-acciones">
          {pedido.payment_status !== 'confirmado' && (
            <button type="button" className="btn-primary btn-small" disabled={revisar.isPending} onClick={() => revisar.mutate('confirmar')}>
              <CheckCircle size={14} /> Confirmar pago
            </button>
          )}
          {pedido.payment_status === 'en_revision' && !rechazando && (
            <button type="button" className="btn-secondary btn-small" onClick={() => setRechazando(true)}>
              <XCircle size={14} /> Rechazar comprobante
            </button>
          )}
        </div>
      )}

      {rechazando && (
        <div className="pago-rechazo">
          <label htmlFor="pago-motivo">¿Por qué? Lo verá el cliente en su enlace.</label>
          <textarea
            id="pago-motivo"
            className="premium-input"
            rows={2}
            maxLength={300}
            placeholder="Ej.: el monto no coincide con el total del pedido"
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
          />
          <div className="pago-acciones">
            <button type="button" className="btn-secondary btn-small" onClick={() => setRechazando(false)}>Cancelar</button>
            <button type="button" className="btn-primary btn-small" disabled={!motivo.trim() || revisar.isPending} onClick={() => revisar.mutate('rechazar')}>
              Rechazar
            </button>
          </div>
        </div>
      )}

      {enlace && (
        <div className="pago-enlace">
          <span>Enlace del cliente para ver su pedido y mandar el comprobante:</span>
          <div>
            <code>{enlace}</code>
            <button type="button" className="btn-secondary btn-small" onClick={copiarEnlace} title="Copiar el enlace">
              <Copy size={13} /> Copiar
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
