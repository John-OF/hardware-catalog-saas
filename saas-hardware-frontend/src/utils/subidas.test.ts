import { describe, expect, it } from 'vitest';
import { aceptaDe, conRecuentoDeArchivos, formatosDe, pistaDe, problemaDelArchivo, tamanoLegible } from './subidas';

/** Un archivo que dice pesar `bytes` sin reservar esa memoria. */
const archivo = (nombre: string, bytes: number, type = '') => {
  const f = new File(['x'], nombre, { type });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
};

const MB = 1024 * 1024;

describe('subidas (UI-15)', () => {
  /** Los mismos casos que `Subidas::legible()` en `LimitesDeSubidaTest`. */
  it('dice el tope como lo diría una persona, igual que el servidor', () => {
    expect(tamanoLegible(10240)).toBe('10 MB');
    expect(tamanoLegible(5120)).toBe('5 MB');
    expect(tamanoLegible(1536)).toBe('1,5 MB');
    expect(tamanoLegible(512)).toBe('512 KB');
  });

  it('nombra los formatos como los conoce el dueño', () => {
    expect(formatosDe('imagen')).toBe('JPG, PNG o WEBP');
    expect(formatosDe('favicon')).toBe('PNG o ICO');
    expect(formatosDe('csv')).toBe('CSV o TXT');
    expect(pistaDe('logo')).toBe('JPG, PNG o WEBP, máx. 2 MB');
  });

  it('el accept lleva los MIME (para que el móvil ofrezca la galería) y las extensiones', () => {
    const accept = aceptaDe('imagen').split(',');

    expect(accept).toContain('image/jpeg');
    expect(accept).toContain('.webp');
    expect(accept).not.toContain('image/gif');
  });

  it('deja pasar lo que el servidor acepta', () => {
    expect(problemaDelArchivo(archivo('rtx.png', 200 * 1024, 'image/png'), 'imagen')).toBeNull();
    // El tope se incluye, como en la regla `max` de Laravel.
    expect(problemaDelArchivo(archivo('justo.jpg', 10 * MB, 'image/jpeg'), 'imagen')).toBeNull();
  });

  it('avisa del tamaño con el mismo tope que el servidor, y dice cuánto pesa', () => {
    expect(problemaDelArchivo(archivo('grande.jpg', 12 * MB, 'image/jpeg'), 'imagen'))
      .toBe('«grande.jpg» pesa 12 MB y el máximo es 10 MB.');
    expect(problemaDelArchivo(archivo('icono.png', 700 * 1024, 'image/png'), 'favicon'))
      .toBe('«icono.png» pesa 700 KB y el máximo es 512 KB.');
  });

  /** Un byte de más: "pesa 10 MB y el máximo es 10 MB" no se entendería. */
  it('si al redondear pesa lo mismo que el tope, no dice los dos números iguales', () => {
    expect(problemaDelArchivo(archivo('casi.jpg', 10 * MB + 1, 'image/jpeg'), 'imagen'))
      .toBe('«casi.jpg» pasa del máximo de 10 MB.');
  });

  it('avisa de un tipo que el servidor no acepta', () => {
    expect(problemaDelArchivo(archivo('animada.gif', 1024, 'image/gif'), 'imagen'))
      .toBe('«animada.gif» no es un archivo admitido: sube un JPG, PNG o WEBP.');
    expect(problemaDelArchivo(archivo('catalogo.xlsx', 1024, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'), 'csv'))
      .toMatch(/sube un CSV o TXT/);
  });

  /**
   * El tipo vale si lo dice el MIME o la extensión: con uno solo, se rechazarían
   * archivos que el servidor acepta.
   */
  it('no rechaza un JPEG con otra extensión ni un CSV que Windows presenta como Excel', () => {
    expect(problemaDelArchivo(archivo('descargada.jfif', 1024, 'image/jpeg'), 'imagen')).toBeNull();
    expect(problemaDelArchivo(archivo('catalogo.csv', 1024, 'application/vnd.ms-excel'), 'csv')).toBeNull();
    expect(problemaDelArchivo(archivo('FOTO.PNG', 1024, ''), 'imagen')).toBeNull();
  });
});

describe('conRecuentoDeArchivos (INF-12)', () => {
  it('cuenta todos los archivos del formulario, estén en el campo que estén', () => {
    const formData = new FormData();
    formData.append('name', 'Placa madre');
    formData.append('image', archivo('principal.jpg', 10));
    formData.append('gallery[]', archivo('a.jpg', 10));
    formData.append('gallery[]', archivo('b.jpg', 10));
    formData.append('variant_images[3]', archivo('negro.jpg', 10));
    formData.append('specs', '{}');

    expect(conRecuentoDeArchivos(formData).get('archivos_enviados')).toBe('4');
  });

  it('sin archivos manda 0, y llamarla dos veces no se cuenta a sí misma', () => {
    const formData = new FormData();
    formData.append('name', 'Placa madre');

    conRecuentoDeArchivos(formData);
    conRecuentoDeArchivos(formData);

    expect(formData.getAll('archivos_enviados')).toEqual(['0']);
  });
});
