import './TrialBanner.css';

import { useEffect, useState } from 'react';
import { Clock } from 'lucide-react';
import { useTenantStore } from '../../stores/tenantStore';

/**
 * Aviso de cuánto queda del período de prueba (FUN-16).
 *
 * Sin esto, una tienda en prueba se cerraría de golpe el día que corra
 * `trials:cerrar-vencidas`, sin que su dueño hubiera visto venir nada — el
 * mismo tipo de sorpresa que `VerifyEmailBanner` ya evita para el correo sin
 * confirmar.
 *
 * Sólo lee `tenant.trial_ends_at`: la fecha manda, no un contador propio que
 * pudiera desincronizarse de lo que de verdad va a cerrar la tienda.
 */
export default function TrialBanner() {
  const tenant = useTenantStore((s) => s.tenant);
  const trialEndsAt = tenant?.trial_ends_at;

  // `Date.now()` es impura, así que no puede leerse directo en el cuerpo del
  // componente (ni dentro de un `useMemo`: la regla de pureza del render lo
  // sigue marcando). Va en un efecto, como cualquier otra lectura del mundo
  // exterior -no hace falta que el número se actualice segundo a segundo,
  // basta con que se calcule una vez por cada `trialEndsAt` distinto-.
  const [diasRestantes, setDiasRestantes] = useState<number | null>(null);

  useEffect(() => {
    if (!trialEndsAt) {
      setDiasRestantes(null);
      return;
    }

    setDiasRestantes(Math.ceil((new Date(trialEndsAt).getTime() - Date.now()) / (1000 * 60 * 60 * 24)));
  }, [trialEndsAt]);

  if (diasRestantes === null) {
    return null;
  }

  // Vencida: en cuanto corra el cierre automático la tienda queda suspendida
  // y nadie llega a ver esto. Mientras tanto, mejor nada que "0 días" o un
  // número negativo.
  if (diasRestantes <= 0) {
    return null;
  }

  // Los últimos dos días cambian de tono: es la misma prueba, pero ya no es
  // "de aquí a un rato".
  const urgente = diasRestantes <= 2;

  return (
    <div className={`trial-banner${urgente ? ' trial-banner-urgente' : ''}`} role="status">
      <Clock size={20} className="trial-banner-icon" />
      <p>
        <strong>
          {diasRestantes === 1 ? 'Tu prueba termina mañana.' : `Te quedan ${diasRestantes} días de prueba.`}
        </strong>{' '}
        Escríbenos para activar tu plan antes de que termine y tu tienda no se cierre.
      </p>
    </div>
  );
}
