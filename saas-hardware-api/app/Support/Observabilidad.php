<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Lo que sale hacia Sentry y lo que no (`TEC-5`).
 *
 * **Sin DSN no pasa nada**: el SDK se queda como cliente nulo, y es lo que hay
 * en local y en la suite. Con DSN, un error no controlado llega a Sentry con su
 * traza y la tienda como etiqueta —para saber a quién le pasó sin tener que
 * abrir el log—, pero **sin datos personales**:
 *
 * - `send_default_pii` queda apagado (sin IP, sin usuario) y `config/sentry.php`
 *   no manda el cuerpo de la petición ni las migas de pan de SQL, log y
 *   notificaciones.
 * - Esta clase lo asegura una vez más con `depurar()`: aunque una versión
 *   futura del SDK añadiera un campo, usuario, cookies, cabeceras, cuerpo y
 *   *query string* se quitan del evento antes de enviarlo. El *query string*
 *   importa de verdad aquí: el enlace de recuperar contraseña lleva el token y
 *   el correo en la URL.
 *
 * Es una clase con método estático y no un `fn` en `config/sentry.php` porque
 * `config:cache` no puede serializar un closure, y el despliegue lo ejecuta.
 */
class Observabilidad
{
    public static function depurar(Event $event, ?EventHint $hint = null): ?Event
    {
        $event->setUser(null);

        $peticion = $event->getRequest();

        foreach (['cookies', 'headers', 'data', 'query_string', 'env'] as $campo) {
            unset($peticion[$campo]);
        }

        if (isset($peticion['url']) && is_string($peticion['url'])) {
            $peticion['url'] = strtok($peticion['url'], '?#');
        }

        $event->setRequest($peticion);

        return $event;
    }

    /** La tienda a la que le pasó, para filtrar en Sentry. Nunca tumba la petición. */
    public static function tienda(?string $slug): void
    {
        if ($slug === null || $slug === '' || ! function_exists('Sentry\configureScope')) {
            return;
        }

        try {
            configureScope(fn (Scope $scope) => $scope->setTag('tienda', $slug));
        } catch (\Throwable) {
            // Observar no puede romper lo observado.
        }
    }
}
