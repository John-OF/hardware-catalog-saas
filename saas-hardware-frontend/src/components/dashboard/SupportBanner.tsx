import './SupportBanner.css';

import { useNavigate } from 'react-router-dom';
import { Eye, LogOut } from 'lucide-react';
import { logoutUser } from '../../api/auth';
import { useAuthStore } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';

/**
 * Aviso de "estás en una tienda ajena" (INF-2).
 *
 * Cuando el operador entra como soporte, el panel que ve es exactamente el del
 * dueño: mismo menú, mismos botones. Sin este aviso no habría forma de saber en
 * casa de quién está —ni de saber por qué todo lo que intente guardar va a
 * responder 403—, y ese es justo el error que convierte una sesión de soporte
 * en un incidente.
 *
 * Dice las tres cosas que hacen falta: en qué tienda está, que solo puede
 * mirar, y cómo salir.
 *
 * Quién decide si se pinta es el TOKEN, no el navegador: la marca viene de
 * `GET /auth/me`, que la calcula de las abilities. Si alguien tocara el estado
 * del navegador para esconder el aviso, seguiría sin poder escribir.
 */
export default function SupportBanner() {
  const navigate = useNavigate();
  const esSoporte = useAuthStore((s) => s.soporte);
  const tenant = useTenantStore((s) => s.tenant);

  if (!esSoporte) return null;

  const salir = async () => {
    try {
      // El logout es la única escritura que el modo soporte tiene permitida, a
      // propósito: revocar la llave prestada antes de que caduque deja la
      // tienda más protegida, no menos.
      await logoutUser();
    } catch {
      // Si el token ya caducó, el 401 aquí da igual: se sale igualmente.
    }

    // Solo las claves del panel de tienda: la sesión de plataforma vive en
    // otra clave de sessionStorage y es justo a la que se vuelve (ver
    // `clearPanelSession` en authStore).
    useAuthStore.getState().clearPanelSession();
    useTenantStore.getState().clearTenant();

    navigate('/platform/tenants', { replace: true });
  };

  return (
    <div className="support-banner">
      <Eye size={16} />
      <p>
        <strong>Modo soporte</strong> — estás viendo {tenant ? `«${tenant.name}»` : 'esta tienda'} como
        su administrador. <strong>Solo lectura</strong>: nada de lo que cambies se guardará. La sesión
        caduca sola.
      </p>
      <button type="button" className="btn-secondary btn-support-exit" onClick={salir}>
        <LogOut size={14} /> Salir del modo soporte
      </button>
    </div>
  );
}
