import type { CampoDeSpec, ComponentType, DefinicionDeSpecs } from '../types';

/**
 * Lo que se hace en el navegador con las specs de nombre fijo (FUN-8).
 *
 * La definición llega de la API (`useSpecsFijas`); aquí no hay ninguna lista
 * de campos. Lo que sí hay es la forma de pintarlas, que repite la de
 * `App\Support\SpecsFijas::paraMostrar()` para que la ficha del SPA y la que ve
 * un buscador digan lo mismo.
 */

export interface SpecParaMostrar {
  clave: string;
  etiqueta: string;
  valor: string;
}

export interface SpecLibre {
  key: string;
  value: string;
}

/** Los campos de un tipo, en orden. Vacío sin tipo o sin definición todavía. */
export function camposDe(definicion: DefinicionDeSpecs | undefined, tipo: ComponentType | null | undefined): CampoDeSpec[] {
  if (!definicion || !tipo) return [];
  return definicion.tipos[tipo] ?? [];
}

/**
 * Todas las claves fijas, de todos los tipos y en orden de aparición. Para
 * pintar no hace falta saber el tipo del producto: una clave repetida entre
 * tipos se llama igual en todos (lo fija `SpecsFijasTest` en la API).
 */
export function todosLosCampos(definicion: DefinicionDeSpecs | undefined): Map<string, CampoDeSpec> {
  const campos = new Map<string, CampoDeSpec>();
  if (!definicion) return campos;

  for (const lista of Object.values(definicion.tipos)) {
    for (const campo of lista ?? []) {
      if (!campos.has(campo.clave)) campos.set(campo.clave, campo);
    }
  }

  return campos;
}

/** "105" → "105 W", "si" → "Sí". Un valor escrito a mano se deja tal cual. */
export function valorParaMostrar(valor: unknown, campo: CampoDeSpec): string {
  const texto = comoTexto(valor);

  if (campo.tipo === 'si_no') {
    if (texto === 'si') return 'Sí';
    if (texto === 'no') return 'No';
    return texto;
  }

  // La unidad solo va pegada a un número limpio: a "unos 105W" se le dejaría
  // "unos 105W W".
  const conUnidad = (campo.tipo === 'numero' || campo.tipo === 'lista') && !!campo.unidad && /^\d+$/.test(texto);

  return conUnidad ? `${texto} ${campo.unidad}` : texto;
}

/** La etiqueta con la que se enseña una clave: la del campo fijo, o la clave misma. */
export function etiquetaDeSpec(clave: string, campos: Map<string, CampoDeSpec>): string {
  return campos.get(clave)?.etiqueta ?? clave;
}

/** El valor de una clave como se enseña: con unidad si es fija, tal cual si es libre. */
export function valorDeSpec(clave: string, valor: unknown, campos: Map<string, CampoDeSpec>): string {
  const campo = campos.get(clave);
  return campo ? valorParaMostrar(valor, campo) : comoTexto(valor);
}

/**
 * Las specs de un producto listas para pintar: primero las fijas, en el orden
 * de la definición, y después las libres como se guardaron.
 */
export function specsParaMostrar(
  specs: Record<string, unknown> | null | undefined,
  definicion: DefinicionDeSpecs | undefined,
): SpecParaMostrar[] {
  if (!specs) return [];

  const campos = todosLosCampos(definicion);
  const fijas: SpecParaMostrar[] = [];
  const libres: SpecParaMostrar[] = [];

  for (const [clave, campo] of campos) {
    if (clave in specs) {
      fijas.push({ clave, etiqueta: campo.etiqueta, valor: valorParaMostrar(specs[clave], campo) });
    }
  }

  for (const [clave, valor] of Object.entries(specs)) {
    if (!campos.has(clave)) {
      libres.push({ clave, etiqueta: clave, valor: comoTexto(valor) });
    }
  }

  return [...fijas, ...libres];
}

/**
 * Reparte las specs guardadas entre los campos fijos del tipo y las filas
 * libres del formulario. Una clave fija de OTRO tipo cae en las libres: el
 * producto no la pregunta, pero el dato no se esconde.
 */
export function separarSpecs(
  specs: Record<string, unknown> | null | undefined,
  campos: CampoDeSpec[],
): { fijas: Record<string, string>; libres: SpecLibre[] } {
  const delTipo = new Set(campos.map((c) => c.clave));
  const fijas: Record<string, string> = {};
  const libres: SpecLibre[] = [];

  for (const [clave, valor] of Object.entries(specs ?? {})) {
    if (delTipo.has(clave)) {
      fijas[clave] = comoTexto(valor);
    } else {
      libres.push({ key: clave, value: comoTexto(valor) });
    }
  }

  return { fijas, libres };
}

/**
 * Lo que se manda al guardar. Las filas libres a medio escribir no viajan, y un
 * campo fijo gana a una fila libre con su mismo nombre.
 */
export function unirSpecs(fijas: Record<string, string>, libres: SpecLibre[]): Record<string, string> {
  const specs: Record<string, string> = {};

  for (const { key, value } of libres) {
    if (key.trim() && value.trim()) specs[key.trim()] = value.trim();
  }

  for (const [clave, valor] of Object.entries(fijas)) {
    if (valor.trim()) specs[clave] = valor.trim();
  }

  return specs;
}

/**
 * Al cambiar la categoría —o al llegar la definición—, se vuelve a repartir:
 * lo que era fijo y el tipo nuevo no pregunta pasa a las libres, y una libre
 * que el tipo nuevo sí pregunta sube a su campo. Así lo que se ve es lo que se
 * guarda. Las filas libres a medio escribir se quedan donde estaban.
 */
export function reacomodarSpecs(
  fijas: Record<string, string>,
  libres: SpecLibre[],
  campos: CampoDeSpec[],
): { fijas: Record<string, string>; libres: SpecLibre[] } {
  const aMedias = libres.filter((l) => !l.key.trim() || !l.value.trim());
  const repartidas = separarSpecs(unirSpecs(fijas, libres), campos);

  return { fijas: repartidas.fijas, libres: [...repartidas.libres, ...aMedias] };
}

/** Las partes de un campo `varios`: "AM4, AM5" → ["AM4", "AM5"]. */
export function partesDeVarios(valor: string): string[] {
  return valor.split(',').map((p) => p.trim()).filter(Boolean);
}

/**
 * Marca o desmarca una opción de un campo `varios` y lo deja como lo guarda el
 * servidor: en el orden de la lista. Lo que no está en la lista se conserva al
 * final, para que se vea y se pueda quitar a mano en vez de perderse.
 */
export function alternarEnVarios(valor: string, opcion: string, campo: CampoDeSpec): string {
  const actuales = new Set(partesDeVarios(valor));

  if (actuales.has(opcion)) actuales.delete(opcion);
  else actuales.add(opcion);

  const conocidas = campo.opciones.filter((o) => actuales.has(o));
  const ajenas = [...actuales].filter((o) => !campo.opciones.includes(o));

  return [...conocidas, ...ajenas].join(', ');
}

function comoTexto(valor: unknown): string {
  if (Array.isArray(valor)) return valor.map((v) => String(v)).join(', ');
  if (typeof valor === 'boolean') return valor ? 'si' : 'no';
  if (valor === null || valor === undefined) return '';
  return String(valor);
}
