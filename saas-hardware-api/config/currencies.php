<?php

/*
|--------------------------------------------------------------------------
| Monedas que puede elegir una tienda (OWN-1)
|--------------------------------------------------------------------------
|
| Fuente de verdad para la validacion de `tenants.currency` y para el formato
| de importes en los correos (App\Support\Money).
|
| OJO: el frontend tiene su propia copia de esta lista en
| `saas-hardware-frontend/src/utils/money.ts`, porque necesita las etiquetas
| para el selector de Configuracion y el locale para Intl.NumberFormat. Si se
| añade o quita una moneda hay que tocar los dos sitios: el backend rechazaria
| con 422 cualquier codigo que solo exista en el frontend.
|
| `decimals` importa: CLP, COP y PYG no usan decimales, y mostrar "$1.028,98"
| en pesos chilenos delata que el sistema no es de por aqui.
|
*/

return [
    'USD' => ['name' => 'Dolar estadounidense', 'symbol' => '$',    'decimals' => 2],
    'PEN' => ['name' => 'Sol peruano',          'symbol' => 'S/',   'decimals' => 2],
    'MXN' => ['name' => 'Peso mexicano',        'symbol' => '$',    'decimals' => 2],
    'COP' => ['name' => 'Peso colombiano',      'symbol' => '$',    'decimals' => 0],
    'CLP' => ['name' => 'Peso chileno',         'symbol' => '$',    'decimals' => 0],
    'ARS' => ['name' => 'Peso argentino',       'symbol' => '$',    'decimals' => 2],
    'BOB' => ['name' => 'Boliviano',            'symbol' => 'Bs',   'decimals' => 2],
    'BRL' => ['name' => 'Real brasileño',       'symbol' => 'R$',   'decimals' => 2],
    'UYU' => ['name' => 'Peso uruguayo',        'symbol' => '$U',   'decimals' => 2],
    'PYG' => ['name' => 'Guarani paraguayo',    'symbol' => '₲',    'decimals' => 0],
    'VES' => ['name' => 'Bolivar venezolano',   'symbol' => 'Bs.',  'decimals' => 2],
    'GTQ' => ['name' => 'Quetzal guatemalteco', 'symbol' => 'Q',    'decimals' => 2],
    'DOP' => ['name' => 'Peso dominicano',      'symbol' => 'RD$',  'decimals' => 2],
    'CRC' => ['name' => 'Colon costarricense',  'symbol' => '₡',    'decimals' => 2],
    'EUR' => ['name' => 'Euro',                 'symbol' => '€',    'decimals' => 2],
];
