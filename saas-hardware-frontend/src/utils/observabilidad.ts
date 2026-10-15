import * as Sentry from '@sentry/react';
import { isAxiosError } from 'axios';

/**
 * Errores no controlados hacia Sentry (`TEC-5`).
 *
 * **Sin `VITE_SENTRY_DSN` no hace nada**: ni siquiera se inicia el cliente, que
 * es lo que hay en local y en los tests. Con DSN, lo que sale es el error, su
 * traza y la tienda como etiqueta, **sin datos personales**:
 *
 * - `dataCollection` sin usuario, cookies, cabeceras, cuerpos ni parámetros de la URL, y **ningún rastro de
 *   navegación** (`beforeBreadcrumb` devuelve null): los clics, las
 *   peticiones y la consola pueden llevar nombres, correos o el token del
 *   enlace de recuperar contraseña.
 * - `depurarEvento` quita además el usuario, las cookies, las cabeceras y el
 *   *query string* de la URL —el enlace de recuperar contraseña lleva el token
 *   y el correo ahí—, por si una versión futura del SDK añadiera alguno.
 * - Un 4xx de la API no se envía: es la respuesta normal a una sesión vencida
 *   o a un formulario mal rellenado, no un fallo, y el plan gratis tiene 5.000
 *   errores al mes.
 *
 * El DSN solo permite *enviar* eventos al proyecto, no leerlos; por eso va en
 * el build del navegador como `VITE_PLATFORM_HOSTS`.
 */

type Env = Record<string, unknown>;

/** Primeros segmentos de la URL que son de la aplicación y no una tienda. */
const RUTAS_DE_LA_APLICACION = new Set([
  'login', 'register', 'dashboard', 'platform', 'terminos', 'privacidad', 'reembolsos',
  'forgot-password', 'reset-password', 'accept-invitation',
]);

const limpio = (valor: unknown) => (typeof valor === 'string' && valor.trim() !== '' ? valor.trim() : null);

/** La tienda a la que le pasó: la del panel, o la primera parte de la URL del catálogo. */
export function tiendaActual(): string | null {
  try {
    const delPanel = sessionStorage.getItem('tenant_slug');
    if (delPanel) return delPanel;
  } catch {
    // Sin almacenamiento (ventana privada): se mira la URL.
  }

  const primero = window.location.pathname.split('/')[1] ?? '';

  return primero !== '' && !RUTAS_DE_LA_APLICACION.has(primero) ? primero : null;
}

export function depurarEvento<T extends Sentry.ErrorEvent>(event: T, hint?: Sentry.EventHint): T | null {
  const original = hint?.originalException;
  if (isAxiosError(original)) {
    const estado = original.response?.status;
    if (estado !== undefined && estado >= 400 && estado < 500) return null;
  }

  delete event.user;

  if (event.request) {
    delete event.request.cookies;
    delete event.request.headers;
    delete event.request.data;
    delete event.request.query_string;
    if (event.request.url) event.request.url = event.request.url.split(/[?#]/)[0];
  }

  const tienda = tiendaActual();
  if (tienda) event.tags = { ...event.tags, tienda };

  return event;
}

/** Arranca Sentry si hay DSN. Devuelve si lo arrancó, para poder comprobarlo. */
export function iniciarObservabilidad(env: Env = import.meta.env): boolean {
  const dsn = limpio(env.VITE_SENTRY_DSN);
  if (!dsn) return false;

  Sentry.init({
    dsn,
    environment: limpio(env.VITE_SENTRY_ENVIRONMENT) ?? limpio(env.MODE) ?? 'production',
    // Sentry 11 sustituyó `sendDefaultPii` por `dataCollection`: nada de usuario,
    // cookies, cabeceras, cuerpos ni parámetros de la URL.
    dataCollection: {
      userInfo: false,
      cookies: false,
      httpHeaders: false,
      httpBodies: [],
      urlQueryParams: false,
    },
    tracesSampleRate: 0,
    beforeBreadcrumb: () => null,
    beforeSend: depurarEvento,
  });

  return true;
}

/** Para los dos sitios que ya atrapan el error (`ErrorBoundary`, `RouteErrorFallback`). */
export function capturarError(error: unknown, contexto?: Record<string, unknown>) {
  if (!Sentry.getClient()) return;

  Sentry.captureException(error, contexto ? { extra: contexto } : undefined);
}
