/**
 * Lo que el sistema acepta subir, y la comprobación antes de subirlo (UI-15).
 *
 * `LIMITES_DE_SUBIDA` es una COPIA de `config/subidas.php` del backend: el
 * servidor es la barrera de verdad y este archivo solo avisa antes, para que
 * nadie suba 12 MB por una conexión lenta y se entere al final. Si cambias un
 * número o un tipo aquí, cámbialo allí: `LimitesDeSubidaTest` (PHPUnit) compara
 * las dos y falla si se separan. Antes se separaron sin que nadie lo notara: el
 * formulario de producto decía "Máx 5MB" y el servidor aceptaba 10.
 */
export const LIMITES_DE_SUBIDA = {
  // Foto principal, galería y fotos de variantes: las tres son del producto.
  imagen: { kb: 10240, tipos: ['jpeg', 'jpg', 'png', 'webp'] },
  logo: { kb: 2048, tipos: ['jpeg', 'jpg', 'png', 'webp'] },
  banner: { kb: 5120, tipos: ['jpeg', 'jpg', 'png', 'webp'] },
  favicon: { kb: 512, tipos: ['png', 'ico'] },
  csv: { kb: 4096, tipos: ['csv', 'txt'] },
} as const;

export type TipoDeSubida = keyof typeof LIMITES_DE_SUBIDA;

/**
 * Los tipos MIME con que el navegador presenta cada extensión. Sirven para el
 * `accept` del `<input>` —con ellos el móvil ofrece la galería de fotos, y no
 * solo el explorador de archivos— y para reconocer un archivo cuyo nombre no
 * dice lo que es: un `.jfif` es un JPEG, el navegador lo presenta como
 * `image/jpeg` y el servidor lo acepta.
 */
const MIME_DE: Record<string, string[]> = {
  jpeg: ['image/jpeg'],
  jpg: ['image/jpeg'],
  png: ['image/png'],
  webp: ['image/webp'],
  ico: ['image/x-icon', 'image/vnd.microsoft.icon'],
  csv: ['text/csv'],
  txt: ['text/plain'],
};

/** Cómo se nombra cada extensión en un mensaje: el dueño conoce "JPG", no "jpeg". */
const NOMBRE_DE: Record<string, string> = { jpeg: 'JPG', jpg: 'JPG' };

/**
 * Un tamaño en kilobytes como lo dice una persona: 10240 → "10 MB",
 * 1536 → "1,5 MB", 512 → "512 KB". Igual que `Subidas::legible()` en PHP, que
 * es lo que dice el mensaje del servidor: los dos tienen que decir lo mismo.
 */
export function tamanoLegible(kb: number): string {
  if (kb < 1024) {
    return `${Math.round(kb)} KB`;
  }

  const megas = Math.round((kb / 1024) * 10) / 10;

  return `${String(megas).replace('.', ',')} MB`;
}

/** "JPG, PNG o WEBP": los tipos de una subida, para un texto. */
export function formatosDe(tipo: TipoDeSubida): string {
  const nombres = [...new Set(LIMITES_DE_SUBIDA[tipo].tipos.map((t) => NOMBRE_DE[t] ?? t.toUpperCase()))];

  return nombres.length > 1 ? `${nombres.slice(0, -1).join(', ')} o ${nombres[nombres.length - 1]}` : nombres[0];
}

/** "JPG, PNG o WEBP, máx. 10 MB": lo que se le enseña al dueño junto al campo. */
export function pistaDe(tipo: TipoDeSubida): string {
  return `${formatosDe(tipo)}, máx. ${tamanoLegible(LIMITES_DE_SUBIDA[tipo].kb)}`;
}

/** El `accept` del `<input type="file">`: los MIME y las extensiones. */
export function aceptaDe(tipo: TipoDeSubida): string {
  const { tipos } = LIMITES_DE_SUBIDA[tipo];
  const mimes = tipos.flatMap((t) => MIME_DE[t] ?? []);

  return [...new Set([...mimes, ...tipos.map((t) => `.${t}`)])].join(',');
}

/**
 * Si el archivo se puede subir: `null` si sí, y si no, qué decirle al dueño.
 *
 * El tipo vale si lo dice el MIME o la extensión, y no hace falta que lo digan
 * los dos: con solo la extensión, un `.jfif` —un JPEG normal— se rechazaría
 * aquí y el servidor lo habría aceptado; con solo el MIME, un `.csv` que
 * Windows presenta como `application/vnd.ms-excel`. Aquí se descarta lo
 * evidente; el servidor mira el contenido.
 *
 * El tamaño se compara igual que la regla `max` de Laravel: pasa si pesa como
 * mucho el tope, no si pesa menos.
 */
export function problemaDelArchivo(archivo: File, tipo: TipoDeSubida): string | null {
  const { kb, tipos } = LIMITES_DE_SUBIDA[tipo];
  const partes = archivo.name.split('.');
  const extension = partes.length > 1 ? partes[partes.length - 1].toLowerCase() : '';
  const mimes = tipos.flatMap((t) => MIME_DE[t] ?? []);

  if (!mimes.includes(archivo.type) && !(tipos as readonly string[]).includes(extension)) {
    return `«${archivo.name}» no es un archivo admitido: sube un ${formatosDe(tipo)}.`;
  }

  if (archivo.size > kb * 1024) {
    const pesa = tamanoLegible(archivo.size / 1024);
    const maximo = tamanoLegible(kb);

    return pesa === maximo
      ? `«${archivo.name}» pasa del máximo de ${maximo}.`
      : `«${archivo.name}» pesa ${pesa} y el máximo es ${maximo}.`;
  }

  return null;
}
