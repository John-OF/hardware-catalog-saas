import type { PaymentMethod } from '../types';

/**
 * Los métodos de pago en el formulario de Configuración (MOD-23).
 *
 * La tienda arma su lista: nombre, datos para pagar, instrucciones y, si
 * quiere, la imagen de su QR. Para no empezar de cero, se le sugieren los que
 * se usan en su país —deducido de su WhatsApp, igual que el prefijo del
 * checkout—, y puede escribir cualquier otro.
 */

/** Copia de `MetodosDePago::MAXIMO` del backend, que es quien manda. */
export const MAXIMO_DE_METODOS = 8;

export interface MetodoEnFormulario {
  /** Clave local, para React. No viaja. */
  clave: string;
  /** El del backend; null en uno nuevo. */
  id: string | null;
  name: string;
  details: string;
  instructions: string;
  enabled: boolean;
  /** El QR que ya tenía guardado (URL), o null. */
  qrGuardado: string | null;
  /** Un QR nuevo elegido en este formulario. */
  qrArchivo: File | null;
  qrVistaPrevia: string | null;
  /** Quitar el QR guardado al guardar. */
  quitarQr: boolean;
  /** Qué escribir en «Datos para pagar», de la sugerencia con la que nació. */
  pista: string;
}

export interface MetodoSugerido {
  name: string;
  pista: string;
}

const TRANSFERENCIA: MetodoSugerido = {
  name: 'Transferencia bancaria',
  pista: 'Banco, tipo y número de cuenta, y a nombre de quién',
};
const EFECTIVO: MetodoSugerido = { name: 'Efectivo contra entrega', pista: '' };
const CELULAR = 'Número de celular y a nombre de quién';

/**
 * Los métodos más usados en cada país, por el prefijo de su teléfono (el mismo
 * que `COUNTRY_CODES` de `utils/phone.ts`). Solo son un punto de partida: el
 * dueño escribe sus datos y puede añadir cualquier otro.
 */
export const SUGERIDOS_POR_PAIS: Record<string, MetodoSugerido[]> = {
  '+51': [{ name: 'Yape', pista: CELULAR }, { name: 'Plin', pista: CELULAR }, TRANSFERENCIA, EFECTIVO],
  '+593': [{ name: 'Deuna', pista: CELULAR }, TRANSFERENCIA, EFECTIVO],
  '+57': [{ name: 'Nequi', pista: CELULAR }, { name: 'Daviplata', pista: CELULAR }, TRANSFERENCIA, EFECTIVO],
  '+52': [{ name: 'Transferencia SPEI', pista: 'Banco, CLABE y a nombre de quién' }, { name: 'Depósito en OXXO', pista: 'Número de tarjeta y a nombre de quién' }, EFECTIVO],
  '+56': [TRANSFERENCIA, EFECTIVO],
  '+54': [{ name: 'Transferencia (CBU o alias)', pista: 'CBU o alias, y a nombre de quién' }, { name: 'Mercado Pago', pista: 'Alias o CVU, y a nombre de quién' }, EFECTIVO],
  '+591': [{ name: 'Pago por QR', pista: 'Banco y a nombre de quién' }, TRANSFERENCIA, EFECTIVO],
  '+58': [{ name: 'Pago Móvil', pista: 'Banco, teléfono y cédula' }, TRANSFERENCIA, { name: 'Zelle', pista: 'Correo y a nombre de quién' }, EFECTIVO],
  '+595': [TRANSFERENCIA, { name: 'Giros Tigo', pista: CELULAR }, EFECTIVO],
  '+598': [TRANSFERENCIA, { name: 'Mercado Pago', pista: 'Alias y a nombre de quién' }, EFECTIVO],
  '+502': [TRANSFERENCIA, EFECTIVO],
  '+506': [{ name: 'SINPE Móvil', pista: CELULAR }, TRANSFERENCIA, EFECTIVO],
  '+55': [{ name: 'Pix', pista: 'Clave Pix y a nombre de quién' }, TRANSFERENCIA, EFECTIVO],
  '+34': [{ name: 'Bizum', pista: CELULAR }, TRANSFERENCIA, EFECTIVO],
  '+1': [{ name: 'Zelle', pista: 'Correo o teléfono, y a nombre de quién' }, TRANSFERENCIA, EFECTIVO],
};

/** Para un país que no está en la lista. */
export const SUGERIDOS_POR_DEFECTO: MetodoSugerido[] = [TRANSFERENCIA, EFECTIVO];

const normalizar = (texto: string) => texto.trim().toLocaleLowerCase('es');

/** Las sugerencias del país que la tienda todavía no tiene (por nombre, sin mayúsculas). */
export function sugeridosPara(prefijo: string, actuales: Pick<MetodoEnFormulario, 'name'>[]): MetodoSugerido[] {
  const tiene = new Set(actuales.map((m) => normalizar(m.name)));
  return (SUGERIDOS_POR_PAIS[prefijo] ?? SUGERIDOS_POR_DEFECTO).filter((s) => !tiene.has(normalizar(s.name)));
}

let siguiente = 0;
const nuevaClave = () => `metodo-${++siguiente}`;

export function metodoNuevo(sugerido?: MetodoSugerido): MetodoEnFormulario {
  return {
    clave: nuevaClave(),
    id: null,
    name: sugerido?.name ?? '',
    details: '',
    instructions: '',
    enabled: true,
    qrGuardado: null,
    qrArchivo: null,
    qrVistaPrevia: null,
    quitarQr: false,
    pista: sugerido?.pista ?? '',
  };
}

/** Lo que tiene guardado la tienda, como filas del formulario. */
export function metodosDesdeTienda(lista?: PaymentMethod[] | null): MetodoEnFormulario[] {
  if (!Array.isArray(lista)) return [];

  return lista.map((m) => ({
    ...metodoNuevo(),
    id: m.id,
    name: m.name ?? '',
    details: m.details ?? '',
    instructions: m.instructions ?? '',
    enabled: m.enabled !== false,
    qrGuardado: m.qr_url ?? null,
  }));
}

/** Qué impide guardar, en palabras del dueño, o null. El backend valida lo mismo. */
export function problemaDeMetodos(filas: MetodoEnFormulario[]): string | null {
  if (filas.length > MAXIMO_DE_METODOS) return `Puedes tener hasta ${MAXIMO_DE_METODOS} métodos de pago.`;

  const sinNombre = filas.findIndex((f) => !f.name.trim());
  if (sinNombre >= 0) return `Ponle nombre al método de pago ${sinNombre + 1} (por ejemplo, «Yape» o «Transferencia»).`;

  return null;
}

/**
 * Lo que viaja en el `FormData` de `PUT /tenant`: la lista entera, en su orden,
 * y el QR de cada uno por su posición. **La lista manda**: lo que no va se
 * quita. Una lista vacía se manda como `payment_methods=''`, que es la única
 * forma de decir «ninguno» en un FormData (sin la clave, no se tocarían).
 */
export function agregarMetodosAlFormulario(fd: FormData, filas: MetodoEnFormulario[]): void {
  if (filas.length === 0) {
    fd.append('payment_methods', '');
    return;
  }

  filas.forEach((fila, i) => {
    if (fila.id) fd.append(`payment_methods[${i}][id]`, fila.id);
    fd.append(`payment_methods[${i}][name]`, fila.name.trim());
    fd.append(`payment_methods[${i}][details]`, fila.details.trim());
    fd.append(`payment_methods[${i}][instructions]`, fila.instructions.trim());
    // '1'/'0', nunca '' (README, booleanos en FormData).
    fd.append(`payment_methods[${i}][enabled]`, fila.enabled ? '1' : '0');
    if (fila.quitarQr) fd.append(`payment_methods[${i}][remove_qr]`, '1');
    if (fila.qrArchivo) fd.append(`payment_method_qr[${i}]`, fila.qrArchivo);
  });
}
