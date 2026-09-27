import './Imagen.css';

import { useState, type ImgHTMLAttributes, type ReactNode } from 'react';
import { ImageOff } from 'lucide-react';

type ImagenProps = Omit<ImgHTMLAttributes<HTMLImageElement>, 'src' | 'onError'> & {
  src?: string | null;
  /**
   * Lo que se pinta sin imagen: cuando no hay URL **y** cuando la que hay no
   * carga. Es el mismo hueco que cada pantalla ya tenía para "sin foto", así
   * que una foto rota se ve igual que una que no se subió. Sin él, un icono de
   * imagen rota en la misma caja (clase y estilo) que ocuparía la imagen.
   */
  respaldo?: ReactNode;
};

/**
 * Toda imagen de la aplicación pasa por aquí (`UI-16`), nunca un `<img>` suelto:
 * lo vigila `Imagen.guardia.test.ts`.
 *
 * Ningún `<img>` tenía `onError`. Si el logo apuntaba a una URL que murió, o una
 * foto desaparecía del almacén, cada visitante veía el icono de imagen rota del
 * navegador —y en el logo, además, el texto del `alt` desbordando la cabecera—.
 * El respaldo es local: el único que había, un `via.placeholder.com` en la
 * cuenta del cliente, convertía un hueco en una petición a un tercero.
 */
export default function Imagen({ src, respaldo, alt = '', className, style, ...resto }: ImagenProps) {
  // La URL que falló y no un booleano: si `src` cambia (la galería de la ficha,
  // la vista previa de un formulario), la nueva se intenta otra vez.
  const [fallida, setFallida] = useState<string | null>(null);

  if (!src || src === fallida) {
    if (respaldo !== undefined) return <>{respaldo}</>;

    return (
      <span
        className={className ? `imagen-respaldo ${className}` : 'imagen-respaldo'}
        style={style}
        {...(alt ? { role: 'img', 'aria-label': alt } : { 'aria-hidden': true })}
      >
        <ImageOff size={18} />
      </span>
    );
  }

  return <img {...resto} className={className} style={style} alt={alt} src={src} onError={() => setFallida(src)} />;
}
