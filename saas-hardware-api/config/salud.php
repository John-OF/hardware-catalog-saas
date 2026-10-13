<?php

/*
|--------------------------------------------------------------------------
| Salud del despliegue (INF-18, INF-19)
|--------------------------------------------------------------------------
|
| Lo que este proyecto necesita del servidor y que, si falta, no da ningun
| error: el worker de colas, el cron, las copias, el correo, la lista de IPs de
| plataforma, los topes de PHP. Dos piezas lo vigilan:
|
| - `php artisan salud:comprobar` (INF-19) lo comprueba todo y sale con codigo
|   distinto de cero si algo falla. Se lanza al final de cada despliegue y,
|   ademas, cada hora desde el scheduler.
| - Los avisos de `vigilancia` (INF-18): cada tarea programada avisa a un
|   servicio externo al terminar. Es la falta de aviso lo que dispara la alarma,
|   y es lo unico que detecta algo que NO ocurrio: un cron caido no produce
|   errores, produce silencio.
|
*/

return [

    /*
    | URL de aviso de cada tarea programada (healthchecks.io, Better Stack...).
    |
    | Al terminar bien se pide la URL tal cual; al fallar, la URL con `/fail`
    | detras, que es como lo entienden los dos servicios citados: asi un fallo
    | avisa en el momento y no al acabar el margen. Vacia, la tarea no avisa a
    | nadie, que es lo que se quiere en local y en la suite.
    |
    | Cada tarea programada de `routes/console.php` tiene que estar aqui (lo
    | vigila `VigilanciaDeTareasTest`), menos el latido del propio scheduler,
    | que es interno.
    */
    'vigilancia' => [
        'copias'   => env('VIGILANCIA_COPIAS_URL'),
        'pruebas'  => env('VIGILANCIA_PRUEBAS_URL'),
        'papelera' => env('VIGILANCIA_PAPELERA_URL'),
        'bitacora' => env('VIGILANCIA_BITACORA_URL'),
        'salud'    => env('VIGILANCIA_SALUD_URL'),
    ],

    /*
    | Segundos que `salud:comprobar` espera a que un worker atienda el trabajo
    | de prueba. Con supervisor tarda uno o dos; con el worker lanzado por el
    | propio cron cada minuto (`queue:work --stop-when-empty`, ver INF-16),
    | hasta sesenta. Noventa cubre los dos.
    */
    'espera_cola' => 90,

    /*
    | Minutos sin noticias del scheduler a partir de los cuales el cron se da
    | por parado. Corre cada minuto; tres deja margen a un `schedule:run` lento
    | sin tardar en darse cuenta.
    */
    'minutos_scheduler' => 3,

    /*
    | Horas que puede tener la copia mas reciente. La copia es diaria (03:30):
    | pedir una "de hoy" fallaria cada noche entre las 00:00 y las 03:30, asi que
    | se piden 24 horas mas el margen de lo que tarde en hacerse.
    */
    'horas_copia' => 26,

    /*
    | Los minimos de PHP del README (*Requisitos*, INF-12 e INF-14). Se comparan
    | con los del PHP que atiende la web, no con los de la consola: suelen ser
    | dos php.ini distintos, y en muchas distribuciones el de consola trae
    | `memory_limit = -1`, que haria pasar la comprobacion siempre.
    */
    'php' => [
        'upload_max_filesize' => '12M',
        'post_max_size'       => '100M',
        'max_file_uploads'    => 50,
        'memory_limit'        => '256M',
    ],

    /*
    | Lo que forma la "huella" del codigo, relativo a la raiz del proyecto.
    |
    | Un worker guarda la huella con la que arranco; si ya no coincide con la
    | del disco, sigue ejecutando la version anterior (INF-13). Entra lo que un
    | proceso carga al arrancar y no vuelve a leer: el codigo, la configuracion
    | (y su cache), las rutas, las vistas, las traducciones, el lock de Composer
    | como resumen de `vendor/` y el `.env`.
    */
    'huella' => [
        'app',
        'bootstrap/app.php',
        'bootstrap/cache',
        'config',
        'lang',
        'resources/views',
        'routes',
        'composer.lock',
        '.env',
    ],

];
