import { describe, expect, it } from 'vitest';
import { csvDeFilasRechazadas } from './filasRechazadas';

describe('csvDeFilasRechazadas (FUN-29)', () => {
  it('devuelve la cabecera original y las filas como llegaron, con el motivo al final', () => {
    const csv = csvDeFilasRechazadas(['Nombre', 'Precio', 'Stock'], [
      { fila: 3, error: "El precio 'caro' no es válido.", celdas: ['Teclado', 'caro', '2'] },
    ]);

    expect(csv.startsWith('\uFEFF')).toBe(true);
    expect(csv.slice(1).split('\r\n')).toEqual([
      'Nombre;Precio;Stock;error',
      "Teclado;caro;2;Fila 3: El precio 'caro' no es válido.",
      '',
    ]);
  });

  it('pone entre comillas lo que lleva separador, comillas o saltos de línea', () => {
    const csv = csvDeFilasRechazadas(['nombre', 'descripcion'], [
      { fila: 2, error: 'x', celdas: ['Mouse; inalámbrico', 'Dice "hola"\nen dos líneas'] },
    ]);

    expect(csv).toContain('"Mouse; inalámbrico";"Dice ""hola""\nen dos líneas";Fila 2: x');
  });

  it('una fila más corta que la cabecera se completa, para no correr la columna del error', () => {
    const csv = csvDeFilasRechazadas(['nombre', 'precio', 'stock'], [{ fila: 4, error: 'Falta el nombre.', celdas: ['', '10'] }]);

    expect(csv.slice(1).split('\r\n')[1]).toBe(';10;;Fila 4: Falta el nombre.');
  });
});
