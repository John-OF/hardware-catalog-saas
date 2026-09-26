import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'react-hot-toast';
import { avisarError, avisarErrorEnSesion, mensajeDeError, mensajeDeErrorEnSesion } from './erroresDeFormulario';

vi.mock('react-hot-toast', () => {
  const toast = { success: vi.fn(), error: vi.fn() };
  return { toast, default: toast };
});

const conRespuesta = (status: number, data: unknown = {}) => {
  const config = { headers: new AxiosHeaders() };
  const response = { status, data, statusText: '', headers: {}, config } as AxiosResponse;
  return new AxiosError('fallo', 'ERR_BAD_RESPONSE', config, {}, response);
};

const sinRespuesta = (code = 'ERR_NETWORK') => new AxiosError('Network Error', code, { headers: new AxiosHeaders() }, {});

describe('mensajeDeError (UI-11)', () => {
  beforeEach(() => {
    vi.mocked(toast.error).mockReset();
  });

  it('sin respuesta NO dice "credenciales incorrectas": dice que no se llegó al servidor', () => {
    const texto = mensajeDeError(sinRespuesta(), { contexto: 'plataforma' });

    expect(texto).toMatch(/No se pudo contactar con el servidor/);
    expect(texto).not.toMatch(/credenciales/i);
  });

  it('distingue sin conexión y timeout', () => {
    expect(mensajeDeError(sinRespuesta('ECONNABORTED'), { contexto: 'panel' })).toMatch(/tardó demasiado/);

    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
    expect(mensajeDeError(sinRespuesta(), { contexto: 'panel' })).toMatch(/No hay conexión a internet/);
  });

  it('en un 422 enseña el primer error de campo y no el `message` con "(and 1 more error)"', () => {
    const error = conRespuesta(422, {
      message: 'The email field is required. (and 1 more error)',
      errors: { email: ['El correo es obligatorio.'], password: ['La contraseña es obligatoria.'] },
    });

    expect(mensajeDeError(error, { contexto: 'panel' })).toBe('El correo es obligatorio.');
  });

  it('en un 422 sin errores de campo cae al `message`, luego al texto propio y luego al genérico', () => {
    expect(mensajeDeError(conRespuesta(422, { message: 'Las credenciales son incorrectas.' }), { contexto: 'panel' }))
      .toBe('Las credenciales son incorrectas.');
    expect(mensajeDeError(conRespuesta(422), { contexto: 'panel', si422: 'Correo o contraseña incorrectos.' }))
      .toBe('Correo o contraseña incorrectos.');
    expect(mensajeDeError(conRespuesta(422), { contexto: 'panel' })).toBe('Revisa los datos del formulario.');
  });

  it('un 403 en plataforma es la cerca de IP; en otro sitio no afirma eso', () => {
    expect(mensajeDeError(conRespuesta(403), { contexto: 'plataforma' })).toMatch(/PLATFORM_ALLOWED_IPS/);
    expect(mensajeDeError(conRespuesta(403), { contexto: 'panel' })).not.toMatch(/IP/);
  });

  it('un 404 en el catálogo es la tienda cerrada; en el panel, la API mal configurada', () => {
    expect(mensajeDeError(conRespuesta(404), { contexto: 'catalogo' })).toBe('Esta tienda no está disponible en este momento.');
    expect(mensajeDeError(conRespuesta(404), { contexto: 'panel' })).toMatch(/dirección de la API/);
  });

  it('429, 503, 5xx y códigos raros', () => {
    expect(mensajeDeError(conRespuesta(429), { contexto: 'panel' })).toMatch(/Demasiados intentos/);
    expect(mensajeDeError(conRespuesta(503), { contexto: 'panel' })).toMatch(/mantenimiento/);
    expect(mensajeDeError(conRespuesta(500, { message: 'Server Error' }), { contexto: 'panel' }))
      .toMatch(/error interno \(500\)/);
    expect(mensajeDeError(conRespuesta(409), { contexto: 'panel' })).toMatch(/inesperada del servidor \(409\)/);
  });

  it('un error que no es de axios es un fallo de la propia aplicación', () => {
    expect(mensajeDeError(new TypeError('x is undefined'), { contexto: 'panel' })).toMatch(/Algo falló en la aplicación/);
  });

  it('avisarError reutiliza el toast del interceptor en un 429 para no duplicar el aviso', () => {
    avisarError(conRespuesta(429), { contexto: 'catalogo' });
    avisarError(conRespuesta(500), { contexto: 'catalogo' });

    expect(vi.mocked(toast.error).mock.calls[0][1]).toEqual({ id: 'rate-limit' });
    expect(vi.mocked(toast.error).mock.calls[1][1]).toEqual({ id: 'form-error' });
  });
});

describe('mensajeDeErrorEnSesion (UI-15)', () => {
  beforeEach(() => {
    vi.mocked(toast.error).mockReset();
    // Un caso de arriba deja `navigator.onLine` en false: aquí se fija a mano.
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);
  });

  /**
   * Lo que distingue a este helper de `mensajeDeError`: con sesión, un 403 es
   * nuestro y dice algo que hay que leer. Con `mensajeDeError` saldría como un
   * "El servidor rechazó la petición (403)" genérico.
   */
  it('con sesión, un 403 enseña el texto del backend', () => {
    const soporte = 'Sesión de soporte: solo lectura. Para cambiar algo, entra el dueño de la tienda.';

    expect(mensajeDeErrorEnSesion(conRespuesta(403, { message: soporte }))).toBe(soporte);
    expect(mensajeDeError(conRespuesta(403, { message: soporte }), { contexto: 'panel' })).not.toBe(soporte);
  });

  it('en un 422 enseña el primer error de campo; sin campos, el `message` (el tope del plan)', () => {
    expect(mensajeDeErrorEnSesion(conRespuesta(422, {
      message: 'El campo imagen no puede pesar más de 10 MB. (y 1 error más)',
      errors: { image: ['El campo imagen no puede pesar más de 10 MB.'], name: ['El campo nombre es obligatorio.'] },
    }))).toBe('El campo imagen no puede pesar más de 10 MB.');

    expect(mensajeDeErrorEnSesion(conRespuesta(422, { message: 'Tu plan permite 50 productos.', code: 'plan_limit' })))
      .toBe('Tu plan permite 50 productos.');
  });

  it('un 404 enseña el texto del backend, que desde UI-15 llega en español', () => {
    expect(mensajeDeErrorEnSesion(conRespuesta(404, { message: 'No se encontró: puede que se haya borrado.' })))
      .toBe('No se encontró: puede que se haya borrado.');
  });

  it('un 4xx sin texto cae al de la pantalla, y si no hay, a uno genérico', () => {
    expect(mensajeDeErrorEnSesion(conRespuesta(409), 'Error al crear la categoría')).toBe('Error al crear la categoría');
    expect(mensajeDeErrorEnSesion(conRespuesta(409))).toBe('No se pudo completar la acción.');
  });

  it('401, 413 y 429 tienen su texto, diga lo que diga el backend', () => {
    expect(mensajeDeErrorEnSesion(conRespuesta(401, { message: 'Unauthenticated.' }))).toBe('Tu sesión caducó. Vuelve a entrar.');
    // El 413 de nginx trae una página HTML, no JSON.
    expect(mensajeDeErrorEnSesion(conRespuesta(413, '<html><body>413 Request Entity Too Large</body></html>')))
      .toBe('El archivo pesa más de lo que admite el servidor.');
    expect(mensajeDeErrorEnSesion(conRespuesta(429, { message: 'Too Many Attempts.' }))).toMatch(/Demasiados intentos/);
  });

  it('un 500 no enseña el "Server Error" de Laravel', () => {
    const texto = mensajeDeErrorEnSesion(conRespuesta(500, { message: 'Server Error' }));

    expect(texto).toMatch(/error interno \(500\)/);
    expect(texto).not.toMatch(/Server Error/);
  });

  it('sin respuesta dice lo mismo que en los formularios sin sesión', () => {
    expect(mensajeDeErrorEnSesion(sinRespuesta())).toBe(mensajeDeError(sinRespuesta(), { contexto: 'panel' }));
  });

  it('avisarErrorEnSesion comparte el aviso del 429 con el interceptor', () => {
    avisarErrorEnSesion(conRespuesta(429));
    avisarErrorEnSesion(conRespuesta(422, { message: 'x' }));

    expect(vi.mocked(toast.error).mock.calls[0][1]).toEqual({ id: 'rate-limit' });
    expect(vi.mocked(toast.error).mock.calls[1][1]).toEqual({ id: 'error-en-sesion' });
  });
});
