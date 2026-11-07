import type { FilaRechazada } from '../api/products';

/**
 * Las filas del import que no entraron, como un CSV para corregir y volver a
 * subir (FUN-29).
 *
 * Con la cabecera del archivo original y sus celdas tal como llegaron, más una
 * columna `error` al final con el motivo: el importador ignora las columnas que
 * no conoce, así que el mismo archivo, corregido, se puede volver a subir sin
 * quitarla. Separado por `;`, como la plantilla y la exportación, y con BOM para
 * que Excel lea bien las tildes.
 */
export function csvDeFilasRechazadas(cabecera: string[], filas: FilaRechazada[]): string {
  const ancho = Math.max(cabecera.length, ...filas.map((f) => f.celdas.length));
  const completar = (celdas: (string | null)[]) =>
    Array.from({ length: ancho }, (_, i) => celdas[i] ?? '');

  const lineas = [
    [...completar(cabecera), 'error'],
    ...filas.map((f) => [...completar(f.celdas), `Fila ${f.fila}: ${f.error}`]),
  ];

  return '\uFEFF' + lineas.map((celdas) => celdas.map(celdaCsv).join(';')).join('\r\n') + '\r\n';
}

/** Una celda con comillas si lleva separador, comillas o saltos de línea. */
function celdaCsv(valor: string | null): string {
  const texto = valor ?? '';
  return /[;"\r\n]/.test(texto) ? `"${texto.replace(/"/g, '""')}"` : texto;
}

/** Guarda un texto como archivo desde el navegador. */
export function descargarTexto(nombre: string, texto: string, tipo = 'text/csv;charset=utf-8'): void {
  const url = URL.createObjectURL(new Blob([texto], { type: tipo }));
  const enlace = document.createElement('a');
  enlace.href = url;
  enlace.download = nombre;
  document.body.appendChild(enlace);
  enlace.click();
  enlace.remove();
  URL.revokeObjectURL(url);
}
