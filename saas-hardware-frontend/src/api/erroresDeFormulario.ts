import { isAxiosError } from 'axios';
import { toast } from 'react-hot-toast';

/**
 * Desde qué parte de la app se envió el formulario. Cambia qué significa un 403
 * o un 404, no el resto:
 * - `plataforma`: antes de autenticarse, el único 403 es la cerca de IP.
 * - `panel`: las rutas de auth del panel no dependen de ninguna tienda, así que
 *   un 404 es la dirección de la API mal puesta.
 * - `catalogo`: todo cuelga de `/public/{slug}`, y un 404 ahí es
 *   `InitializeTenantBySlug` diciendo que la tienda no existe o está cerrada.
 */
export type ContextoDeFormulario = 'plataforma' | 'panel' | 'catalogo';

export interface OpcionesDeError {
  contexto: ContextoDeFormulario;
  /** Texto del 422 cuando el backend no trae ninguno (en la práctica siempre trae). */
  si422?: string;
}

/**
 * Qué decirle a alguien cuyo formulario sin sesión falló, según lo que de
 * verdad falló (UI-11).
 *
 * Antes cada formulario tenía su `response?.data?.message || texto fijo`, y el
 * texto fijo afirmaba una causa: los logins decían "credenciales incorrectas"
 * con la API caída o CORS cortando la petición. Se descubrió con el operador
 * metiendo la contraseña buena.
 *
 * La regla es no afirmar más de lo que se sabe: solo un 422 es un problema con
 * lo que se escribió, y sin `error.response` no hubo respuesta —red, CORS o
 * servidor caído, que desde el navegador no se pueden distinguir entre sí—.
 * Los textos del backend solo se enseñan en el 422: son los únicos escritos en
 * español a propósito, porque `APP_LOCALE` es `en` y un 500 llegaría como
 * "Server Error".
 */
export function mensajeDeError(error: unknown, { contexto, si422 }: OpcionesDeError): string {
  if (!isAxiosError(error)) {
    return 'Algo falló en la aplicación. Recarga la página e inténtalo de nuevo.';
  }

  const response = error.response;

  if (!response) {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      return 'No hay conexión a internet. Revisa tu red y vuelve a intentarlo.';
    }
    if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') {
      return 'El servidor tardó demasiado en responder. Inténtalo de nuevo en unos minutos.';
    }
    return 'No se pudo contactar con el servidor: puede estar caído o rechazando la conexión (CORS).';
  }

  const { status } = response;

  if (status === 422) {
    const data = response.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    // El primer error de cualquier campo y no `message`: con más de un error,
    // Laravel le añade "(and 1 more error)" en inglés.
    const primero = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return primero ?? data?.message ?? si422 ?? 'Revisa los datos del formulario.';
  }

  if (status === 429) {
    return 'Demasiados intentos seguidos. Espera un minuto antes de volver a probar.';
  }

  if (status === 403) {
    return contexto === 'plataforma'
      ? 'Acceso denegado desde esta red: tu IP no está autorizada para el panel de plataforma (PLATFORM_ALLOWED_IPS).'
      : 'El servidor rechazó la petición (403). Si persiste, contacta con soporte.';
  }

  if (status === 404) {
    return contexto === 'catalogo'
      ? 'Esta tienda no está disponible en este momento.'
      : 'No se encontró el servicio (404): la dirección de la API parece mal configurada.';
  }

  if (status === 503) {
    return 'El sistema está en mantenimiento. Vuelve a intentarlo en unos minutos.';
  }

  if (status >= 500) {
    return `El servidor tuvo un error interno (${status}). No es un problema de lo que escribiste: inténtalo de nuevo en unos minutos.`;
  }

  return `Respuesta inesperada del servidor (${status}). Inténtalo de nuevo.`;
}

/**
 * Muestra el mensaje de arriba como toast.
 *
 * Con `id` fijo para que varios intentos seguidos refresquen un único aviso en
 * vez de apilarlos. El del 429 reutiliza el `rate-limit` del interceptor de
 * `api/axios.ts`, que ya avisa por su cuenta en el panel y el catálogo: sin eso
 * saldrían dos toasts diciendo lo mismo.
 */
export function avisarError(error: unknown, opciones: OpcionesDeError): void {
  const esRateLimit = isAxiosError(error) && error.response?.status === 429;
  toast.error(mensajeDeError(error, opciones), { id: esRateLimit ? 'rate-limit' : 'form-error' });
}
