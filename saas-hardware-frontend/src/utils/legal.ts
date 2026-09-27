/**
 * Quién presta el servicio, para las páginas legales (INF-15).
 *
 * Paddle, la pasarela de cobro (7.7b), revisa la web antes de aprobar la cuenta
 * y pide términos y condiciones, política de privacidad y política de
 * reembolsos, con el nombre de quien vende. Ese nombre y el correo de contacto
 * son datos personales del dueño —cobra como persona natural—, así que no van
 * escritos en el código sino en dos variables del build, como
 * `VITE_PLATFORM_HOSTS`: `VITE_LEGAL_TITULAR` y `VITE_LEGAL_CORREO`.
 *
 * Si falta alguna, las páginas no se quedan en blanco ni se inventan un
 * nombre: lo dicen en pantalla (`faltan`), para que no se publique ni se mande
 * a revisar una página sin titular sin que nadie lo vea.
 */

export const NOMBRE_DEL_SERVICIO = 'Catálogo de Componentes PC';

/** País desde el que se presta el servicio, y cuya ley se aplica. */
export const PAIS_DEL_TITULAR = 'Ecuador';

/** Fecha de la última versión de los textos: cambiarla al cambiarlos. */
export const ULTIMA_ACTUALIZACION = '26 de septiembre de 2026';

/**
 * Las tres páginas y sus rutas. Las rutas son también slugs reservados en el
 * backend (`AuthController::register`): ninguna tienda puede llamarse así, o
 * su catálogo quedaría tapado por la página legal.
 */
export const PAGINAS_LEGALES = [
  { ruta: '/terminos', titulo: 'Términos y condiciones' },
  { ruta: '/privacidad', titulo: 'Política de privacidad' },
  { ruta: '/reembolsos', titulo: 'Política de reembolsos' },
] as const;

type Env = Record<string, unknown>;

export type DatosLegales = {
  titular: string | null;
  correo: string | null;
  /** Los nombres de las variables que faltan, para enseñarlo. */
  faltan: string[];
};

const limpio = (valor: unknown) => (typeof valor === 'string' && valor.trim() !== '' ? valor.trim() : null);

export function datosLegales(env: Env = import.meta.env): DatosLegales {
  const titular = limpio(env.VITE_LEGAL_TITULAR);
  const correo = limpio(env.VITE_LEGAL_CORREO);

  return {
    titular,
    correo,
    faltan: [!titular && 'VITE_LEGAL_TITULAR', !correo && 'VITE_LEGAL_CORREO'].filter(
      (nombre): nombre is string => typeof nombre === 'string',
    ),
  };
}
