import './FichaTecnica.css';

import type { CampoDeSpec } from '../../types';
import { alternarEnVarios, partesDeVarios } from '../../utils/specsFijas';

interface FichaTecnicaProps {
  /** Los campos del tipo de la categoría elegida, tal como los da la API. */
  campos: CampoDeSpec[];
  /** Lo escrito en cada campo, por clave. Ausente o vacío es "sin rellenar". */
  valores: Record<string, string>;
  /** Cómo se llama el tipo, para el título: "Procesadores (CPU)". */
  tipo: string;
  onChange: (clave: string, valor: string) => void;
}

/**
 * Los campos con nombre fijo del tipo de componente (FUN-8, 13.3).
 *
 * Es lo que convierte "adivinar" en "preguntar": el dueño ya no tiene que
 * saber que el armador busca una spec llamada "socket", elige el socket de una
 * lista. Un select donde la lista es cerrada, número con su unidad donde la
 * lleva.
 *
 * Nada aquí es obligatorio: dejar un campo vacío no impide guardar, y el
 * armador dirá que no pudo comprobarlo. Un valor guardado que no está en la
 * lista (de antes de esto, o de un CSV) se enseña con un aviso en vez de
 * desaparecer: el servidor lo rechazaría al guardar y la persona tiene que
 * saber por qué.
 */
export default function FichaTecnica({ campos, valores, tipo, onChange }: FichaTecnicaProps) {
  if (campos.length === 0) return null;

  return (
    <section className="ficha-tecnica" aria-labelledby="ficha-tecnica-titulo">
      <div>
        <h3 id="ficha-tecnica-titulo" className="ficha-tecnica-titulo">Ficha técnica · {tipo}</h3>
        <p className="ficha-tecnica-pista">
          Los marcados con <span className="ficha-tecnica-marca">armador</span> los usa el armador de PC para
          comprobar la compatibilidad. Puedes dejarlos vacíos: el armador avisará de que no pudo comprobarlo.
        </p>
      </div>

      <div className="ficha-tecnica-campos">
        {campos.map((campo) => (
          <Campo key={campo.clave} campo={campo} valor={valores[campo.clave] ?? ''} onChange={(v) => onChange(campo.clave, v)} />
        ))}
      </div>
    </section>
  );
}

function Campo({ campo, valor, onChange }: { campo: CampoDeSpec; valor: string; onChange: (valor: string) => void }) {
  const id = `ficha-${campo.clave}`;

  return (
    <div className={`ficha-campo${campo.tipo === 'varios' ? ' ficha-campo-ancho' : ''}`}>
      <label htmlFor={campo.tipo === 'varios' ? undefined : id} className="ficha-campo-etiqueta">
        {campo.etiqueta}
        {campo.unidad && campo.tipo !== 'numero' ? ` (${campo.unidad})` : ''}
        {campo.armador && <span className="ficha-tecnica-marca">armador</span>}
      </label>

      <Control campo={campo} valor={valor} id={id} onChange={onChange} />

      {campo.ayuda && <p className="ficha-campo-ayuda">{campo.ayuda}</p>}
    </div>
  );
}

function Control({ campo, valor, id, onChange }: { campo: CampoDeSpec; valor: string; id: string; onChange: (valor: string) => void }) {
  if (campo.tipo === 'lista' || campo.tipo === 'si_no') {
    const opciones = campo.tipo === 'si_no'
      ? [{ valor: 'si', texto: 'Sí' }, { valor: 'no', texto: 'No' }]
      : campo.opciones.map((o) => ({ valor: o, texto: campo.unidad ? `${o} ${campo.unidad}` : o }));
    const fueraDeLista = valor !== '' && !opciones.some((o) => o.valor === valor);

    return (
      <>
        <select id={id} value={valor} onChange={(e) => onChange(e.target.value)} className="premium-input select-input">
          <option value="">Sin indicar</option>
          {fueraDeLista && <option value={valor}>{valor} (no está en la lista)</option>}
          {opciones.map((o) => (
            <option key={o.valor} value={o.valor}>{o.texto}</option>
          ))}
        </select>
        {fueraDeLista && <FueraDeLista valor={valor} />}
      </>
    );
  }

  if (campo.tipo === 'varios') {
    const elegidas = partesDeVarios(valor);
    const ajenas = elegidas.filter((p) => !campo.opciones.includes(p));

    return (
      <>
        <div className="ficha-varios" role="group" aria-label={campo.etiqueta}>
          {[...campo.opciones, ...ajenas].map((opcion) => (
            <label key={opcion} className={`ficha-varios-opcion${ajenas.includes(opcion) ? ' ajena' : ''}`}>
              <input
                type="checkbox"
                checked={elegidas.includes(opcion)}
                onChange={() => onChange(alternarEnVarios(valor, opcion, campo))}
              />
              {opcion}
            </label>
          ))}
        </div>
        {ajenas.length > 0 && <FueraDeLista valor={ajenas.join(', ')} />}
      </>
    );
  }

  if (campo.tipo === 'numero') {
    // Texto con teclado numérico y no `type="number"`: este último deja en
    // blanco un valor que no es número ("unos 105W" de un CSV viejo), y la
    // persona vería un campo vacío que el servidor rechaza sin saber por qué.
    const noEsNumero = valor !== '' && !/^\d+$/.test(valor);

    return (
      <>
        <div className="ficha-numero">
          <input
            id={id}
            type="text"
            inputMode="numeric"
            value={valor}
            onChange={(e) => onChange(e.target.value)}
            className="premium-input"
          />
          {campo.unidad && <span className="ficha-numero-unidad">{campo.unidad}</span>}
        </div>
        {noEsNumero && (
          <p className="ficha-campo-aviso" role="alert">
            «{valor}» no es un número entero. Escribe solo la cifra{campo.unidad ? `, en ${campo.unidad}` : ''}.
          </p>
        )}
      </>
    );
  }

  return (
    <input id={id} type="text" maxLength={255} value={valor} onChange={(e) => onChange(e.target.value)} className="premium-input" />
  );
}

function FueraDeLista({ valor }: { valor: string }) {
  return (
    <p className="ficha-campo-aviso" role="alert">
      «{valor}» no está en la lista y no se podrá guardar así. Elige el que corresponda.
    </p>
  );
}
