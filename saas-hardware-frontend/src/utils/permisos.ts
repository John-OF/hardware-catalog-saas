/**
 * Las claves de los permisos por acción del equipo (MOD-21).
 *
 * Copia **solo de las claves** de `config/permisos.php`: las etiquetas, las
 * áreas y lo que requiere cada una vienen de `GET /api/permissions`, que es lo
 * que pinta el editor de roles. Esta lista existe para que `usePuede('...')`
 * no acepte una clave mal escrita, que escondería un botón sin avisar a nadie.
 * `PermisosPorAccionTest` (en la API) falla si las dos listas se separan.
 *
 * Esconder es lo único que hace el panel con esto: la barrera de verdad es el
 * middleware `permiso:` de cada ruta.
 */
export const PERMISOS = [
  'resumen.ver',
  'productos.ver',
  'productos.editar',
  'productos.borrar',
  'productos.importar',
  'productos.masivo',
  'productos.exportar',
  'pedidos.ver',
  'pedidos.gestionar',
  'pedidos.borrar',
  'pedidos.exportar',
  'costos.ver',
  'clientes.ver',
  'reportes.ver',
  'reportes.exportar',
  'resenas.gestionar',
  'espera.gestionar',
  'cupones.gestionar',
  'categorias.gestionar',
  'paginas.gestionar',
  'papelera.gestionar',
  'actividad.ver',
  'tienda.configurar',
] as const;

export type Permiso = (typeof PERMISOS)[number];

/** Un permiso tal como lo describe `GET /api/permissions`. */
export interface PermisoDelCatalogo {
  clave: Permiso;
  etiqueta: string;
  requiere: Permiso[];
}

export interface AreaDePermisos {
  clave: string;
  etiqueta: string;
  permisos: PermisoDelCatalogo[];
}

/** Todos los permisos de las áreas, en un mapa por clave. */
const indexar = (areas: AreaDePermisos[]): Map<Permiso, PermisoDelCatalogo> =>
  new Map(areas.flatMap((area) => area.permisos.map((p) => [p.clave, p] as const)));

/**
 * Marcar un permiso marca también lo que requiere (y lo que requiere eso): no
 * se edita lo que no se ve. Es la misma regla que aplica el servidor al
 * guardar (`Permisos::normalizar`), aquí solo para que la pantalla no engañe.
 */
export const marcarPermiso = (
  actuales: Permiso[],
  clave: Permiso,
  marcado: boolean,
  areas: AreaDePermisos[],
): Permiso[] => {
  const catalogo = indexar(areas);

  if (marcado) {
    const resultado = new Set(actuales);
    const vistos = new Set<Permiso>();
    const pendientes: Permiso[] = [clave];

    while (pendientes.length > 0) {
      const siguiente = pendientes.pop() as Permiso;
      if (vistos.has(siguiente)) continue;
      vistos.add(siguiente);
      resultado.add(siguiente);
      pendientes.push(...(catalogo.get(siguiente)?.requiere ?? []));
    }

    return ordenar([...resultado], areas);
  }

  // Desmarcar quita también lo que dependía de él: "importar" sin "editar"
  // no lo aceptaría el servidor y la casilla se quedaría mintiendo.
  const quitados = new Set<Permiso>([clave]);
  let cambio = true;

  while (cambio) {
    cambio = false;
    for (const p of actuales) {
      if (quitados.has(p)) continue;
      if ((catalogo.get(p)?.requiere ?? []).some((r) => quitados.has(r))) {
        quitados.add(p);
        cambio = true;
      }
    }
  }

  return actuales.filter((p) => !quitados.has(p));
};

/** "Todo" en un área: marca o desmarca sus permisos, con sus dependencias. */
export const marcarArea = (
  actuales: Permiso[],
  area: AreaDePermisos,
  marcado: boolean,
  areas: AreaDePermisos[],
): Permiso[] =>
  area.permisos.reduce((acumulado, p) => marcarPermiso(acumulado, p.clave, marcado, areas), actuales);

/** En el orden del catálogo, que es el de la pantalla. */
export const ordenar = (permisos: Permiso[], areas: AreaDePermisos[]): Permiso[] => {
  const orden = areas.flatMap((area) => area.permisos.map((p) => p.clave));
  return [...permisos].sort((a, b) => orden.indexOf(a) - orden.indexOf(b));
};

/** Lo que requiere un permiso, por su nombre: «Requiere: Ver los productos del panel». */
export const requisitosDe = (clave: Permiso, areas: AreaDePermisos[]): string[] => {
  const catalogo = indexar(areas);
  return (catalogo.get(clave)?.requiere ?? []).map((r) => catalogo.get(r)?.etiqueta ?? r);
};
