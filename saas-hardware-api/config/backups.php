<?php

/*
|--------------------------------------------------------------------------
| Copias de seguridad (INF-7)
|--------------------------------------------------------------------------
|
| El comando `copias:crear` vuelca la base y la guarda en un disco de
| `config/filesystems.php`. Lo que se decide aqui es DONDE y CUANTO se guarda.
|
| **El disco por defecto es `local` y no el de las imagenes a proposito.** Una
| copia lleva dentro los datos de todas las tiendas y los correos de todos sus
| clientes; el disco `public` se sirve por HTTP desde `/storage`, asi que dejarla
| ahi seria publicar la base entera. `App\Support\Copias::discoEsPublico()`
| ademas lo corta, por si alguien pone `BACKUP_DISK=public` en el .env.
|
| **En produccion esto tiene que apuntar a `r2` (o `s3`).** Una copia en el mismo
| servidor que la base no es una copia: es el mismo disco que se puede perder de
| una vez. `local` sirve para probar el comando y para una copia de emergencia
| antes de una migracion, no como politica de respaldo.
|
*/

return [

    /*
    | El disco de `filesystems.php` donde se escriben las copias.
    */
    'disco' => env('BACKUP_DISK', 'local'),

    /*
    | Cuantos dias se guarda una copia antes de que el propio comando la borre.
    |
    | Treinta es el plazo en el que se nota que algo se corrompio o se borro por
    | error. Guardarlas para siempre es pagar almacenamiento por copias que nadie
    | va a restaurar, y sin purga la carpeta crece sin techo, que es la razon por
    | la que las copias se acaban apagando.
    */
    'retencion_dias' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    | Donde esta `mysqldump`, si no esta en el PATH.
    |
    | En muchos hostings el PATH del usuario del cron no es el de la sesion
    | interactiva: el comando funciona a mano y falla de noche. Aqui se pone la
    | carpeta (sin el nombre del binario), p. ej. /usr/bin o, en Windows con
    | Laragon, C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin.
    */
    'ruta_binarios' => env('BACKUP_BIN_PATH', ''),

];
