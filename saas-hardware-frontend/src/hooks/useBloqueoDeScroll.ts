import { useEffect } from 'react';

/**
 * Congela el scroll de la pagina mientras hay una ventana flotante abierta.
 *
 * **El problema que resuelve** (UI-9): con un modal abierto, la rueda del raton
 * mueve su contenido hasta que llega al final — y a partir de ahi el navegador
 * sigue el movimiento en la pagina de detras (*scroll chaining*). El resultado
 * es que al cerrar el modal el catalogo o la lista estan en otro sitio, sin que
 * nadie lo haya pedido; y con modales cortos, la rueda mueve el fondo desde el
 * primer giro.
 *
 * **Por que bloquear en vez de repartir por donde este el cursor.** La segunda
 * opcion suena mas fina pero no lo es: el navegador ya reparte por cursor, y eso
 * es justo lo que produce el salto en cuanto el puntero pasa por encima del
 * fondo oscurecido. Ademas hace que el mismo gesto haga cosas distintas segun un
 * pixel, que es peor que una regla clara: **mientras hay un modal, la pagina no
 * se mueve**. Es lo que hace cualquier interfaz con dialogos.
 *
 * `overscroll-behavior: contain` en el propio modal (esta en las hojas de cada
 * pagina) tapa el caso del final del recorrido; esto tapa el resto, incluido el
 * fondo cuando el cursor no esta sobre el modal.
 *
 * Restaura el valor que hubiera antes y no un `''` a ciegas, para no pisar un
 * bloqueo puesto por otro modal que siga abierto debajo.
 */
export function useBloqueoDeScroll(activo: boolean): void {
  useEffect(() => {
    if (!activo) {
      return;
    }

    const anterior = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = anterior;
    };
  }, [activo]);
}
