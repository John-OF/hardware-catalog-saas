import type { ProductVariant, VariantOption } from '../types';

/**
 * Las variantes mientras se editan en el formulario de producto del panel
 * (MOD-5). Va aparte de `<EditorDeVariantes>` para que ese archivo solo exporte
 * el componente (la recarga en caliente de Vite lo necesita).
 */

/** Lo mismo que el backend (`ProductVariant::MAXIMO_POR_PRODUCTO`). */
export const MAXIMO_DE_VARIANTES = 30;

/** Cuántas opciones puede tener una variante ("Capacidad", "Color", "Velocidad"). */
export const MAXIMO_DE_EJES = 3;

/** Una variante mientras se edita en el formulario. Los números van como texto, igual que el resto del formulario. */
export interface VarianteEnFormulario {
  /** Clave local para React: las nuevas todavía no tienen `id`. */
  clave: string;
  id?: string;
  /** Un valor por eje, en el mismo orden que `ejes`. */
  valores: string[];
  sku: string;
  price: string;
  sale_price: string;
  /** Costo de compra (MOD-6). Vacío para staff, que ni ve la columna. */
  cost: string;
  stock: string;
  low_stock_threshold: string;
  /** Foto nueva elegida, que se sube al guardar. */
  imagen: File | null;
  /** Lo que se enseña: la miniatura guardada o la foto nueva. */
  vistaPrevia: string | null;
  /** Marcar para borrar la foto guardada. */
  quitarImagen: boolean;
}

const nuevaClave = () => `nueva-${Math.random().toString(36).slice(2, 10)}`;

export const varianteVacia = (ejes: string[]): VarianteEnFormulario => ({
  clave: nuevaClave(),
  valores: ejes.map(() => ''),
  sku: '',
  price: '',
  sale_price: '',
  cost: '',
  stock: '',
  low_stock_threshold: '5',
  imagen: null,
  vistaPrevia: null,
  quitarImagen: false,
});

/**
 * De las variantes guardadas a lo que edita el formulario. Los ejes salen de los
 * nombres de opción en el orden en que aparecen: una variante a la que le falte
 * uno se queda con ese valor vacío, y el formulario pide rellenarlo.
 */
export const variantesDesdeProducto = (variantes: ProductVariant[] = []) => {
  const ejes: string[] = [];
  variantes.forEach((v) => v.options.forEach((o) => {
    if (!ejes.includes(o.name)) ejes.push(o.name);
  }));

  const filas: VarianteEnFormulario[] = variantes.map((v) => ({
    clave: v.id,
    id: v.id,
    valores: ejes.map((eje) => v.options.find((o) => o.name === eje)?.value ?? ''),
    sku: v.sku ?? '',
    price: String(v.price),
    sale_price: v.sale_price !== null ? String(v.sale_price) : '',
    cost: v.cost !== null && v.cost !== undefined ? String(v.cost) : '',
    stock: String(v.stock),
    low_stock_threshold: String(v.low_stock_threshold ?? 5),
    imagen: null,
    vistaPrevia: v.thumbnail_url ?? v.image_url,
    quitarImagen: false,
  }));

  return { ejes, filas };
};

/**
 * Qué falta para poder guardar. Devuelve el primer problema en palabras del
 * dueño, o null. El backend valida lo mismo; esto evita el viaje de ida y vuelta
 * con un mensaje de campo que el formulario no sabría señalar.
 */
export const problemaDeVariantes = (ejes: string[], filas: VarianteEnFormulario[]): string | null => {
  if (filas.length === 0) return null;
  if (ejes.some((e) => !e.trim())) return 'Ponle nombre a cada opción de las variantes (por ejemplo, "Capacidad").';

  const vistas = new Set<string>();
  for (const [i, fila] of filas.entries()) {
    const n = i + 1;
    if (fila.valores.some((v) => !v.trim())) return `A la variante ${n} le falta el valor de alguna opción.`;
    if (fila.price === '' || Number(fila.price) < 0) return `A la variante ${n} le falta el precio.`;
    if (fila.stock === '' || Number(fila.stock) < 0) return `A la variante ${n} le falta el stock.`;
    if (fila.sale_price !== '' && Number(fila.sale_price) >= Number(fila.price)) {
      return `En la variante ${n}, el precio de oferta debe ser menor que el regular.`;
    }
    const clave = fila.valores.map((v) => v.trim().toLowerCase()).join('|');
    if (vistas.has(clave)) return `La variante ${n} repite las mismas opciones que otra.`;
    vistas.add(clave);
  }

  return null;
};

/**
 * Lo que se manda al backend: la lista en JSON y las fotos por su posición.
 *
 * `incluirCosto` es false para staff, y entonces la clave `cost` **no viaja**.
 * No es lo mismo que mandarla vacía: el backend entiende ausente como "no lo
 * toques" y null como "bórralo" (MOD-6), así que mandarla vacía dejaría a cada
 * vendedor borrando los costos al editar una variante.
 */
export const agregarVariantesAlFormulario = (
  formData: FormData,
  ejes: string[],
  filas: VarianteEnFormulario[],
  incluirCosto = false,
) => {
  const lista = filas.map((fila) => ({
    ...(fila.id ? { id: fila.id } : {}),
    options: ejes.map((name, i): VariantOption => ({ name: name.trim(), value: fila.valores[i].trim() })),
    sku: fila.sku.trim() || null,
    price: fila.price,
    sale_price: fila.sale_price === '' ? null : fila.sale_price,
    ...(incluirCosto ? { cost: fila.cost === '' ? null : fila.cost } : {}),
    stock: fila.stock,
    low_stock_threshold: fila.low_stock_threshold || '5',
    remove_image: fila.quitarImagen,
  }));

  formData.append('variants', JSON.stringify(lista));
  filas.forEach((fila, i) => {
    if (fila.imagen) formData.append(`variant_images[${i}]`, fila.imagen);
  });
};
