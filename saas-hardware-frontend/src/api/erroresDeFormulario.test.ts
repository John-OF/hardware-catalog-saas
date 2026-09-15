import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'react-hot-toast';
import { avisarError, mensajeDeError } from './erroresDeFormulario';

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
