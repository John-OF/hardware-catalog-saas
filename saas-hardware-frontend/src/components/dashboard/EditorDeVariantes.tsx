import './EditorDeVariantes.css';

import { useState } from 'react';
import { ImagePlus, Layers, Plus, Trash2, X } from 'lucide-react';
import { margenDe, precioQueSeCobra } from '../../utils/margen';
import {
  MAXIMO_DE_EJES,
  MAXIMO_DE_VARIANTES,
  varianteVacia,
  type VarianteEnFormulario,
} from '../../utils/variantesEnFormulario';
import Dialogo from '../ui/Dialogo';
import EditorDeTramos from './EditorDeTramos';

interface EditorDeVariantesProps {
  ejes: string[];
  filas: VarianteEnFormulario[];
  moneda: string;
  /** Si se enseña la columna de costo (MOD-6). Solo para admin. */
  conCostos?: boolean;
  onChange: (ejes: string[], filas: VarianteEnFormulario[]) => void;
}

/** El margen de una fila, con el precio que de verdad se cobra (el de oferta si lo hay). */
const margenDeLaFila = (fila: VarianteEnFormulario) =>
  margenDe(precioQueSeCobra(fila.price, fila.sale_price), fila.cost);

/**
 * Las variantes de un producto en el formulario del panel (MOD-5).
 *
 * Los nombres de las opciones se escriben una vez para todo el producto
 * ("Capacidad", "Color") y cada variante solo rellena sus valores: así no puede
 * haber una que diga "Capacidad" y otra "capacidad " para lo mismo, que en la
 * tienda saldrían como dos cosas distintas.
 *
 * El costo de cada variante (MOD-6) solo aparece si `conCostos`: es dato de
 * admin, y su ausencia significa "no lo toques" al guardar.
 */
export default function EditorDeVariantes({ ejes, filas, moneda, conCostos = false, onChange }: EditorDeVariantesProps) {
  /**
   * MOD-15: qué variante tiene abierto su precio por mayor. En una ventana
   * aparte y no como columnas de la fila porque son de dos a cinco parejas de
   * números por variante, y metidos en la rejilla dejarían cada campo en dos
   * dígitos de ancho. Se guarda la clave y no la fila para que lo que se edite
   * siga siendo la fila de `filas`, que es la única copia buena.
   */
  const [tramosAbiertos, setTramosAbiertos] = useState<string | null>(null);
  const filaConTramos = filas.find((f) => f.clave === tramosAbiertos) ?? null;

  const cambiarEje = (i: number, nombre: string) => {
    onChange(ejes.map((e, j) => (j === i ? nombre : e)), filas);
  };

  const agregarEje = () => {
    onChange([...ejes, ''], filas.map((f) => ({ ...f, valores: [...f.valores, ''] })));
  };

  const quitarEje = (i: number) => {
    onChange(ejes.filter((_, j) => j !== i), filas.map((f) => ({ ...f, valores: f.valores.filter((_, j) => j !== i) })));
  };

  const cambiarFila = (clave: string, cambios: Partial<VarianteEnFormulario>) => {
    onChange(ejes, filas.map((f) => (f.clave === clave ? { ...f, ...cambios } : f)));
  };

  const agregarFila = () => {
    // La primera variante estrena un eje, para que haya dónde escribir.
    const ejesAhora = ejes.length === 0 ? [''] : ejes;
    onChange(ejesAhora, [...filas, varianteVacia(ejesAhora)]);
  };

  const quitarFila = (clave: string) => {
    const quedan = filas.filter((f) => f.clave !== clave);
    // Sin variantes no quedan ejes que nombrar.
    onChange(quedan.length === 0 ? [] : ejes, quedan);
  };

  return (
    <div className="variant-editor">
      <div className="variant-editor-head">
        <div>
          <span className="variant-editor-title">Variantes (opcional)</span>
          <p className="variant-editor-hint">
            Si lo vendes en varias capacidades, colores o versiones, añade una variante por cada una.
            Cada variante tiene su precio, su stock y su foto, y el comprador elige cuál quiere.
          </p>
        </div>
      </div>

      {filas.length > 0 && (
        <>
          <div className="variant-axes">
            {ejes.map((eje, i) => (
              <div key={i} className="variant-axis">
                <input
                  type="text"
                  className="premium-input"
                  placeholder={i === 0 ? 'Opción (ej. Capacidad)' : 'Otra opción (ej. Color)'}
                  value={eje}
                  maxLength={50}
                  onChange={(e) => cambiarEje(i, e.target.value)}
                  aria-label={`Nombre de la opción ${i + 1}`}
                />
                {ejes.length > 1 && (
                  <button type="button" className="variant-icon-btn" onClick={() => quitarEje(i)} title="Quitar esta opción">
                    <X size={14} />
                  </button>
                )}
              </div>
            ))}
            {ejes.length < MAXIMO_DE_EJES && (
              <button type="button" className="variant-link-btn" onClick={agregarEje}>
                <Plus size={13} /> Otra opción
              </button>
            )}
          </div>

          <div className="variant-rows">
            {filas.map((fila, n) => (
              <div key={fila.clave} className="variant-row">
                <div className="variant-row-image">
                  {fila.vistaPrevia && !fila.quitarImagen ? (
                    <>
                      <img src={fila.vistaPrevia} alt={`Foto de la variante ${n + 1}`} />
                      <button
                        type="button"
                        className="variant-image-remove"
                        title="Quitar foto"
                        onClick={() => cambiarFila(fila.clave, { imagen: null, vistaPrevia: null, quitarImagen: Boolean(fila.id) })}
                      >
                        <X size={11} />
                      </button>
                    </>
                  ) : (
                    <label className="variant-image-pick" title="Foto de esta variante (opcional)">
                      <ImagePlus size={16} />
                      <input
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        onChange={(e) => {
                          const archivo = e.target.files?.[0];
                          if (archivo) {
                            cambiarFila(fila.clave, { imagen: archivo, vistaPrevia: URL.createObjectURL(archivo), quitarImagen: false });
                          }
                        }}
                      />
                    </label>
                  )}
                </div>

                <div className="variant-row-fields">
                  <div className="variant-values">
                    {ejes.map((eje, i) => (
                      <input
                        key={i}
                        type="text"
                        className="premium-input"
                        placeholder={eje.trim() ? eje : `Valor ${i + 1}`}
                        value={fila.valores[i] ?? ''}
                        maxLength={100}
                        onChange={(e) => cambiarFila(fila.clave, { valores: fila.valores.map((v, j) => (j === i ? e.target.value : v)) })}
                        aria-label={`${eje || 'Valor'} de la variante ${n + 1}`}
                      />
                    ))}
                  </div>
                  <div className="variant-numbers">
                    <label>
                      <span>Precio ({moneda})</span>
                      <input type="number" step="0.01" min="0" className="premium-input" value={fila.price} onChange={(e) => cambiarFila(fila.clave, { price: e.target.value })} />
                    </label>
                    <label>
                      <span>Oferta</span>
                      <input type="number" step="0.01" min="0" className="premium-input" value={fila.sale_price} onChange={(e) => cambiarFila(fila.clave, { sale_price: e.target.value })} />
                    </label>
                    {conCostos && (
                      <label>
                        <span>Costo</span>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          className="premium-input"
                          value={fila.cost}
                          onChange={(e) => cambiarFila(fila.clave, { cost: e.target.value })}
                        />
                        {margenDeLaFila(fila) && (
                          <small className="variant-margin">
                            {margenDeLaFila(fila)!.porcentaje}% de margen
                          </small>
                        )}
                      </label>
                    )}
                    <label>
                      <span>Stock</span>
                      <input type="number" min="0" className="premium-input" value={fila.stock} onChange={(e) => cambiarFila(fila.clave, { stock: e.target.value })} />
                    </label>
                    <label>
                      <span>SKU</span>
                      <input type="text" maxLength={100} className="premium-input" value={fila.sku} onChange={(e) => cambiarFila(fila.clave, { sku: e.target.value })} />
                    </label>
                  </div>
                  {/* MOD-15: el precio por mayor de ESTA variante. El botón dice
                      cuántos tramos tiene para que se vea sin abrir la ventana
                      cuál de las variantes lo tiene puesto y cuál no. */}
                  <button
                    type="button"
                    className={`variant-tier-btn ${fila.price_tiers.length > 0 ? 'tiene-tramos' : ''}`}
                    onClick={() => setTramosAbiertos(fila.clave)}
                  >
                    <Layers size={13} />
                    {fila.price_tiers.length > 0
                      ? `Precio por mayor (${fila.price_tiers.length})`
                      : 'Precio por mayor'}
                  </button>
                </div>

                <button type="button" className="variant-icon-btn danger" onClick={() => quitarFila(fila.clave)} title="Quitar variante">
                  <Trash2 size={15} />
                </button>
              </div>
            ))}
          </div>
        </>
      )}

      {filas.length < MAXIMO_DE_VARIANTES ? (
        <button type="button" className="btn-secondary variant-add-btn" onClick={agregarFila}>
          <Plus size={15} /> Añadir variante
        </button>
      ) : (
        <p className="variant-editor-hint">Has llegado al máximo de {MAXIMO_DE_VARIANTES} variantes por producto.</p>
      )}

      {/* MOD-15. No guarda nada por su cuenta: escribe en la misma fila que el
          resto del formulario, y se guarda todo junto con el producto. Por eso
          solo se cierra, sin botón de aceptar que prometería otra cosa. */}
      {filaConTramos && (
        <Dialogo
          titulo={`Precio por mayor · ${filaConTramos.valores.filter(Boolean).join(' / ') || 'variante nueva'}`}
          subtitulo="Al llegar a las unidades que pongas, todas se cobran a ese precio."
          className="page-products"
          onCerrar={() => setTramosAbiertos(null)}
        >
          <div className="dialogo-cuerpo">
            <EditorDeTramos
              filas={filaConTramos.price_tiers}
              moneda={moneda}
              precioBase={filaConTramos.price}
              compacto
              onChange={(tramos) => cambiarFila(filaConTramos.clave, { price_tiers: tramos })}
            />
          </div>
        </Dialogo>
      )}
    </div>
  );
}
