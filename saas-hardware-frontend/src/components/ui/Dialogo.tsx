import './Dialogo.css';

import { useEffect, useId, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { useBloqueoDeScroll } from '../../hooks/useBloqueoDeScroll';

interface DialogoProps {
  titulo: ReactNode;
  /** Una línea bajo el título, para explicar qué hace la ventana. */
  subtitulo?: ReactNode;
  onCerrar: () => void;
  /** Ancho máximo en píxeles. Por defecto 480, el de un formulario corto. */
  ancho?: number;
  /**
   * La clase de la página que abre el diálogo (`page-products`…), para que sus
   * reglas `:where(.page-x) …` sigan alcanzando el contenido: el diálogo va por
   * portal, así que ya no cuelga del árbol de la página.
   */
  className?: string;
  /**
   * El contenido. Lo normal es un `<form className="dialogo-cuerpo">` que
   * termine en un `<div className="dialogo-acciones">`: el cuerpo es lo que
   * hace scroll, y los botones quedan dentro del form para que el submit
   * funcione.
   */
  children: ReactNode;
}

/**
 * La ventana flotante del panel (TEC-11).
 *
 * Antes cada página traía su copia del diálogo —overlay, tarjeta, cabecera,
 * botones— en su propia hoja, con valores que se habían ido separando. Costó
 * dos fallos: `UI-7` (Categorías se quedó con un patrón viejo y un z-index que
 * la dejaba bajo el aviso de verificación) y `UI-8` (Equipo nació sin ninguna de
 * esas reglas). Aquí se decide una vez:
 *
 * - **Va por portal a `.dashboard-layout`.** Las páginas del panel llevan
 *   `animate-fade-in`, cuya animación crea un contexto de apilamiento: dentro de
 *   él, el z-index del velo solo compite con sus hermanos, y la barra superior y
 *   el menú lateral quedaban por encima, sin oscurecer. Páginas ya lo había
 *   resuelto así; las demás no. El destino es el layout y no `<body>` porque la
 *   paleta del panel (clara y oscura) se define en ese elemento.
 * - **Bloquea el scroll de la página** mientras está montado (`UI-9`), en vez de
 *   que cada página tenga que acordarse de llamar a `useBloqueoDeScroll`.
 * - **Se cierra** con la X, pulsando fuera o con Escape.
 *
 * El padre lo monta solo cuando está abierto (`{abierto && <Dialogo …>}`), así
 * el bloqueo de scroll y la escucha de Escape viven exactamente lo que la
 * ventana.
 */
export default function Dialogo({ titulo, subtitulo, onCerrar, ancho = 480, className, children }: DialogoProps) {
  const idTitulo = useId();

  useBloqueoDeScroll(true);

  useEffect(() => {
    const alPulsar = (evento: KeyboardEvent) => {
      if (evento.key === 'Escape') {
        onCerrar();
      }
    };

    document.addEventListener('keydown', alPulsar);

    return () => document.removeEventListener('keydown', alPulsar);
  }, [onCerrar]);

  return createPortal(
    <div className={`dialogo-velo ${className ?? ''}`} onClick={onCerrar}>
      <div
        className="dialogo animate-scale-in"
        role="dialog"
        aria-modal="true"
        aria-labelledby={idTitulo}
        style={{ maxWidth: `${ancho}px` }}
        onClick={(evento) => evento.stopPropagation()}
      >
        <header className="dialogo-cabecera">
          <div>
            <h3 id={idTitulo}>{titulo}</h3>
            {subtitulo && <p className="dialogo-subtitulo">{subtitulo}</p>}
          </div>
          <button type="button" className="dialogo-cerrar" onClick={onCerrar} aria-label="Cerrar">
            <X size={20} />
          </button>
        </header>

        {children}
      </div>
    </div>,
    document.querySelector('.dashboard-layout') ?? document.body,
  );
}
