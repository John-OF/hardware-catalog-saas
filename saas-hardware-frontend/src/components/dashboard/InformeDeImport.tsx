import './InformeDeImport.css';

import { AlertCircle, CheckCircle2, Download, Eye } from 'lucide-react';
import type { AccionDeImport, ImportReport } from '../../api/products';
import { csvDeFilasRechazadas, descargarTexto } from '../../utils/filasRechazadas';

/**
 * Los grupos del informe, en el orden en que importan. Los dos primeros son lo
 * que se escribió y van abiertos; "sin cambios" y "omitidos" son lo que NO se
 * tocó, y en un catálogo reimportado pueden ser cientos: van plegados, con la
 * cuenta a la vista.
 */
const GRUPOS: { accion: AccionDeImport; titulo: string; enPrevia: string; abierto: boolean }[] = [
  { accion: 'actualizado', titulo: 'Actualizados', enPrevia: 'Se actualizarían', abierto: true },
  { accion: 'creado', titulo: 'Creados', enPrevia: 'Se crearían', abierto: true },
  { accion: 'sin_cambios', titulo: 'Sin cambios (ya estaban al día)', enPrevia: 'Sin cambios (ya están al día)', abierto: false },
  { accion: 'omitido', titulo: 'Omitidos porque ya existían', enPrevia: 'Se omitirían porque ya existen', abierto: false },
];

/**
 * El resultado de un import CSV, producto a producto (FUN-19).
 *
 * Antes solo se listaban las filas que fallaban, y de lo que sí entró se sabía
 * una cifra. Con el modo "actualizar" (FUN-17) eso no bastaba: el dueño tiene
 * que poder leer qué precio cambió en qué producto antes de dar el archivo por
 * bueno. Cada línea lleva la fila del archivo, la misma que usan los errores.
 */
export default function InformeDeImport({ informe }: { informe: ImportReport }) {
  const hayAvisos = informe.errors.length > 0;
  const previa = informe.preview === true;
  const rechazadas = informe.rejected_rows ?? [];

  // FUN-29: las filas que no entraron, como un CSV para corregir y volver a subir.
  const descargarRechazadas = () =>
    descargarTexto('filas-con-error.csv', csvDeFilasRechazadas(informe.header ?? [], rechazadas));

  return (
    <div className={`informe-import ${previa ? 'previa' : ''}`} role="status">
      <h4 className={`informe-import-titulo ${hayAvisos ? 'con-avisos' : ''}`}>
        {previa ? <Eye size={16} /> : hayAvisos ? <AlertCircle size={16} /> : <CheckCircle2 size={16} />}
        {previa
          ? 'Vista previa: todavía no se guardó nada'
          : hayAvisos ? 'Importación con avisos' : 'Importación terminada'}
      </h4>
      <p className="informe-import-resumen">{informe.message}</p>

      {hayAvisos && (
        <section className="informe-import-grupo informe-import-errores">
          <h5>{previa ? 'Filas que no entrarían o con avisos' : 'Filas que no entraron o con avisos'} ({informe.errors.length})</h5>
          {rechazadas.length > 0 && (
            <button type="button" className="btn-secondary btn-small informe-import-descargar" onClick={descargarRechazadas}>
              <Download size={14} /> Descargar las filas con error para corregirlas
            </button>
          )}
          <ul>
            {informe.errors.map((error, i) => (
              <li key={i}>{error}</li>
            ))}
          </ul>
        </section>
      )}

      {GRUPOS.map((grupo) => {
        const entradas = informe.changes.filter((c) => c.accion === grupo.accion);

        if (entradas.length === 0) {
          return null;
        }

        return (
          <details key={grupo.accion} className="informe-import-grupo" open={grupo.abierto}>
            <summary>
              {previa ? grupo.enPrevia : grupo.titulo} ({entradas.length})
            </summary>
            <ul>
              {entradas.map((entrada) => (
                <li key={entrada.fila}>
                  <span className="informe-import-fila">Fila {entrada.fila}</span>{' '}
                  <strong>{entrada.producto}</strong>
                  {entrada.detalle && <span className="informe-import-detalle">: {entrada.detalle}</span>}
                </li>
              ))}
            </ul>
          </details>
        );
      })}
    </div>
  );
}
