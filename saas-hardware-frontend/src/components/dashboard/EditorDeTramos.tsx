import './EditorDeTramos.css';

import { Plus, Trash2 } from 'lucide-react';
import {
  MAXIMO_DE_TRAMOS,
  MINIMO_DE_UN_TRAMO,
  tramoVacio,
  type TramoEnFormulario,
} from '../../utils/tramosEnFormulario';

interface EditorDeTramosProps {
  filas: TramoEnFormulario[];
  moneda: string;
  /** El precio normal, para poder decir cuánto rebaja cada tramo. Vacío si aún no se escribió. */
  precioBase?: string;
  /** Sin título ni explicación: dentro de la ventana de una variante ya los lleva la ventana. */
  compacto?: boolean;
  onChange: (filas: TramoEnFormulario[]) => void;
}

/**
 * El precio por mayor de un producto o de una variante (MOD-15).
 *
 * **Un tramo es un precio, no un descuento**: "desde 10 unidades, a 90 cada
 * una", y entonces las diez valen 90. El editor lo dice con esas palabras a
 * propósito, porque la otra lectura —"10% menos a partir de 10"— es la que hace
 * que el dueño escriba el número equivocado.
 *
 * Se usa en dos sitios: bajo el precio de la ficha (productos sin variantes) y
 * dentro de la ventana de cada variante, porque con variantes el precio vive
 * ahí (MOD-5) y el precio por mayor con él.
 */
export default function EditorDeTramos({ filas, moneda, precioBase = '', compacto = false, onChange }: EditorDeTramosProps) {
  const cambiar = (clave: string, cambios: Partial<TramoEnFormulario>) => {
    onChange(filas.map((f) => (f.clave === clave ? { ...f, ...cambios } : f)));
  };

  /** Cuánto rebaja este tramo, para que el dueño vea lo que está dando. */
  const rebaja = (fila: TramoEnFormulario): string | null => {
    const base = Number(precioBase);
    const precio = Number(fila.price);

    if (!base || !precio || precio >= base) return null;

    return `${Math.round(((base - precio) / base) * 100)}% menos`;
  };

  return (
    <div className="tier-editor">
      {!compacto && (
        <div className="tier-editor-head">
          <span className="tier-editor-title">Precio por mayor (opcional)</span>
          <p className="tier-editor-hint">
            Si vendes más barato por cantidad, dilo aquí. Al llegar a las unidades que pongas,
            <strong> todas</strong> se cobran a ese precio.
          </p>
        </div>
      )}

      {filas.length > 0 && (
        <div className="tier-rows">
          {filas.map((fila, n) => (
            <div key={fila.clave} className="tier-row">
              <label>
                <span>Desde (unidades)</span>
                <input
                  type="number"
                  min={MINIMO_DE_UN_TRAMO}
                  step="1"
                  className="premium-input"
                  value={fila.min}
                  onChange={(e) => cambiar(fila.clave, { min: e.target.value })}
                  aria-label={`Unidades del tramo ${n + 1}`}
                />
              </label>
              <label>
                <span>Precio c/u ({moneda})</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  className="premium-input"
                  value={fila.price}
                  onChange={(e) => cambiar(fila.clave, { price: e.target.value })}
                  aria-label={`Precio del tramo ${n + 1}`}
                />
                {rebaja(fila) && <small className="tier-discount">{rebaja(fila)}</small>}
              </label>
              <button
                type="button"
                className="tier-icon-btn danger"
                onClick={() => onChange(filas.filter((f) => f.clave !== fila.clave))}
                title="Quitar este tramo"
                aria-label={`Quitar el tramo ${n + 1}`}
              >
                <Trash2 size={15} />
              </button>
            </div>
          ))}
        </div>
      )}

      {filas.length < MAXIMO_DE_TRAMOS ? (
        <button type="button" className="btn-secondary tier-add-btn" onClick={() => onChange([...filas, tramoVacio()])}>
          <Plus size={15} /> Añadir tramo
        </button>
      ) : (
        <p className="tier-editor-hint">Has llegado al máximo de {MAXIMO_DE_TRAMOS} tramos.</p>
      )}
    </div>
  );
}
