import './TiendaCerrada.css';

import { useQuery } from '@tanstack/react-query';
import { Check, Loader2, Lock, LogOut, MessageCircle, Minus } from 'lucide-react';
import { getPublicPlans } from '../../api/public';
import type { PublicPlan } from '../../api/public';
import { useEsAdmin } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';
import type { CierreDeTienda, Tenant } from '../../types';
import {
  contactoDeLaPlataforma,
  enlaceDeContacto,
  mensajeParaActivar,
  mensajeParaReabrir,
} from '../../utils/contactoPlataforma';
import { formatearFecha } from '../../utils/fechas';
import { NOMBRES_LIMITE, ORDEN_LIMITES } from '../../utils/limitesDePlan';

/**
 * Lo que ve el equipo de una tienda cerrada al entrar al panel (FUN-28).
 *
 * Antes entraba igual y cada pantalla fallaba por su cuenta («Error al cargar
 * métricas»), sin decir que la tienda estaba cerrada ni qué hacer. La API ya
 * solo responde `me` y `logout` (el resto, 403 con el motivo), así que esto
 * sustituye al panel entero: no hay ninguna pantalla suya que fuera a funcionar.
 *
 * Qué dice depende de por qué está cerrada (`Tenant::cierre()`): una prueba o un
 * plan vencidos se arreglan eligiendo un plan; una suspensión a mano, hablando
 * con la plataforma. Y de quién mira: pagar lo decide quien administra, así que
 * al resto del equipo solo se le dice a quién avisar.
 *
 * Mientras no haya pasarela (7.7b), pedir un plan es escribirle al operador
 * (`utils/contactoPlataforma.ts`); al registrar él el pago, la tienda se reabre.
 */
export default function TiendaCerrada({ onSalir }: { onSalir: () => void }) {
  const tenant = useTenantStore((s) => s.tenant);
  const cierre = useTenantStore((s) => s.cierre);
  const esAdmin = useEsAdmin();

  const motivo = cierre?.motivo ?? 'suspendida';
  const ofrecePlanes = esAdmin === true && motivo !== 'suspendida';

  const { data: planes, isLoading } = useQuery<PublicPlan[]>({
    queryKey: ['publicPlans'],
    queryFn: getPublicPlans,
    enabled: ofrecePlanes,
    staleTime: 5 * 60 * 1000,
  });

  const contacto = contactoDeLaPlataforma();
  const tienda = { name: tenant?.name ?? 'tu tienda', slug: tenant?.slug ?? '' };
  const { titulo, texto } = textos(cierre, tenant);

  const enlaceSinPlan = enlaceDeContacto(contacto, mensajeParaReabrir(tienda), `Reabrir ${tienda.name}`);

  return (
    <div className="tienda-cerrada">
      <div className="tienda-cerrada-card glass-card">
        <div className="tienda-cerrada-icono">
          <Lock size={26} />
        </div>

        <h1>{titulo}</h1>
        <p className="tienda-cerrada-texto">{texto}</p>
        <LoQueSeGuarda cierre={cierre} tenant={tenant} motivo={motivo} />

        {esAdmin === false && (
          <p className="tienda-cerrada-aviso">
            Avísale a quien administra la tienda: es quien puede elegir un plan y pagarlo.
          </p>
        )}

        {esAdmin === true && motivo === 'suspendida' && (
          enlaceSinPlan ? (
            <a href={enlaceSinPlan} target="_blank" rel="noopener noreferrer" className="btn-primary tienda-cerrada-contacto">
              <MessageCircle size={18} /> Escribir a la plataforma
            </a>
          ) : (
            <p className="tienda-cerrada-aviso">Escríbele a la plataforma para saber por qué y cómo reabrirla.</p>
          )
        )}

        {ofrecePlanes && (
          <section className="tienda-cerrada-planes" aria-label="Planes">
            <h2>Elige un plan</h2>
            <p className="tienda-cerrada-lead">
              {contacto.whatsapp || contacto.correo
                ? 'Escríbenos con el plan que quieres y te decimos cómo pagarlo. Precios en dólares estadounidenses.'
                : 'Escríbele a la plataforma con el plan que quieres para que te diga cómo pagarlo. Precios en dólares estadounidenses.'}
            </p>

            {isLoading || !planes ? (
              <div className="tienda-cerrada-cargando">
                <Loader2 className="spinner" size={24} />
              </div>
            ) : (
              <div className="tienda-cerrada-grid">
                {planes.map((plan) => {
                  const enlace = enlaceDeContacto(contacto, mensajeParaActivar(tienda, plan.label), `Plan ${plan.label} para ${tienda.name}`);
                  const elQueTenia = motivo === 'plan' && plan.label === cierre?.plan;

                  return (
                    <article key={plan.key} className={`tienda-cerrada-plan${elQueTenia ? ' tienda-cerrada-plan-suyo' : ''}`}>
                      {elQueTenia && <span className="tienda-cerrada-plan-badge">Tu plan</span>}
                      <h3>{plan.label}</h3>
                      <p className="tienda-cerrada-precio">
                        US$ {plan.price_usd}<span> /mes + impuestos</span>
                      </p>
                      <ul>
                        {ORDEN_LIMITES.filter((clave) => clave in plan.limits).map((clave) => {
                          const valor = plan.limits[clave];
                          const nombre = NOMBRES_LIMITE[clave] ?? clave;

                          if (typeof valor === 'boolean') {
                            return (
                              <li key={clave} className={valor ? '' : 'tienda-cerrada-no'}>
                                {valor ? <Check size={14} /> : <Minus size={14} />}
                                {nombre}
                              </li>
                            );
                          }

                          return (
                            <li key={clave}>
                              <Check size={14} />
                              {valor === null ? `${nombre}: sin límite` : `${nombre}: hasta ${valor}`}
                            </li>
                          );
                        })}
                      </ul>
                      {enlace && (
                        <a href={enlace} target="_blank" rel="noopener noreferrer" className={elQueTenia ? 'btn-primary' : 'btn-secondary'}>
                          <MessageCircle size={16} /> Pedir el plan {plan.label}
                        </a>
                      )}
                    </article>
                  );
                })}
              </div>
            )}
          </section>
        )}

        <button type="button" className="tienda-cerrada-salir" onClick={onSalir}>
          <LogOut size={16} /> Cerrar sesión
        </button>
      </div>
    </div>
  );
}

/**
 * Qué pasa con sus datos (INF-24). A una prueba que nunca pagó se le borran a
 * los 90 días de cerrarse, y aquí se le dice la fecha —la misma de los dos
 * correos—; a quien pagó alguna vez, o a una tienda suspendida a mano, no se le
 * borra nada.
 */
function LoQueSeGuarda({ cierre, tenant, motivo }: { cierre: CierreDeTienda | null; tenant: Tenant | null; motivo: string }) {
  const zona = tenant?.timezone;

  if (cierre?.datos_borrados) {
    return (
      <p className="tienda-cerrada-texto">
        Pasado el plazo sin elegir un plan, el {formatearFecha(cierre.datos_borrados, zona)} se borraron sus productos,
        pedidos y clientes. Tu cuenta y la tienda siguen: si eliges un plan, vuelve a abrir, vacía.
      </p>
    );
  }

  if (cierre?.borrado_desde) {
    return (
      <p className="tienda-cerrada-texto">
        Tus productos, pedidos y clientes se guardan hasta el <strong>{formatearFecha(cierre.borrado_desde, zona)}</strong>:
        si para entonces no has elegido un plan, se borran, con sus fotos. Si lo eliges antes, la tienda vuelve a
        abrir tal como estaba.
      </p>
    );
  }

  return (
    <p className="tienda-cerrada-texto">
      Tus productos, pedidos y clientes siguen guardados.
      {motivo !== 'suspendida' && ' En cuanto se confirme el pago, la tienda vuelve a abrir tal como estaba.'}
    </p>
  );
}

function textos(cierre: CierreDeTienda | null, tenant: Tenant | null): { titulo: string; texto: string } {
  const nombre = tenant?.name ?? 'tu tienda';
  const fecha = formatearFecha(cierre?.desde, tenant?.timezone);

  switch (cierre?.motivo) {
    case 'prueba':
      return {
        titulo: 'Tu prueba gratuita terminó',
        texto: `${fecha ? `Desde el ${fecha}, el` : 'El'} catálogo de ${nombre} no se ve y el panel está cerrado.`,
      };
    case 'plan':
      return {
        titulo: `Tu plan ${cierre.plan} venció`,
        texto: `${fecha ? `Venció el ${fecha}: el` : 'El'} catálogo de ${nombre} no se ve y el panel está cerrado.`,
      };
    default:
      return {
        titulo: 'Tu tienda está suspendida',
        texto: `La plataforma cerró ${nombre}: el catálogo no se ve y el panel está cerrado.`,
      };
  }
}
