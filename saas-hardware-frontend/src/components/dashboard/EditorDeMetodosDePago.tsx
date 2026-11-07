import './EditorDeMetodosDePago.css';

import { toast } from 'react-hot-toast';
import { ArrowDown, ArrowUp, ImagePlus, Plus, QrCode, Trash2, X } from 'lucide-react';
import Imagen from '../ui/Imagen';
import { aceptaDe, pistaDe, problemaDelArchivo } from '../../utils/subidas';
import { COUNTRY_CODES } from '../../utils/phone';
import {
  MAXIMO_DE_METODOS,
  metodoNuevo,
  sugeridosPara,
  type MetodoEnFormulario,
  type MetodoSugerido,
} from '../../utils/metodosDePagoEnFormulario';

interface EditorDeMetodosDePagoProps {
  filas: MetodoEnFormulario[];
  /** Prefijo del país para las sugerencias ('+593'). Lo elige el dueño; por defecto, el de su WhatsApp. */
  pais: string;
  onPais: (prefijo: string) => void;
  onChange: (filas: MetodoEnFormulario[]) => void;
}

/**
 * Los métodos de pago de la tienda, en Configuración (MOD-23).
 *
 * Una tarjeta por método con su interruptor, su nombre, los datos para pagar,
 * las instrucciones y el QR opcional; se reordenan con flechas, porque ese es
 * el orden en que los ve el comprador. Arriba, los que se usan en el país de la
 * tienda, para añadirlos con un clic. No guarda nada por su cuenta: escribe en
 * las filas del formulario, y se guarda todo junto con «Guardar cambios».
 */
export default function EditorDeMetodosDePago({ filas, pais, onPais, onChange }: EditorDeMetodosDePagoProps) {
  const sugeridos = sugeridosPara(pais, filas);
  const lleno = filas.length >= MAXIMO_DE_METODOS;

  const cambiar = (clave: string, cambios: Partial<MetodoEnFormulario>) => {
    onChange(filas.map((f) => (f.clave === clave ? { ...f, ...cambios } : f)));
  };

  const agregar = (sugerido?: MetodoSugerido) => {
    if (lleno) return;
    onChange([...filas, metodoNuevo(sugerido)]);
  };

  const mover = (i: number, paso: -1 | 1) => {
    const j = i + paso;
    if (j < 0 || j >= filas.length) return;
    const copia = [...filas];
    [copia[i], copia[j]] = [copia[j], copia[i]];
    onChange(copia);
  };

  const quitar = (clave: string) => onChange(filas.filter((f) => f.clave !== clave));

  return (
    <div className="metodos-pago">
      <div className="metodos-pago-sugeridos">
        <label className="metodos-pago-pais">
          <span>Sugerencias para</span>
          <select className="premium-input" value={pais} onChange={(e) => onPais(e.target.value)}>
            {COUNTRY_CODES.map((c) => (
              <option key={c.code} value={c.code}>{c.label}</option>
            ))}
          </select>
        </label>
        <div className="metodos-pago-chips">
          {sugeridos.map((s) => (
            <button key={s.name} type="button" className="metodos-pago-chip" onClick={() => agregar(s)} disabled={lleno}>
              <Plus size={13} /> {s.name}
            </button>
          ))}
          <button type="button" className="metodos-pago-chip otro" onClick={() => agregar()} disabled={lleno}>
            <Plus size={13} /> Otro método
          </button>
        </div>
        {lleno && <p className="metodos-pago-nota">Has llegado al máximo de {MAXIMO_DE_METODOS} métodos de pago.</p>}
      </div>

      {filas.length === 0 && (
        <p className="metodos-pago-nota">
          Todavía no tienes ninguno: el comprador no verá cómo pagarte hasta que añadas al menos uno.
        </p>
      )}

      <div className="metodos-pago-lista">
        {filas.map((fila, i) => {
          const qr = fila.qrVistaPrevia ?? (fila.quitarQr ? null : fila.qrGuardado);
          const n = i + 1;

          return (
            <div key={fila.clave} className={`metodo-pago ${fila.enabled ? '' : 'apagado'}`}>
              <div className="metodo-pago-cabecera">
                <input
                  type="checkbox"
                  checked={fila.enabled}
                  onChange={(e) => cambiar(fila.clave, { enabled: e.target.checked })}
                  aria-label={`Enseñar ${fila.name || `el método ${n}`} al comprador`}
                  title={fila.enabled ? 'Se enseña al comprador' : 'Oculto: no se enseña'}
                />
                <input
                  type="text"
                  className="premium-input metodo-pago-nombre"
                  value={fila.name}
                  maxLength={60}
                  placeholder="Nombre (ej. Yape, Deuna, Transferencia)"
                  onChange={(e) => cambiar(fila.clave, { name: e.target.value })}
                  aria-label={`Nombre del método ${n}`}
                />
                <div className="metodo-pago-acciones">
                  <button type="button" className="metodo-pago-icono" onClick={() => mover(i, -1)} disabled={i === 0} title="Subir">
                    <ArrowUp size={14} />
                  </button>
                  <button type="button" className="metodo-pago-icono" onClick={() => mover(i, 1)} disabled={i === filas.length - 1} title="Bajar">
                    <ArrowDown size={14} />
                  </button>
                  <button type="button" className="metodo-pago-icono peligro" onClick={() => quitar(fila.clave)} title="Quitar este método">
                    <Trash2 size={14} />
                  </button>
                </div>
              </div>

              <div className="metodo-pago-cuerpo">
                <div className="metodo-pago-textos">
                  <label>
                    <span>Datos para pagar</span>
                    <textarea
                      className="premium-input"
                      rows={2}
                      maxLength={300}
                      value={fila.details}
                      placeholder={fila.pista || 'Número, cuenta o alias, y a nombre de quién'}
                      onChange={(e) => cambiar(fila.clave, { details: e.target.value })}
                    />
                  </label>
                  <label>
                    <span>Instrucciones <em>(opcional)</em></span>
                    <textarea
                      className="premium-input"
                      rows={2}
                      maxLength={500}
                      value={fila.instructions}
                      placeholder="Ej.: envía la captura del pago por WhatsApp"
                      onChange={(e) => cambiar(fila.clave, { instructions: e.target.value })}
                    />
                  </label>
                </div>

                <div className="metodo-pago-qr">
                  <span>QR <em>(opcional)</em></span>
                  {qr ? (
                    <div className="metodo-pago-qr-imagen">
                      <Imagen src={qr} alt={`QR de ${fila.name || `el método ${n}`}`} respaldo={<QrCode size={28} />} />
                      <button
                        type="button"
                        className="metodo-pago-qr-quitar"
                        title="Quitar el QR"
                        onClick={() => cambiar(fila.clave, { qrArchivo: null, qrVistaPrevia: null, quitarQr: Boolean(fila.qrGuardado) })}
                      >
                        <X size={11} />
                      </button>
                    </div>
                  ) : (
                    <label className="metodo-pago-qr-elegir" title={`Imagen del QR: ${pistaDe('imagen')}`}>
                      <ImagePlus size={18} />
                      <span>Subir</span>
                      <input
                        type="file"
                        accept={aceptaDe('imagen')}
                        onChange={(e) => {
                          const archivo = e.target.files?.[0];
                          if (!archivo) return;

                          // UI-15: antes de subir, con el tope del servidor.
                          const problema = problemaDelArchivo(archivo, 'imagen');
                          if (problema) {
                            toast.error(problema);
                            e.target.value = '';
                            return;
                          }

                          cambiar(fila.clave, { qrArchivo: archivo, qrVistaPrevia: URL.createObjectURL(archivo), quitarQr: false });
                        }}
                      />
                    </label>
                  )}
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
