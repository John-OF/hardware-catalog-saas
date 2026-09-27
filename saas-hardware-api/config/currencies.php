<?php

/*
|--------------------------------------------------------------------------
| Monedas que puede elegir una tienda (OWN-1)
|--------------------------------------------------------------------------
|
| Fuente de verdad para la validacion de `tenants.currency` y para el formato
| de importes que escribe el servidor: la cotizacion en PDF, los correos, el
| SEO y la descripcion de un cupon (App\Support\Money).
|
| OJO: el frontend tiene su propia copia de esta lista en
| `saas-hardware-frontend/src/utils/money.ts`, que es con la que se pintan el
| panel y el catalogo. Si se añade o quita una moneda, o se cambia su formato,
| hay que tocar los dos sitios: `FormatoDeMonedaTest` compara las dos y falla si
| se separan (FUN-23). El backend rechazaria con 422 cualquier codigo que solo
| exista en el frontend.
|
| `decimals` importa: CLP, COP y PYG no usan decimales, y mostrar "$1.028,98"
| en pesos chilenos delata que el sistema no es de por aqui.
|
| El formato va escrito aqui y no sacado de `Intl`/ICU (FUN-23). El panel usaba
| `Intl.NumberFormat` y el servidor `number_format()` con coma de miles para
| todas: en 10 de las 15 monedas el mismo precio salia escrito de dos formas.
| Una tabla es lo unico que sale igual en PHP, en Node y en cada navegador, que
| trae su propia version de ICU. Los separadores y los espacios reproducen lo
| que pintaba `Intl` en cada pais; los simbolos son los de aqui, que son los que
| el dueño ve al elegir la moneda (`Intl` pintaba `$` para UYU y DOP y `Bs.S`,
| el del bolivar ya retirado, para VES).
|
|   miles   separador de miles ("\u{A0}" es un espacio que no se parte)
|   decimal separador decimal
|   patron  `¤` es el simbolo, `#` el numero, y un espacio se escribe como
|           espacio que no se parte, para que el simbolo no quede solo al
|           final de una linea
|
*/

return [
    'USD' => ['name' => 'Dolar estadounidense', 'symbol' => '$',   'decimals' => 2, 'miles' => ',',      'decimal' => '.', 'patron' => '¤#'],
    'PEN' => ['name' => 'Sol peruano',          'symbol' => 'S/',  'decimals' => 2, 'miles' => ',',      'decimal' => '.', 'patron' => '¤ #'],
    'MXN' => ['name' => 'Peso mexicano',        'symbol' => '$',   'decimals' => 2, 'miles' => ',',      'decimal' => '.', 'patron' => '¤#'],
    'COP' => ['name' => 'Peso colombiano',      'symbol' => '$',   'decimals' => 0, 'miles' => '.',      'decimal' => ',', 'patron' => '¤ #'],
    'CLP' => ['name' => 'Peso chileno',         'symbol' => '$',   'decimals' => 0, 'miles' => '.',      'decimal' => ',', 'patron' => '¤#'],
    'ARS' => ['name' => 'Peso argentino',       'symbol' => '$',   'decimals' => 2, 'miles' => '.',      'decimal' => ',', 'patron' => '¤ #'],
    'BOB' => ['name' => 'Boliviano',            'symbol' => 'Bs',  'decimals' => 2, 'miles' => '.',      'decimal' => ',', 'patron' => '¤ #'],
    'BRL' => ['name' => 'Real brasileño',       'symbol' => 'R$',  'decimals' => 2, 'miles' => '.',      'decimal' => ',', 'patron' => '¤ #'],
    'UYU' => ['name' => 'Peso uruguayo',        'symbol' => '$U',  'decimals' => 2, 'miles' => '.',      'decimal' => ',', 'patron' => '¤ #'],
    'PYG' => ['name' => 'Guarani paraguayo',    'symbol' => '₲',   'decimals' => 0, 'miles' => '.',      'decimal' => ',', 'patron' => '¤ #'],
    'VES' => ['name' => 'Bolivar venezolano',   'symbol' => 'Bs.', 'decimals' => 2, 'miles' => '.',      'decimal' => ',', 'patron' => '¤ #'],
    'GTQ' => ['name' => 'Quetzal guatemalteco', 'symbol' => 'Q',   'decimals' => 2, 'miles' => ',',      'decimal' => '.', 'patron' => '¤ #'],
    'DOP' => ['name' => 'Peso dominicano',      'symbol' => 'RD$', 'decimals' => 2, 'miles' => ',',      'decimal' => '.', 'patron' => '¤#'],
    'CRC' => ['name' => 'Colon costarricense',  'symbol' => '₡',   'decimals' => 2, 'miles' => "\u{A0}", 'decimal' => ',', 'patron' => '¤#'],
    'EUR' => ['name' => 'Euro',                 'symbol' => '€',   'decimals' => 2, 'miles' => '.',      'decimal' => ',', 'patron' => '# ¤'],
];
