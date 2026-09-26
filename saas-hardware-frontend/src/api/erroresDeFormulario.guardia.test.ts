import { describe, expect, it } from 'vitest';

/**
 * Ninguna pantalla pinta el `message` de un error del servidor a mano (UI-15).
 *
 * Así llegaban al panel "The image field must not be greater than 10240
 * kilobytes." y "Server Error": cada pantalla tenía su
 * `err.response?.data?.message || 'texto fijo'`, y es el patrón que se copia al
 * hacer una pantalla nueva. Los errores pasan por `api/erroresDeFormulario.ts`:
 * `mensajeDeErrorEnSesion` en el panel y `mensajeDeError` sin sesión.
 *
 * Se busca `?.data?.message` —con `?.`, que es como se lee un error, cuya
 * respuesta puede no existir— se llame como se llame la variable (`ReportsPage`
 * la llamaba `respuesta`), y `response.data.message` con o sin `?.`. Leer
 * `data.message` o `res.data.message` de una respuesta que salió BIEN —un
 * "Guardado" escrito por nosotros— no es esto y no se busca.
 */
const PATRON = /\?\.data\??\.message|\bresponse\??\.data\??\.message/;

const fuentes = import.meta.glob(['/src/**/*.{ts,tsx}', '!/src/**/*.test.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>;

describe('guardia de UI-15', () => {
  it('el patrón detecta las formas en que se escribía', () => {
    expect(PATRON.test("const msg = err.response?.data?.message || 'Error al crear';")).toBe(true);
    expect(PATRON.test("toast.error((error as ApiError).response?.data?.message || 'x');")).toBe(true);
    expect(PATRON.test('return respuesta?.data?.message ?? "x";')).toBe(true);
    expect(PATRON.test('toast.error(e.response.data.message);')).toBe(true);
    expect(PATRON.test("toast.success(res.data.message);")).toBe(false);
    expect(PATRON.test("toast.success(data.message || 'Guardado');")).toBe(false);
  });

  it('lee de verdad el código de la aplicación', () => {
    expect(Object.keys(fuentes).length).toBeGreaterThan(50);
  });

  it('ninguna pantalla pinta a mano el `message` de un error', () => {
    const aMano = Object.entries(fuentes)
      .filter(([ruta]) => ruta !== '/src/api/erroresDeFormulario.ts')
      .flatMap(([ruta, codigo]) =>
        codigo.split('\n').flatMap((linea, i) => (PATRON.test(linea) ? [`${ruta}:${i + 1}`] : [])),
      );

    expect(aMano).toEqual([]);
  });
});
