<?php

/*
|--------------------------------------------------------------------------
| Planes y sus limites (SAAS-3 — paso 7.7a del backlog)
|--------------------------------------------------------------------------
|
| Hasta aqui `tenants.plan` era decorativo: se guardaba, se pintaba como badge
| en el panel y no decidia nada. Esta es la matriz que le da significado.
|
| Vive en config y no en una tabla a proposito. Los limites cambian por una
| decision comercial, no por un cambio de esquema, y una config se puede fijar
| en un test con config()->set() sin tocar la base ni sembrar filas.
|
| Convenciones de los valores:
|
|   - un entero  -> tope de cuantos se pueden crear
|   - null       -> sin tope
|   - true/false -> la funcion esta disponible o no
|
| OJO con `null`: significa "ilimitado", que es lo contrario de "no definido".
| Por eso un plan que no exista en esta lista NO cae en null sino en el plan por
| defecto (ver App\Support\PlanGate). Un `plan` escrito a mano en la base o
| sobrante de una version anterior tiene que fallar en cerrado, igual que el
| aislamiento entre tiendas (AUD-4): equivocarse hacia "gratis total" es peor
| que equivocarse hacia "el plan minimo".
|
| Los topes de aqui son la creacion, NO el estado que ya existe. Una tienda que
| baja de plan conserva lo que tenga de mas; simplemente no puede añadir. Sin
| esto, cambiar el plan desde el panel de plataforma borraria datos de un
| cliente, que es justo lo que no puede pasar al gestionar un moroso.
|
| `images_per_product` cuenta la GALERIA. La imagen principal del producto es
| una columna suya (`products.image_url`), no una fila de `product_images`, y no
| entra en el tope: un plan con 3 imagenes son la principal + 3 de galeria.
|
| El frontend recibe esta misma matriz por `GET /api/plan`, asi que no hay una
| segunda copia que mantener (a diferencia de config/currencies.php).
|
*/

return [

    /*
    | Plan que se aplica a una tienda cuyo `plan` no esta en la lista de abajo.
    | Coincide con el default de la columna en la migracion de tenants.
    */
    'default' => 'free',

    'plans' => [

        'free' => [
            'label'  => 'Gratis',
            'limits' => [
                'products'           => 20,
                'images_per_product' => 3,
                'categories'         => 5,
                'pages'              => 2,
                'custom_domain'      => false,
                'csv_import'         => false,
            ],
        ],

        'pro' => [
            'label'  => 'Pro',
            'limits' => [
                'products'           => 500,
                'images_per_product' => 8,
                'categories'         => 50,
                'pages'              => 15,
                'custom_domain'      => true,
                'csv_import'         => true,
            ],
        ],

        'enterprise' => [
            'label'  => 'Enterprise',
            'limits' => [
                'products'           => null,
                'images_per_product' => null,
                'categories'         => null,
                'pages'              => null,
                'custom_domain'      => true,
                'csv_import'         => true,
            ],
        ],

    ],

];
