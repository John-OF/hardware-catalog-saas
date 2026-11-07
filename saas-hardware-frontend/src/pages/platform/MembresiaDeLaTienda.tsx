import './MembresiaDeLaTienda.css';

import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { CalendarCheck, Loader2, Receipt } from 'lucide-react';
import { avisarErrorEnSesion } from '../../api/erroresDeFormulario';
import { registrarPago, updatePlatformTenant } from '../../api/platform';
import type { MembresiaDeTienda, PlatformTenant } from '../../api/platform';
import { formatearFecha } from '../../utils/fechas';
import { formatMoney } from '../../utils/money';

/**
 * El plan pagado de una tienda, en su ficha del operador (INF-23).
 *
 * Mientras no haya pasarela (7.7b), cobrar es esto: el dueño paga por fuera (una
 * transferencia, efectivo) y el operador lo apunta aquí. Apuntarlo mueve el
 * «pagado hasta», saca a la tienda de la prueba y, si estaba cerrada, la reabre.
 * Las cuentas las hace la API (`App\Support\Membresia`); aquí no hay ninguna
 * copia de la regla de los meses, y por eso «los meses cuentan desde» llega
 * calculado en `membresia.desde`.
 *
 * Los pagos no se editan ni se borran. Si uno se apuntó mal, se corrige la fecha
 * de fin aparte, y la corrección queda en la bitácora.
 */
export default function MembresiaDeLaTienda({
  tenant,
  membresia,
  onCambio,
}: {
  tenant: PlatformTenant;
  membresia: MembresiaDeTienda;
  onCambio: () => void;
}) {
  const zona = tenant.timezone;
  const planInicial = membresia.planes.find((p) => p.key === tenant.plan) ?? membresia.planes[0];

  const [plan, setPlan] = useState(planInicial?.key ?? '');
  const [meses, setMeses] = useState(1);
  const [monto, setMonto] = useState(String(planInicial?.price_usd ?? ''));
  const [medio, setMedio] = useState('transferencia');
  // La fecha de hoy del navegador, para el valor por defecto y el tope del
  // campo. Con inicializador perezoso: se lee el reloj una vez, al montar, y no
  // en cada render.
  const [hoy] = useState(fechaDeHoy);
  const [pagadoEl, setPagadoEl] = useState(hoy);
  const [referencia, setReferencia] = useState('');
  const [nota, setNota] = useState('');
  const [nuevoFin, setNuevoFin] = useState('');

  /** El monto que se propone: precio del plan × meses, o 0 si es una cortesía. El operador lo puede cambiar. */
  const proponerMonto = (clave: string, cuantos: number, conMedio: string) => {
    const precio = membresia.planes.find((p) => p.key === clave)?.price_usd ?? 0;
    setMonto(conMedio === 'cortesia' ? '0' : String(precio * cuantos));
  };

  const pagoMutation = useMutation({
    mutationFn: () =>
      registrarPago(tenant.id, {
        plan,
        months: meses,
        amount_usd: Number(monto),
        method: medio,
        reference: referencia.trim() || undefined,
        notes: nota.trim() || undefined,
        paid_on: pagadoEl,
      }),
    onSuccess: (res) => {
      toast.success(res.message);
      setReferencia('');
      setNota('');
      onCambio();
    },
    onError: (error) => avisarErrorEnSesion(error, 'No se pudo registrar el pago.'),
  });

  const corregirMutation = useMutation({
    mutationFn: () => updatePlatformTenant(tenant.id, { plan_ends_at: nuevoFin }),
    onSuccess: () => {
      toast.success('Fecha corregida.');
      setNuevoFin('');
      onCambio();
    },
    onError: (error) => avisarErrorEnSesion(error, 'No se pudo corregir la fecha.'),
  });

  const estado = (() => {
    if (tenant.trial_ends_at) {
      return `En prueba hasta el ${formatearFecha(tenant.trial_ends_at, zona)}. Nunca ha pagado.`;
    }
    if (tenant.plan_ends_at) {
      return `Pagado hasta el ${formatearFecha(tenant.plan_ends_at, zona)}.`;
    }
    return membresia.pagos.length === 0
      ? 'Sin fecha de fin: tiene el plan desde antes de que se registraran pagos.'
      : 'Sin fecha de fin.';
  })();

  const cierre = membresia.cierre;

  return (
    <section className="glass-card tenant-card membresia">
      <h3>
        <Receipt size={16} /> Plan pagado
      </h3>

      <p className="membresia-estado">{estado}</p>
      {cierre && (
        <p className="membresia-cierre">
          {cierre.motivo === 'prueba' && `Cerrada: terminó su prueba el ${formatearFecha(cierre.desde, zona)}. Registrar un pago la reabre.`}
          {cierre.motivo === 'plan' && `Cerrada: su plan ${cierre.plan} venció el ${formatearFecha(cierre.desde, zona)}. Registrar un pago la reabre.`}
          {cierre.motivo === 'suspendida' && 'Suspendida a mano. Registrar un pago también la reabre.'}
        </p>
      )}
      {/* INF-24: lo que hará o hizo `pruebas:purgar` con una prueba que nunca pagó. */}
      {cierre?.borrado_desde && (
        <p className="membresia-cierre">
          Nunca ha pagado: sus productos, pedidos y clientes se borran el {formatearFecha(cierre.borrado_desde, zona)} si
          no se registra un pago antes.
        </p>
      )}
      {cierre?.datos_borrados && (
        <p className="membresia-cierre">
          Sus productos, pedidos y clientes se borraron el {formatearFecha(cierre.datos_borrados, zona)}, al pasar el
          plazo sin pagar. Si paga, la tienda vuelve a abrir vacía.
        </p>
      )}

      <form
        className="membresia-form"
        onSubmit={(e) => {
          e.preventDefault();
          pagoMutation.mutate();
        }}
      >
        <h4>Registrar un pago</h4>

        <div className="membresia-campos">
          <label>
            Plan
            <select
              className="premium-input"
              value={plan}
              onChange={(e) => {
                setPlan(e.target.value);
                proponerMonto(e.target.value, meses, medio);
              }}
            >
              {membresia.planes.map((p) => (
                <option key={p.key} value={p.key}>
                  {p.label} (US$ {p.price_usd}/mes)
                </option>
              ))}
            </select>
          </label>

          <label>
            Meses
            <input
              className="premium-input"
              type="number"
              min={1}
              max={membresia.meses_max}
              value={meses}
              onChange={(e) => {
                const cuantos = Math.max(1, Math.min(membresia.meses_max, Number(e.target.value) || 1));
                setMeses(cuantos);
                proponerMonto(plan, cuantos, medio);
              }}
            />
          </label>

          <label>
            Monto (USD)
            <input
              className="premium-input"
              type="number"
              min={0}
              step="0.01"
              value={monto}
              onChange={(e) => setMonto(e.target.value)}
            />
          </label>

          <label>
            Medio
            <select
              className="premium-input"
              value={medio}
              onChange={(e) => {
                setMedio(e.target.value);
                proponerMonto(plan, meses, e.target.value);
              }}
            >
              {Object.entries(membresia.medios).map(([clave, nombre]) => (
                <option key={clave} value={clave}>{nombre}</option>
              ))}
            </select>
          </label>

          <label>
            Fecha del pago
            <input
              className="premium-input"
              type="date"
              max={hoy}
              value={pagadoEl}
              onChange={(e) => setPagadoEl(e.target.value)}
              required
            />
          </label>

          <label>
            Referencia
            <input
              className="premium-input"
              type="text"
              maxLength={120}
              placeholder="N.º de operación"
              value={referencia}
              onChange={(e) => setReferencia(e.target.value)}
            />
          </label>
        </div>

        <label className="membresia-nota">
          Nota
          <input
            className="premium-input"
            type="text"
            maxLength={500}
            placeholder="Opcional"
            value={nota}
            onChange={(e) => setNota(e.target.value)}
          />
        </label>

        <div className="membresia-pie">
          <span>
            Los meses cuentan desde el {formatearFecha(membresia.desde, zona)}
            {medio === 'cortesia' ? ', sin cobro.' : '.'}
          </span>
          <button type="submit" className="btn-primary btn-mini" disabled={pagoMutation.isPending || !plan || !pagadoEl}>
            {pagoMutation.isPending ? <Loader2 size={14} className="spinner" /> : <Receipt size={14} />}
            Registrar pago
          </button>
        </div>
      </form>

      {/* Solo con fecha: a una tienda sin pagos, poner fecha sería darle un
          plan sin cobrarlo, que es lo que INF-23 quita. La API lo rechaza igual. */}
      {tenant.plan_ends_at && (
        <form
          className="membresia-corregir"
          onSubmit={(e) => {
            e.preventDefault();
            corregirMutation.mutate();
          }}
        >
          <label>
            Corregir el «pagado hasta»
            <input
              className="premium-input"
              type="date"
              min={hoy}
              value={nuevoFin}
              onChange={(e) => setNuevoFin(e.target.value)}
              required
            />
          </label>
          <button type="submit" className="btn-secondary btn-mini" disabled={corregirMutation.isPending || !nuevoFin}>
            {corregirMutation.isPending ? <Loader2 size={14} className="spinner" /> : <CalendarCheck size={14} />}
            Corregir
          </button>
          <span className="membresia-ayuda">Para un pago mal apuntado. Queda en la bitácora.</span>
        </form>
      )}

      <h4>Pagos</h4>
      {membresia.pagos.length === 0 ? (
        <p className="tenant-muted">Todavía no se ha registrado ningún pago.</p>
      ) : (
        <ul className="membresia-pagos">
          {membresia.pagos.map((p) => (
            <li key={p.id}>
              <span>
                <strong>{membresia.planes.find((x) => x.key === p.plan)?.label ?? p.plan}</strong>
                {' · '}
                {p.months === 1 ? '1 mes' : `${p.months} meses`}
                {' · '}
                {p.method === 'cortesia' ? 'cortesía' : formatMoney(Number(p.amount_usd), 'USD')}
                {' · '}
                {membresia.medios[p.method] ?? p.method}
                {p.reference && ` (${p.reference})`}
              </span>
              <span className="membresia-pago-meta">
                {/* `paid_on` es un día sin hora: pintado en UTC para que no retroceda un día. */}
                Pagado el {formatearFecha(p.paid_on, 'UTC')} · cubre hasta el {formatearFecha(p.ends_at, zona)} ·{' '}
                {p.registered_by_email ?? 'operador borrado'}
              </span>
              {p.notes && <span className="membresia-pago-meta">{p.notes}</span>}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** Hoy en el calendario del navegador, como `AAAA-MM-DD` (lo que pide un `<input type="date">`). */
function fechaDeHoy(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
