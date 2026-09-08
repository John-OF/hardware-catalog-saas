import './VerifyEmailBanner.css';

import { useState } from 'react';
import { toast } from 'react-hot-toast';
import { MailWarning, Loader2 } from 'lucide-react';
import { resendVerificationEmail } from '../../api/auth';
import { useAuthStore } from '../../stores/authStore';

/**
 * Aviso de "confirma tu correo" del panel (FUN-5).
 *
 * Existe porque sin verificar el catálogo público no se ve, y esa es una
 * consecuencia que el dueño no puede adivinar: por dentro el panel funciona
 * entero —productos, categorías, personalización— así que sin este aviso la
 * única señal sería que su enlace da 404 cuando se lo pasa a un cliente.
 *
 * Se pinta encima de todas las pantallas del panel y no solo del resumen: quien
 * está subiendo productos no vuelve al inicio a mirar si hay algo pendiente.
 */
export default function VerifyEmailBanner() {
  const user = useAuthStore((s) => s.user);
  const setAuth = useAuthStore((s) => s.setAuth);
  const token = useAuthStore((s) => s.token);

  const [isSending, setIsSending] = useState(false);

  // `=== false` y no `!user.email_verified`: el campo es opcional en el tipo
  // (lo comparte con el cliente del catálogo, al que no se le pide verificar),
  // así que mientras `getMe()` no ha respondido vale `undefined` y el aviso no
  // debe parpadear en pantalla para desaparecer un instante después.
  if (!user || user.email_verified !== false) {
    return null;
  }

  const handleResend = async () => {
    setIsSending(true);
    try {
      const data = await resendVerificationEmail();
      toast.success(data.message);

      // Si el correo ya estaba verificado —lo confirmó en otra pestaña— el
      // aviso se quita solo en vez de quedarse mintiendo hasta el próximo
      // refresco.
      if (data.verified && token) {
        setAuth(token, { ...user, email_verified: true });
      }
    } catch (error: any) {
      console.error(error);
      const message =
        error.response?.status === 429
          ? 'Acabas de pedir un correo. Espera un minuto antes de volver a intentarlo.'
          : error.response?.data?.message || 'No pudimos reenviar el correo. Inténtalo de nuevo en un momento.';
      toast.error(message);
    } finally {
      setIsSending(false);
    }
  };

  return (
    <div className="verify-email-banner" role="status">
      <MailWarning size={20} className="verify-email-banner-icon" />

      <div className="verify-email-banner-text">
        <strong>Confirma tu correo para publicar la tienda.</strong>
        <span>
          Te enviamos un enlace a <b>{user.email}</b>. Hasta que lo abras, tu catálogo no es visible
          para el público — pero puedes seguir configurándolo todo desde aquí.
        </span>
      </div>

      <button
        type="button"
        className="btn-secondary verify-email-banner-btn"
        onClick={handleResend}
        disabled={isSending}
      >
        {isSending ? <Loader2 size={16} className="verify-email-banner-spin" /> : null}
        <span>{isSending ? 'Enviando...' : 'Reenviar correo'}</span>
      </button>
    </div>
  );
}
