<?php

namespace App\Support;

/**
 * Una huella del código que hay en disco, para saber si un proceso largo corre
 * la versión actual (`INF-19`).
 *
 * **El problema que resuelve es `INF-13`.** `queue:work` carga el código al
 * arrancar y no lo vuelve a leer: tras un despliegue sin `queue:restart`, el
 * worker sigue enviando la versión vieja **sin ningún error y sin nada en
 * `failed_jobs`**. "Hay un worker vivo" no basta; hay que saber con qué código.
 *
 * **Cómo.** El worker apunta la huella al arrancar (`delProceso()`, desde el
 * evento `WorkerStarting` en `AppServiceProvider`) y se la devuelve a quien
 * pregunte (`App\Jobs\ComprobarCola`). Quien pregunta es un proceso nuevo, así
 * que calcula la del disco (`delDisco()`) y compara. Si no coinciden, el worker
 * arrancó antes del último cambio.
 *
 * La huella es del **contenido** y no de las fechas de los archivos: un
 * despliegue que deja un archivo igual no obliga a reiniciar, y uno que lo
 * cambia sin tocar la fecha (un zip que la conserva) no se escapa. Lo que entra
 * está en `config/salud.php`; son unos cientos de archivos y se lee en
 * milisegundos.
 */
class HuellaDelCodigo
{
    /** Dónde se guarda en el contenedor la del proceso en curso. */
    private const LLAVE = 'salud.huella-del-proceso';

    /** La del código que hay en disco ahora mismo. */
    public static function delDisco(): string
    {
        $huella = hash_init('xxh128');

        foreach (self::archivos() as $relativo => $absoluto) {
            // El nombre también cuenta: mover un archivo es un cambio.
            hash_update($huella, $relativo."\0");
            hash_update_file($huella, $absoluto);
        }

        return hash_final($huella);
    }

    /**
     * La del código con el que arrancó este proceso.
     *
     * Se calcula la primera vez y se queda: en el worker, esa primera vez es el
     * arranque. Va en el contenedor y no en una propiedad estática para que cada
     * aplicación —cada test— empiece sin ella.
     */
    public static function delProceso(): string
    {
        $app = app();

        if (! $app->bound(self::LLAVE)) {
            $app->instance(self::LLAVE, self::delDisco());
        }

        return $app->make(self::LLAVE);
    }

    /**
     * Los archivos que entran, por ruta relativa y en orden fijo: el orden en
     * que el sistema de archivos los devuelve no está garantizado, y con otro
     * orden saldría otra huella del mismo código.
     *
     * @return array<string, string> ruta relativa => ruta absoluta
     */
    private static function archivos(): array
    {
        $archivos = [];

        foreach ((array) config('salud.huella') as $ruta) {
            $absoluta = base_path($ruta);

            if (is_file($absoluta)) {
                $archivos[$ruta] = $absoluta;

                continue;
            }

            if (! is_dir($absoluta)) {
                continue;
            }

            $recorrido = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absoluta, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($recorrido as $archivo) {
                if ($archivo->isFile()) {
                    $relativa = $ruta.'/'.str_replace('\\', '/', substr($archivo->getPathname(), strlen($absoluta) + 1));
                    $archivos[$relativa] = $archivo->getPathname();
                }
            }
        }

        ksort($archivos, SORT_STRING);

        return $archivos;
    }
}
