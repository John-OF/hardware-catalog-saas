import './TrialBanner.css';

import { useState } from 'react';
import { Clock } from 'lucide-react';
import { useTenantStore } from '../../stores/tenantStore';
import {
  contactoDeLaPlataforma,
  enlaceDeContacto,
  mensajeParaActivar,
  mensajeParaRenovar,
} from '../../utils/contactoPlataforma';
import { diasDeCalendarioHasta, formatearFecha } from '../../utils/fechas';

/**
 * Cuánto queda de la prueba (FUN-16) o del plan pagado (INF-23).
 *
 * Sin esto, una tienda se cerraría de golpe el día que corra
 * `trials:cerrar-vencidas`, sin que su dueño hubiera visto venir nada — el
 * mismo tipo de sorpresa que `VerifyEmailBanner` ya evita para el correo sin
 * confirmar. La prueba avisa desde el primer día (dura siete); el plan, solo en
 * su última semana (`Membresia::DIAS_DE_AVISO` en la API), porque un aviso que
 * está siempre puesto deja de leerse.
 *
 * Sólo lee las fechas de la tienda: la fecha manda, no un contador propio que
 * pudiera desincronizarse de lo que de verdad va a cerrar la tienda.
 */
const DIAS_DE_AVISO_DEL_PLAN = 7;

export default function TrialBanner() {
  const tenant = useTenantStore((s) => s.tenant);
  const trialEndsAt = tenant?.trial_ends_at;
  const planEndsAt = tenant?.plan_ends_at;

  const zona = tenant?.timezone;

  // Leer el reloj en el cuerpo del componente es impuro (la regla de pureza
  // del render lo marca). Con un inicializador perezoso se lee una sola vez, al
  // montar el panel: el aviso no necesita actualizarse segundo a segundo. Antes
  // iba en un efecto que guardaba el resultado en estado, y eso pintaba dos
  // veces cada carga.
  const [ahora] = useState(() => new Date());
  const aviso = calcularAviso(trialEndsAt, planEndsAt, zona, ahora);

  if (!aviso || !tenant) {
    return null;
  }

  // Los últimos días cambian de tono: es el mismo aviso, pero ya no es "de aquí
  // a un rato".
  const urgente = aviso.dias <= 2;
  const enlace =
    aviso.tipo === 'prueba'
      ? enlaceDeContacto(contactoDeLaPlataforma(), mensajeParaActivar(tenant), `Elegir un plan para ${tenant.name}`)
      : enlaceDeContacto(contactoDeLaPlataforma(), mensajeParaRenovar(tenant), `Renovar el plan de ${tenant.name}`);

  const titulo =
    aviso.tipo === 'prueba'
      ? aviso.dias === 1 ? 'Tu prueba termina mañana.' : `Te quedan ${aviso.dias} días de prueba.`
      : aviso.dias === 0
        ? 'Tu plan vence hoy.'
        : aviso.dias === 1
          ? 'Tu plan vence mañana.'
          : `Tu plan vence en ${aviso.dias} días (el ${formatearFecha(planEndsAt, zona)}).`;

  const texto =
    aviso.tipo === 'prueba'
      ? 'Escríbenos para activar tu plan antes de que termine y tu tienda no se cierre.'
      : 'Escríbenos para renovarlo antes de que venza y tu tienda no se cierre.';

  return (
    <div className={`trial-banner${urgente ? ' trial-banner-urgente' : ''}`} role="status">
      <Clock size={20} className="trial-banner-icon" />
      <p>
        <strong>{titulo}</strong> {texto}
      </p>
      {enlace && (
        <a href={enlace} target="_blank" rel="noopener noreferrer" className="btn-secondary trial-banner-enlace">
          Escribirnos
        </a>
      )}
    </div>
  );
}

/** Qué avisar y cuántos días quedan, o `null` si no toca avisar. Pura: el reloj llega en `ahora`. */
function calcularAviso(
  trialEndsAt: string | null | undefined,
  planEndsAt: string | null | undefined,
  zona: string | null | undefined,
  ahora: Date,
): { tipo: 'prueba' | 'plan'; dias: number } | null {
  // La prueba se cuenta en tramos de 24 horas desde el alta, como siempre: nace
  // con 7 días justos. Vencida, mejor nada que "0 días" o un negativo: en cuanto
  // corra el cierre automático, su equipo ve la pantalla de tienda cerrada
  // (FUN-28).
  if (trialEndsAt) {
    const dias = Math.ceil((new Date(trialEndsAt).getTime() - ahora.getTime()) / (1000 * 60 * 60 * 24));
    return dias > 0 ? { tipo: 'prueba', dias } : null;
  }

  // El plan, en días de calendario de la tienda: «vence el 5» tiene que cuadrar
  // con «en 4 días» leído el día 1, y el último día dice «hoy».
  if (planEndsAt && new Date(planEndsAt).getTime() > ahora.getTime()) {
    const dias = diasDeCalendarioHasta(planEndsAt, zona, ahora);
    return dias !== null && dias <= DIAS_DE_AVISO_DEL_PLAN ? { tipo: 'plan', dias } : null;
  }

  return null;
}
