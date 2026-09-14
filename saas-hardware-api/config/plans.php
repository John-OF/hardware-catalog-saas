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
| `users` cuenta los usuarios del PANEL (admin y staff), no los clientes del
| catalogo, que viven en la misma tabla `users` pero con rol `customer`. Una
| tienda con 500 compradores registrados no puede quedarse sin poder invitar a
| su vendedor. Con `users => 1` el plan gratuito se queda como estaba hasta hoy:
| el dueno y nadie mas.
|
| `images_per_product` cuenta la GALERIA. La imagen principal del producto es
| una columna suya (`products.image_url`), no una fila de `product_images`, y no
| entra en el tope: un plan con 3 imagenes son la principal + 3 de galeria.
|
| El frontend recibe esta misma matriz por `GET /api/plan`, asi que no hay una
| segunda copia que mantener (a diferencia de config/currencies.php).
|
| `price_usd` (INF-1) es el precio de LA PLATAFORMA -lo que paga el dueño de la
| tienda por usar el SaaS-, no tiene nada que ver con `tenants.currency` -en la
| que esa tienda cobra a SUS clientes-. Es mensual y en USD porque todavia no
| hay pasarela de cobro (7.7b, SAAS-3): es el precio que se ENSEÑA en la
| landing, no uno que el sistema vaya a cobrar solo. El dia que 7.7b elija
| proveedor y moneda, este es el sitio donde cambiar el numero.
|
| `public` (FUN-16) decide si un plan sale en `GET /api/public/plans`, o sea en
| la landing. `trial` NO lleva esta clave -por eso PublicPlansController la
| trata como `false`-: es un plan interno, el que usa PlanGate mientras dura la
| prueba de una tienda nueva, y no algo que nadie compre. Enseñarlo en la
| landing como una cuarta tarjeta de precio no tendria sentido.
|
| La CLAVE 'free' se queda con ese nombre por dentro aunque ya no sea gratis
| (FUN-16, decision del dueño el 2026-09-12): sigue siendo el plan por defecto
| al que cae un `tenants.plan` invalido o desconocido (ver arriba, "fallar en
| cerrado"), y cambiar esa clave habria significado tocar la columna de la
| migracion, cada test que crea una tienda esperando 'free', y el tipo del
| frontend -todo por un cambio que es solo de ETIQUETA (`label`) y de PRECIO,
| no de identidad-. Lo que ve el dueño de una tienda es `label`, nunca la
| clave.
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
            // Sigue siendo el plan mas barato y el que cae por defecto -ver la
            // cabecera-, pero desde FUN-16 ya no es gratis: $15/mes es lo que
            // el dueño decidio como piso, para al menos no perder con la
            // infraestructura que cuesta sostener la plataforma.
            'label'     => 'Básico',
            'price_usd' => 15,
            'public'    => true,
            'limits' => [
                'products'           => 20,
                'images_per_product' => 3,
                'users'              => 1,
                'categories'         => 5,
                'pages'              => 2,
                'custom_domain'      => false,
                'csv_import'         => false,
            ],
        ],

        'pro' => [
            'label'     => 'Pro',
            'price_usd' => 29,
            'public'    => true,
            'limits' => [
                'products'           => 500,
                'images_per_product' => 8,
                'users'              => 3,
                'categories'         => 50,
                'pages'              => 15,
                'custom_domain'      => true,
                'csv_import'         => true,
            ],
        ],

        'enterprise' => [
            'label'     => 'Enterprise',
            'price_usd' => 79,
            'public'    => true,
            'limits' => [
                'products'           => null,
                'images_per_product' => null,
                'users'              => null,
                'categories'         => null,
                'pages'              => null,
                'custom_domain'      => true,
                'csv_import'         => true,
            ],
        ],

        /*
        | Plan EFECTIVO de una tienda mientras dura su prueba (FUN-16). Nunca se
        | guarda en `tenants.plan` -esa columna sigue con 'free' hasta que
        | alguien elige uno de verdad-; lo devuelve `PlanGate::plan()` en su
        | lugar, calculado a partir de `Tenant::enPrueba()`. Mismos limites que
        | 'pro': lo bastante generoso para que se vea el producto de verdad
        | (dominio propio, CSV, varias personas), sin llegar a prometer el techo
        | de Enterprise que probablemente no va a comprar.
        */
        'trial' => [
            'label'  => 'Prueba',
            'limits' => [
                'products'           => 500,
                'images_per_product' => 8,
                'users'              => 3,
                'categories'         => 50,
                'pages'              => 15,
                'custom_domain'      => true,
                'csv_import'         => true,
            ],
        ],

    ],

];
