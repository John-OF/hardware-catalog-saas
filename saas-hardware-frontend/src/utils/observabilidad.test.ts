import { AxiosError } from 'axios';
import type { ErrorEvent } from '@sentry/react';
import { afterEach, describe, expect, it } from 'vitest';
import { depurarEvento, iniciarObservabilidad, tiendaActual } from './observabilidad';

const evento = (extra: Partial<ErrorEvent> = {}): ErrorEvent => ({
  type: undefined,
  user: { id: '42', email: 'ana@ejemplo.com' },
  request: {
    url: 'https://gondofy.test/reset-password?token=SECRETO&email=ana@ejemplo.com#paso',
    query_string: 'token=SECRETO&email=ana@ejemplo.com',
    cookies: { sesion: 'abc' },
    headers: { Authorization: 'Bearer 1|zzz' },
    data: 'password=clave',
  },
  ...extra,
});

const errorDeLaApi = (status: number) =>
  new AxiosError('fallo', 'ERR_BAD_RESPONSE', undefined, undefined, { status } as never);

afterEach(() => {
  sessionStorage.clear();
  window.history.pushState({}, '', '/');
});

describe('iniciarObservabilidad', () => {
  it('sin DSN no arranca nada', () => {
    expect(iniciarObservabilidad({})).toBe(false);
    expect(iniciarObservabilidad({ VITE_SENTRY_DSN: '   ' })).toBe(false);
  });
});

describe('depurarEvento', () => {
  it('quita usuario, cookies, cabeceras, cuerpo y query string', () => {
    const limpio = depurarEvento(evento());

    expect(limpio?.user).toBeUndefined();
    expect(limpio?.request).toEqual({ url: 'https://gondofy.test/reset-password' });
  });

  it('no envía un 4xx de la API: es una respuesta normal, no un fallo', () => {
    expect(depurarEvento(evento(), { originalException: errorDeLaApi(401) })).toBeNull();
    expect(depurarEvento(evento(), { originalException: errorDeLaApi(422) })).toBeNull();
  });

  it('sí envía un 5xx', () => {
    expect(depurarEvento(evento(), { originalException: errorDeLaApi(500) })).not.toBeNull();
  });

  it('etiqueta la tienda del panel', () => {
    sessionStorage.setItem('tenant_slug', 'tienda-demo');

    expect(depurarEvento(evento())?.tags).toEqual({ tienda: 'tienda-demo' });
  });
});

describe('tiendaActual', () => {
  it('en un catálogo público sale de la URL', () => {
    window.history.pushState({}, '', '/tienda-demo/producto/1');

    expect(tiendaActual()).toBe('tienda-demo');
  });

  it('en las pantallas de la aplicación no hay tienda', () => {
    for (const ruta of ['/', '/login', '/dashboard/products', '/terminos', '/platform/login']) {
      window.history.pushState({}, '', ruta);
      expect(tiendaActual()).toBeNull();
    }
  });
});
