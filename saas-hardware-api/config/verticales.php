<?php

/*
|--------------------------------------------------------------------------
| Verticales: el rubro de cada tienda (MOD-19)
|--------------------------------------------------------------------------
|
| Un solo sistema para varios negocios, no un producto por rubro. Casi todo es
| comun (catalogo, variantes, cupones, pedidos, reportes, CSV, planes); lo que
| solo tiene sentido en una tienda de PC son los MODULOS de abajo, y cada
| vertical dice cuales enciende.
|
| Lo resuelve App\Support\Verticales y nadie mas: ni la API ni el frontend
| preguntan `vertical === 'hardware'`, preguntan si la tienda tiene un modulo.
| La tienda viaja con `modulos` ya resueltos en cada respuesta (catalogo
| publico, login, `me`, Configuracion, panel de plataforma).
|
| Mismas reglas que config/plans.php:
|
|   - una vertical que no este en la lista NO enciende todo: cae en 'default';
|   - decide lo que se ENSEÑA, no lo que se guarda. Cambiar a una tienda de
|     vertical no borra nada, y volver la deja como estaba.
|
| `requiere` va como en config/permisos.php (MOD-21): un modulo sin lo que
| requiere se apaga solo. El armador sin ficha tecnica no tiene datos que
| comparar, y la ficha sin tipo de componente no sabe que campos preguntar.
|
| Renombrar una clave de modulo la apaga en todas las verticales que la tenian.
| El frontend copia SOLO las claves de los modulos (utils/verticales.ts), y
| VerticalesTest compara las dos.
|
*/

return [

    /*
    | La de una tienda cuyo `vertical` no este en la lista. Coincide con el
    | valor por defecto de la columna en la migracion de tenants.
    */
    'default' => 'hardware',

    'modulos' => [

        // El selector «¿Qué vende esta categoría?» de Categorías (FUN-8). Sin
        // el, una categoria nueva nace como «otro» y no se deduce del nombre.
        'tipo_de_componente' => [
            'label'    => 'Tipo de componente en las categorías',
            'requiere' => [],
        ],

        // Los campos fijos por tipo de componente (FUN-8): el formulario de
        // producto, su validacion y como se leen en el catalogo.
        'ficha_tecnica' => [
            'label'    => 'Ficha técnica con campos fijos por tipo de componente',
            'requiere' => ['tipo_de_componente'],
        ],

        // El armador de PC: el boton del catalogo, la pagina, sus reglas de
        // compatibilidad, su mensaje de WhatsApp y su URL en el sitemap. Los
        // complementarios de la ficha de producto van con el, porque salen de
        // las mismas parejas de tipos (una placa para un procesador).
        'armador' => [
            'label'    => 'Armador de PC con reglas de compatibilidad',
            'requiere' => ['tipo_de_componente', 'ficha_tecnica'],
        ],

    ],

    'verticales' => [

        'hardware' => [
            'label'   => 'Componentes de PC',
            'modulos' => ['tipo_de_componente', 'ficha_tecnica', 'armador'],
        ],

        // Una tienda de cualquier otro rubro: el catalogo, sin nada de PC. Se
        // asigna desde el panel de plataforma; el registro sigue creando
        // tiendas de hardware mientras se lance solo hardware.
        'general' => [
            'label'   => 'Catálogo general',
            'modulos' => [],
        ],

    ],

];
