/**
 * Cuentas de prueba para entrar con un clic en los dos logins (`/login` y
 * `/platform/login`), sólo en desarrollo.
 *
 * Salen de la variable `CUENTAS_DE_PRUEBA`, que va en `.env.development.local`
 * (fuera de git, como todo `.env.*`): un JSON con una lista de
 * `{ tipo, etiqueta, email, password }`, donde `tipo` es `tienda` o
 * `plataforma`. Las contraseñas son las de la base local.
 *
 * No llegan nunca al build de producción, por dos lados a la vez:
 *  - la variable no lleva `VITE_`, así que no entra en `import.meta.env`; la
 *    inyecta `vite.config.ts` como `__CUENTAS_DE_PRUEBA__`, y sólo cuando corre
 *    el servidor de desarrollo. En `vite build` vale `""`;
 *  - y `cuentasDePrueba()` devuelve vacío si no es `import.meta.env.DEV`.
 */

declare const __CUENTAS_DE_PRUEBA__: string | undefined;

export type TipoDeCuenta = 'tienda' | 'plataforma';

export type CuentaDePrueba = {
  tipo: TipoDeCuenta;
  etiqueta: string;
  email: string;
  password: string;
};

/** Dónde entra cada tipo de cuenta. */
export const LOGIN_DE: Record<TipoDeCuenta, string> = {
  tienda: '/login',
  plataforma: '/platform/login',
};

const texto = (valor: unknown) => (typeof valor === 'string' && valor.trim() !== '' ? valor.trim() : null);

/**
 * Lee la lista. Lo que no se entiende se descarta en vez de romper el login:
 * un JSON mal escrito deja el login como siempre y lo avisa en la consola.
 */
export function interpretarCuentasDePrueba(crudo: unknown): CuentaDePrueba[] {
  if (typeof crudo !== 'string' || crudo.trim() === '') return [];

  let datos: unknown;
  try {
    datos = JSON.parse(crudo);
  } catch {
    console.warn('CUENTAS_DE_PRUEBA no es un JSON válido: se ignora.');
    return [];
  }

  if (!Array.isArray(datos)) return [];

  return datos.flatMap((fila: unknown): CuentaDePrueba[] => {
    if (typeof fila !== 'object' || fila === null) return [];
    const { tipo, etiqueta, email, password } = fila as Record<string, unknown>;
    const correo = texto(email);
    const clave = typeof password === 'string' && password !== '' ? password : null;

    if ((tipo !== 'tienda' && tipo !== 'plataforma') || !correo || !clave) return [];

    return [{ tipo, etiqueta: texto(etiqueta) ?? correo, email: correo, password: clave }];
  });
}

export function cuentasDePrueba(): CuentaDePrueba[] {
  if (!import.meta.env.DEV || typeof __CUENTAS_DE_PRUEBA__ === 'undefined') return [];

  return interpretarCuentasDePrueba(__CUENTAS_DE_PRUEBA__);
}
