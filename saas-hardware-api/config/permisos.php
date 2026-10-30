<?php

/*
|--------------------------------------------------------------------------
| Permisos por acción del equipo de una tienda (MOD-21)
|--------------------------------------------------------------------------
|
| Lo que un rol del equipo puede marcar. Un administrador los tiene todos sin
| marcar nada; un rol del equipo (`team_roles`) tiene solo los que se le
| marquen. Quien lo comprueba es `App\Support\Permisos`, y en las rutas el
| middleware `permiso:<clave>`.
|
| **Las claves se guardan en la base** (`team_roles.permissions`), así que
| renombrar una deja sin ella a todos los roles que la tenían: es quitar un
| permiso, no cambiarle el nombre. La etiqueta se cambia libre.
|
| `requiere`: sin esos, este no tiene sentido -no se edita lo que no se ve, la
| papelera deja deshacer un borrado-. Al guardar un rol se añaden solos, y al
| leerlo un permiso al que le falta lo que requiere no vale (falla en cerrado,
| por si esta lista cambia después de guardar un rol).
|
| **El equipo y el plan no están aquí a propósito**: los gestiona solo un
| administrador. Un rol que pudiera tocar el equipo podría darse a sí mismo
| cualquier permiso.
|
| El frontend no tiene copia de las etiquetas -las pide a `GET /api/permissions`-
| pero sí de las claves, en `utils/permisos.ts`, para que un error de dedo no
| esconda un botón en silencio. `PermisosPorAccionTest` compara las dos listas.
*/

return [
    'areas' => [
        'resumen' => [
            'etiqueta' => 'Resumen',
            'permisos' => [
                'resumen.ver' => ['etiqueta' => 'Ver el resumen: ventas, pedidos recientes y productos más vistos'],
            ],
        ],
        'productos' => [
            'etiqueta' => 'Productos',
            'permisos' => [
                'productos.ver'      => ['etiqueta' => 'Ver los productos del panel'],
                'productos.editar'   => ['etiqueta' => 'Crear, editar, duplicar y reordenar productos', 'requiere' => ['productos.ver']],
                'productos.borrar'   => ['etiqueta' => 'Mandar productos a la papelera', 'requiere' => ['productos.ver']],
                'productos.importar' => ['etiqueta' => 'Importar productos desde un CSV', 'requiere' => ['productos.editar']],
                'productos.masivo'   => ['etiqueta' => 'Publicar, ocultar o cambiar el precio de varios productos a la vez', 'requiere' => ['productos.editar']],
                'productos.exportar' => ['etiqueta' => 'Exportar el catálogo a CSV', 'requiere' => ['productos.ver']],
            ],
        ],
        'pedidos' => [
            'etiqueta' => 'Pedidos',
            'permisos' => [
                'pedidos.ver'       => ['etiqueta' => 'Ver los pedidos y descargar su cotización en PDF'],
                // Vender en el mostrador es elegir productos del catálogo.
                'pedidos.gestionar' => ['etiqueta' => 'Cambiar el estado de los pedidos y registrar ventas de mostrador', 'requiere' => ['pedidos.ver', 'productos.ver']],
                'pedidos.borrar'    => ['etiqueta' => 'Mandar pedidos a la papelera (devuelve su stock)', 'requiere' => ['pedidos.ver']],
                'pedidos.exportar'  => ['etiqueta' => 'Exportar los pedidos a CSV, con los datos de contacto de cada cliente', 'requiere' => ['pedidos.ver']],
            ],
        ],
        'costos' => [
            'etiqueta' => 'Costo y utilidad',
            'permisos' => [
                // Cruza tres pantallas (productos, pedidos y reportes) y por eso
                // va en su propia área: es el dato con el que se negocia con el
                // proveedor (MOD-6), no una parte de ninguna de las tres.
                'costos.ver' => ['etiqueta' => 'Ver y escribir el costo de compra, y ver el margen y la utilidad'],
            ],
        ],
        'clientes' => [
            'etiqueta' => 'Clientes',
            'permisos' => [
                'clientes.ver' => ['etiqueta' => 'Ver los clientes y sus datos de contacto'],
            ],
        ],
        'reportes' => [
            'etiqueta' => 'Reportes',
            'permisos' => [
                'reportes.ver'      => ['etiqueta' => 'Ver las ventas por periodo, los más vendidos y el stock bajo'],
                'reportes.exportar' => ['etiqueta' => 'Exportar los reportes a CSV', 'requiere' => ['reportes.ver']],
            ],
        ],
        'resenas' => [
            'etiqueta' => 'Reseñas',
            'permisos' => [
                'resenas.gestionar' => ['etiqueta' => 'Ver, aprobar y borrar reseñas'],
            ],
        ],
        'espera' => [
            'etiqueta' => 'Lista de espera',
            'permisos' => [
                'espera.gestionar' => ['etiqueta' => 'Ver la lista de espera, marcar avisos y borrarlos'],
            ],
        ],
        'cupones' => [
            'etiqueta' => 'Cupones',
            'permisos' => [
                'cupones.gestionar' => ['etiqueta' => 'Crear, editar y borrar cupones de descuento'],
            ],
        ],
        'categorias' => [
            'etiqueta' => 'Categorías',
            'permisos' => [
                'categorias.gestionar' => ['etiqueta' => 'Crear, editar, ordenar y borrar categorías'],
            ],
        ],
        'paginas' => [
            'etiqueta' => 'Páginas',
            'permisos' => [
                'paginas.gestionar' => ['etiqueta' => 'Crear, editar y borrar páginas informativas'],
            ],
        ],
        'papelera' => [
            'etiqueta' => 'Papelera',
            'permisos' => [
                // Si se pudiera restaurar sin poder borrar, restaurar y volver a
                // borrar sería borrar: por eso pide los dos borrados (MOD-8).
                'papelera.gestionar' => ['etiqueta' => 'Restaurar, borrar para siempre y vaciar la papelera', 'requiere' => ['productos.borrar', 'pedidos.borrar']],
            ],
        ],
        'actividad' => [
            'etiqueta' => 'Actividad',
            'permisos' => [
                'actividad.ver' => ['etiqueta' => 'Ver quién cambió qué en el panel'],
            ],
        ],
        'configuracion' => [
            'etiqueta' => 'Configuración',
            'permisos' => [
                'tienda.configurar' => ['etiqueta' => 'Cambiar la configuración, la apariencia y el dominio de la tienda'],
            ],
        ],
    ],

    /*
    | El rol que trae toda tienda nueva, «Colaborador»: lo mismo que podía el
    | rol `staff` fijo de FUN-4, para que pasar a permisos por acción no le
    | cambiara nada a nadie. Se puede editar o borrar como cualquier otro.
    */
    'colaborador' => [
        'resumen.ver',
        'productos.ver',
        'productos.editar',
        'pedidos.ver',
        'pedidos.gestionar',
        'clientes.ver',
        'reportes.ver',
        'resenas.gestionar',
        'espera.gestionar',
    ],
];
