<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Http;

/**
 * Cada tarea programada avisa a un servicio externo al terminar (`INF-18`).
 *
 * **Por qué así y no con un error.** `copias:crear` ya falla con código distinto
 * de cero y escribe en el log cuando algo sale mal (`INF-7`), pero **si no
 * corre, no falla**: con el cron caído no hay error que leer, solo silencio. Es
 * lo que convirtió `TEC-16` en pérdida de datos. Aquí el servicio externo
 * (healthchecks.io, Better Stack) espera un aviso cada día —o cada hora— y es su
 * **falta** lo que dispara la alarma. Al fallar se avisa además a `/fail`, para
 * enterarse en el momento y no al acabar el margen.
 *
 * **Va en el scheduler y no dentro de cada comando** porque lo que se vigila es
 * que el cron los lance: una copia hecha a mano no tiene que tapar un cron
 * parado. (Que haya copia reciente, venga de donde venga, lo mira
 * `salud:comprobar`.)
 *
 * Las URL están en `config/salud.php` y se leen al avisar, no al programar la
 * tarea: vacías, no se avisa a nadie, que es lo que se quiere en local.
 */
class Vigilancia
{
    /** Engancha los avisos a una tarea programada y la devuelve, para encadenar. */
    public static function tarea(Event $tarea, string $nombre): Event
    {
        return $tarea
            ->onSuccess(fn () => self::avisar($nombre, fallo: false))
            ->onFailure(fn () => self::avisar($nombre, fallo: true));
    }

    /**
     * Un aviso que no llega no puede tumbar la tarea que ya terminó: se anota y
     * se sigue. Si el servicio está caído, lo dirá él mismo al echar en falta
     * el aviso.
     */
    public static function avisar(string $nombre, bool $fallo): void
    {
        $url = trim((string) config("salud.vigilancia.{$nombre}"));

        if ($url === '') {
            return;
        }

        try {
            Http::connectTimeout(5)->timeout(10)->get($fallo ? rtrim($url, '/').'/fail' : $url);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
