<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IPs autorizadas para el panel de plataforma (AUD-13)
    |--------------------------------------------------------------------------
    |
    | La cuenta de super-admin puede suspender cualquier tienda y disparar la
    | recuperacion de contrasenia de cualquier dueño, y hasta ahora entraba solo
    | con correo y contrasenia. Esto le pone una segunda llave que no depende de
    | lo que sepa el atacante.
    |
    | Lista separada por comas. Admite IPs sueltas, CIDR ("203.0.113.0/24") e
    | IPv6. El comodin '*' desactiva la restriccion a sabiendas.
    |
    | Vacia: en local y en pruebas no restringe nada, para no estorbar. En
    | PRODUCCION no deja pasar a nadie a proposito -es una guarda de
    | configuracion, como la de APP_DEBUG-: una cuenta con este poder no puede
    | quedarse abierta a internet por un despiste. Si de verdad no quieres
    | restringir por IP, ponlo en '*' y queda escrito que fue una decision.
    |
    */

    'allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLATFORM_ALLOWED_IPS', ''))
    ), fn ($ip) => $ip !== '')),

];
