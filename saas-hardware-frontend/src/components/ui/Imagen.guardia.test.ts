import { describe, expect, it } from 'vitest';

/**
 * Ninguna pantalla pinta un `<img>` suelto (`UI-16`): toda imagen pasa por
 * `<Imagen>`, que pone el respaldo cuando la URL no carga. Un `<img>` a mano es
 * lo que se copia al hacer una pantalla nueva, y es justo lo que dejaba el icono
 * de imagen rota del navegador —y el `alt` desbordando la caja— a cada visitante.
 *
 * Y ningún respaldo pide nada a un tercero: el único que había era un
 * `via.placeholder.com`, que convertía un hueco vacío en una petición fuera.
 */
const IMG_SUELTO = /<img(?=[\s>/]|$)/;
const TERCERO = /placeholder\.com|placehold\.co|dummyimage\.com/;

const fuentes = import.meta.glob(['/src/**/*.{ts,tsx}', '!/src/**/*.test.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>;

const lineasQue = (patron: RegExp, excepto: string[] = []) =>
  Object.entries(fuentes)
    .filter(([ruta]) => !excepto.includes(ruta))
    .flatMap(([ruta, codigo]) =>
      codigo.split('\n').flatMap((linea, i) => {
        // Un comentario que habla de `<img>` no es un `<img>`.
        const sinComentario = linea.replace(/^\s*(\*|\/\/|\{\/\*).*$/, '');
        return patron.test(sinComentario) ? [`${ruta}:${i + 1}`] : [];
      }),
    );

describe('guardia de UI-16', () => {
  it('el patrón detecta un <img> en una línea o partido en varias', () => {
    expect(IMG_SUELTO.test('<img src={url} alt="" />')).toBe(true);
    expect(IMG_SUELTO.test('                            <img')).toBe(true);
    expect(IMG_SUELTO.test('<Imagen src={url} alt="" />')).toBe(false);
    expect(IMG_SUELTO.test('<ImageIcon size={18} />')).toBe(false);
  });

  it('lee de verdad el código de la aplicación', () => {
    expect(Object.keys(fuentes).length).toBeGreaterThan(50);
    expect(fuentes['/src/components/ui/Imagen.tsx']).toMatch(IMG_SUELTO);
  });

  it('ninguna pantalla pinta un <img> suelto', () => {
    expect(lineasQue(IMG_SUELTO, ['/src/components/ui/Imagen.tsx'])).toEqual([]);
  });

  it('ningún respaldo pide la imagen a un tercero', () => {
    expect(lineasQue(TERCERO)).toEqual([]);
  });
});
