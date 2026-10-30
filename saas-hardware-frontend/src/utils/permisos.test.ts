import { describe, expect, it } from 'vitest';
import { marcarArea, marcarPermiso, ordenar, requisitosDe, type AreaDePermisos } from './permisos';

/**
 * Las casillas del editor de roles (MOD-21) tienen que hacer lo mismo que el
 * servidor al guardar (`Permisos::normalizar`): si no, la pantalla enseña un
 * rol y se guarda otro. El catálogo es un trozo del de verdad, con las mismas
 * dependencias.
 */
const areas: AreaDePermisos[] = [
  {
    clave: 'productos',
    etiqueta: 'Productos',
    permisos: [
      { clave: 'productos.ver', etiqueta: 'Ver los productos del panel', requiere: [] },
      { clave: 'productos.editar', etiqueta: 'Crear y editar', requiere: ['productos.ver'] },
      { clave: 'productos.borrar', etiqueta: 'Borrar', requiere: ['productos.ver'] },
      { clave: 'productos.importar', etiqueta: 'Importar', requiere: ['productos.editar'] },
    ],
  },
  {
    clave: 'pedidos',
    etiqueta: 'Pedidos',
    permisos: [
      { clave: 'pedidos.ver', etiqueta: 'Ver los pedidos', requiere: [] },
      { clave: 'pedidos.borrar', etiqueta: 'Borrar pedidos', requiere: ['pedidos.ver'] },
    ],
  },
  {
    clave: 'papelera',
    etiqueta: 'Papelera',
    permisos: [
      { clave: 'papelera.gestionar', etiqueta: 'Papelera', requiere: ['productos.borrar', 'pedidos.borrar'] },
    ],
  },
];

describe('permisos del editor de roles (MOD-21)', () => {
  it('marcar uno marca lo que requiere, en cadena y en el orden del catálogo', () => {
    expect(marcarPermiso([], 'productos.importar', true, areas)).toEqual([
      'productos.ver',
      'productos.editar',
      'productos.importar',
    ]);

    expect(marcarPermiso(['pedidos.ver'], 'papelera.gestionar', true, areas)).toEqual([
      'productos.ver',
      'productos.borrar',
      'pedidos.ver',
      'pedidos.borrar',
      'papelera.gestionar',
    ]);
  });

  it('desmarcar uno quita lo que dependía de él, y nada más', () => {
    const todo = marcarPermiso([], 'papelera.gestionar', true, areas);
    const conImportar = marcarPermiso(todo, 'productos.importar', true, areas);

    expect(marcarPermiso(conImportar, 'productos.ver', false, areas)).toEqual(['pedidos.ver', 'pedidos.borrar']);
    expect(marcarPermiso(conImportar, 'productos.editar', false, areas)).toEqual([
      'productos.ver',
      'productos.borrar',
      'pedidos.ver',
      'pedidos.borrar',
      'papelera.gestionar',
    ]);
  });

  it('«Todo» marca y desmarca el área entera', () => {
    const marcados = marcarArea([], areas[0], true, areas);
    expect(marcados).toEqual(['productos.ver', 'productos.editar', 'productos.borrar', 'productos.importar']);

    expect(marcarArea([...marcados, 'pedidos.ver'], areas[0], false, areas)).toEqual(['pedidos.ver']);
  });

  it('dice lo que requiere un permiso con su nombre', () => {
    expect(requisitosDe('productos.importar', areas)).toEqual(['Crear y editar']);
    expect(requisitosDe('productos.ver', areas)).toEqual([]);
  });

  it('ordena como el catálogo', () => {
    expect(ordenar(['pedidos.ver', 'productos.ver'], areas)).toEqual(['productos.ver', 'pedidos.ver']);
  });
});
