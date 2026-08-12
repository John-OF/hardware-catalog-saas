<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) — TEC-3
|--------------------------------------------------------------------------
|
| Sin este archivo, Laravel aplica su default: `allowed_origins => ['*']`, o
| sea que cualquier web podia llamar a la API desde el navegador de un visitante.
| Aqui se cierra a los origenes que de verdad usan la API.
|
| `CORS_ALLOWED_ORIGINS` acepta una lista separada por comas. En produccion hay
| que incluir el dominio del panel y los dominios propios de las tiendas
| (custom_domain), porque el catalogo publico se sirve desde ellos.
|
| Ojo: esto NO es una medida de autenticacion. CORS solo limita lo que puede
| hacer un navegador desde otra web; un curl no lo ve. Lo que protege los datos
| son el token de Sanctum y el scope de tenant.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

if (empty($origins)) {
    // Sin configurar cae al frontend conocido, que en local es el de Vite.
    $origins = [rtrim((string) config('app.frontend_url'), '/')];
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    /*
     * Los dominios propios de las tiendas (SAAS/4.7) no se conocen de antemano.
     * Este patron permite cualquier subdominio de los dominios que el operador
     * declare en CORS_ALLOWED_ORIGIN_PATTERNS (regex, separadas por comas).
     */
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // La API es stateless (tokens Bearer de Sanctum), no usa cookies de sesion.
    'supports_credentials' => false,

];
