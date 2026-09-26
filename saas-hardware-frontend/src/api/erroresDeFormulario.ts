import { isAxiosError, type AxiosError } from 'axios';
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

interface CuerpoDeError {
  message?: string;
  errors?: Record<string, string[]>;
}

/**
 * Lo que no depende de qué pantalla envió la petición: que no sea un error de
 * red (`null` si hubo respuesta HTTP), y el texto de un error que ni siquiera es
 * de axios.
 */
function sinRespuesta(error: unknown): string | null {
  if (!isAxiosError(error)) {
    return 'Algo falló en la aplicación. Recarga la página e inténtalo de nuevo.';
  }

  if (error.response) {
    return null;
  }

  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return 'No hay conexión a internet. Revisa tu red y vuelve a intentarlo.';
  }
  if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') {
    return 'El servidor tardó demasiado en responder. Inténtalo de nuevo en unos minutos.';
  }
  return 'No se pudo contactar con el servidor: puede estar caído o rechazando la conexión (CORS).';
}

/**
 * El primer error de cualquier campo de un 422, y si no hay, su `message`.
 *
 * El primer error de campo y no `message` porque con más de uno Laravel le
 * añade la cuenta: era "(and 1 more error)" en inglés hasta UI-15, y aunque hoy
 * llega en español, a quien escribe le sirve el error, no el recuento. Un 422 sin
 * `errors` —el tope del plan (`PlanLimitException`), un `abort(422, '...')`— trae
 * solo `message`, y ese se enseña.
 */
function textoDe422(data: CuerpoDeError | undefined): string | undefined {
  const primero = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
  return primero ?? data?.message;
}

const TEXTO_429 = 'Demasiados intentos seguidos. Espera un minuto antes de volver a probar.';

/** 5xx: el problema es del servidor, y se dice que no es de lo escrito. */
function textoDe5xx(status: number): string {
  return status === 503
    ? 'El sistema está en mantenimiento. Vuelve a intentarlo en unos minutos.'
    : `El servidor tuvo un error interno (${status}). No es un problema de lo que escribiste: inténtalo de nuevo en unos minutos.`;
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
 * Los textos del backend solo se enseñan en el 422: en estos formularios los
 * demás códigos significan algo que se sabe de antemano (el 403 de plataforma
 * es la cerca de IP, el 404 del catálogo es la tienda cerrada), y así se dice.
 */
export function mensajeDeError(error: unknown, { contexto, si422 }: OpcionesDeError): string {
  const sinHttp = sinRespuesta(error);
  if (sinHttp) return sinHttp;

  const response = (error as AxiosError<CuerpoDeError>).response!;
  const { status } = response;

  if (status === 422) {
    return textoDe422(response.data) ?? si422 ?? 'Revisa los datos del formulario.';
  }

  if (status === 429) {
    return TEXTO_429;
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

  if (status >= 500) {
    return textoDe5xx(status);
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

/**
 * Qué decirle a alguien del panel —de la tienda o de plataforma— cuya acción
 * falló (UI-15).
 *
 * Las pantallas del panel pintaban `err.response?.data?.message` tal cual, y
 * eso enseñaba en inglés todo lo que escribe Laravel: "The image field must not
 * be greater than 10240 kilobytes.", "Server Error", "No query results for model
 * [App\Models\Product]...". `mensajeDeError` no sirve aquí tal cual, porque con
 * sesión los 4xx sí dicen cosas que hay que leer: "Sesión de soporte: solo
 * lectura…", el tope del plan, "Esta tienda se quedaría sin ningún
 * administrador activo.". Con `mensajeDeError` saldrían como un 403 genérico.
 *
 * Así que aquí un 4xx enseña el texto del backend, y eso es seguro porque desde
 * UI-15 el backend responde siempre en español: la validación está traducida y
 * los 404/405 de Laravel se reescriben en `bootstrap/app.php`. Las excepciones
 * son las que el backend no escribe con intención: 401 (la sesión caducó; el
 * interceptor ya manda al login), 413 (el servidor web o PHP cortó un archivo
 * antes de que llegara a Laravel, INF-12) y 429 (el limitador). Y como en
 * `mensajeDeError`, ni 5xx ni la falta de respuesta afirman nada del backend.
 *
 * `siNoHayTexto` es para el raro 4xx que llega sin `message`.
 */
export function mensajeDeErrorEnSesion(error: unknown, siNoHayTexto = 'No se pudo completar la acción.'): string {
  const sinHttp = sinRespuesta(error);
  if (sinHttp) return sinHttp;

  const response = (error as AxiosError<CuerpoDeError>).response!;
  const { status } = response;
  // Un 413 de nginx trae HTML, no JSON: `data` puede ser un texto.
  const data = typeof response.data === 'object' && response.data !== null ? response.data : undefined;

  if (status === 422) {
    return textoDe422(data) ?? siNoHayTexto;
  }

  if (status === 401) {
    return 'Tu sesión caducó. Vuelve a entrar.';
  }

  if (status === 413) {
    return 'El archivo pesa más de lo que admite el servidor.';
  }

  if (status === 429) {
    return TEXTO_429;
  }

  if (status >= 500) {
    return textoDe5xx(status);
  }

  if (status >= 400) {
    return data?.message || siNoHayTexto;
  }

  return `Respuesta inesperada del servidor (${status}). Inténtalo de nuevo.`;
}

/**
 * Muestra el mensaje de arriba como toast. Mismo criterio de `id` que
 * `avisarError`: el 429 comparte aviso con el interceptor y el resto refresca
 * uno solo en vez de apilar el mismo error a cada clic.
 */
export function avisarErrorEnSesion(error: unknown, siNoHayTexto?: string): void {
  const esRateLimit = isAxiosError(error) && error.response?.status === 429;
  toast.error(mensajeDeErrorEnSesion(error, siNoHayTexto), { id: esRateLimit ? 'rate-limit' : 'error-en-sesion' });
}
