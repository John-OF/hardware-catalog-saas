import './VerifyEmailBanner.css';

import { useState } from 'react';
import { toast } from 'react-hot-toast';
import { MailWarning, Loader2 } from 'lucide-react';
import { resendVerificationEmail } from '../../api/auth';
import { useAuthStore } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';

/**
 * Aviso de "confirma tu correo" del panel (FUN-5).
 *
 * Existe porque para una tienda nueva el catálogo público no se ve hasta
 * verificar, y esa es una consecuencia que el dueño no puede adivinar: por
 * dentro el panel funciona entero —productos, categorías, personalización— así
 * que sin este aviso la única señal sería que su enlace da 404 cuando se lo pasa
 * a un cliente.
 *
 * **Pero el aviso NO puede deducir eso del correo, y durante un tiempo lo hizo.**
 * `tenants.is_published` nace en `true` a propósito (ver la migración de FUN-5):
 * de lo contrario, aplicarla habría apagado todas las tiendas que ya existían.
 * O sea que una tienda anterior a FUN-5 **es pública aunque su dueño no haya
 * verificado nunca**, y a esa persona este aviso le decía que su catálogo no se
 * veía mientras cualquiera podía entrar en él. Un aviso que miente sobre lo más
 * importante —si tu tienda está abierta o cerrada— es peor que no tenerlo.
 *
 * Por eso hay dos textos: quien sí está cerrado necesita saber cómo abrir, y
 * quien está abierto necesita saber para qué sirve verificar de todas formas.
 * La pregunta "¿mi tienda se ve?" la contesta `is_published`, y sólo esa.
 *
 * Se pinta encima de todas las pantallas del panel y no solo del resumen: quien
 * está subiendo productos no vuelve al inicio a mirar si hay algo pendiente.
 */
export default function VerifyEmailBanner() {
  const user = useAuthStore((s) => s.user);
  const tenant = useTenantStore((s) => s.tenant);
  const setAuth = useAuthStore((s) => s.setAuth);
  const token = useAuthStore((s) => s.token);
  const esSoporte = useAuthStore((s) => s.soporte);

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

  // `=== false` otra vez, y por lo mismo: mientras el tenant no ha llegado no se
  // puede afirmar que la tienda esté cerrada.
  //
  // Y solo cuenta para un admin: desde FUN-4 verificar el correo de un
  // colaborador no publica nada (ver `AuthController::confirmarCorreo`), asi
  // que prometerle "confirma para publicar" seria volver a mentir.
  const tiendaCerrada = tenant?.is_published === false && user.role === 'admin';

  // INF-2: en una sesión de soporte quien mira es el operador, no el dueño. El
  // dato sí le sirve —suele ser la respuesta a "mi tienda no se ve"—, pero
  // contado en tercera persona y sin el botón: reenviar es una escritura y la
  // sesión de soporte responde 403 a todas.
  if (esSoporte) {
    return (
      <div className="verify-email-banner" role="status">
        <MailWarning size={20} className="verify-email-banner-icon" />
        <div className="verify-email-banner-text">
          <strong>El administrador no ha confirmado su correo.</strong>
          <span>
            {tiendaCerrada
              ? <>Por eso el catálogo no es público: se abre cuando <b>{user.email}</b> abra el enlace de verificación.</>
              : <><b>{user.email}</b> sigue sin verificar. La tienda ya es pública, pero no hay garantía de que le lleguen los avisos ni la recuperación de contraseña.</>}
          </span>
        </div>
      </div>
    );
  }

  return (
    <div className="verify-email-banner" role="status">
      <MailWarning size={20} className="verify-email-banner-icon" />

      <div className="verify-email-banner-text">
        {tiendaCerrada ? (
          <>
            <strong>Confirma tu correo para publicar la tienda.</strong>
            <span>
              Te enviamos un enlace a <b>{user.email}</b>. Hasta que lo abras, tu catálogo no es
              visible para el público — pero puedes seguir configurándolo todo desde aquí.
            </span>
          </>
        ) : (
          <>
            <strong>Confirma tu correo.</strong>
            <span>
              Te enviamos un enlace a <b>{user.email}</b>. Tu tienda ya es pública, pero sin
              confirmarlo no podemos asegurarte los avisos de pedidos ni devolverte el acceso si
              pierdes la contraseña.
            </span>
          </>
        )}
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
