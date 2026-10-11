<?php

namespace App\Support;

use App\Jobs\ComprobarCola;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Las comprobaciones de `salud:comprobar` (`INF-19`).
 *
 * **Qué vigila.** Lo que el despliegue necesita y que, si falta, **no da ningún
 * error**: produce un comportamiento que parece normal. Cada comprobación sale
 * de un caso que ya pasó o que está escrito en `operacion.md` § 4: la cola sin
 * worker (`AUD-11`), el worker con el código viejo (`INF-13`), el cron parado
 * (`FUN-16`, `INF-7`), las migraciones sin aplicar, el correo en `log`, la
 * lista de IPs vacía (`AUD-13`), el operador sin segundo paso (`SEC-9`) y los
 * topes de PHP (`INF-12`, `INF-14`).
 *
 * **Cómo comprueba.** Siempre por un camino distinto del de la acción
 * (`operacion.md` § 5): que haya copia se mira en el disco de copias, no en que
 * exista el comando; que el worker corra el código actual, pidiéndole que lo
 * diga; y los topes de PHP, preguntándole al PHP que atiende la web y no al de
 * esta consola, que suele leer otro php.ini.
 *
 * **Lo que no se ve desde aquí**, y lo dice el propio comando: `VITE_PLATFORM_HOSTS`
 * (va en el build del frontend), el `client_max_body_size` de nginx y los
 * registros SPF/DKIM del correo.
 *
 * Cada comprobación devuelve `[estado, detalle]`. Lo que es obligatorio solo en
 * producción da `NO_APLICA` fuera de ella, para que el comando se pueda correr
 * en local sin ruido.
 */
class Salud
{
    public const OK = 'ok';

    public const FALLO = 'fallo';

    public const NO_APLICA = 'no aplica';

    /** Dónde apunta el scheduler su último paso (`anotarPasoDelScheduler()`). */
    private const CLAVE_SCHEDULER = 'salud:scheduler';

    /**
     * Todas, en orden, de una en una para que el comando las vaya escribiendo:
     * la de la cola puede tardar hasta `$esperaCola` segundos y va la última.
     *
     * Una comprobación que revienta cuenta como fallo, con el porqué: un disco
     * de copias mal configurado lanza una excepción, y eso es justo lo que hay
     * que contar.
     *
     * @return \Generator<string, array{0: string, 1: string}>
     */
    public static function comprobar(int $esperaCola): \Generator
    {
        $comprobaciones = [
            'Migraciones'          => fn () => self::migraciones(),
            'Scheduler'            => fn () => self::scheduler(),
            'Copia de la base'     => fn () => self::copia(),
            'Correo'               => fn () => self::correo(),
            'PHP del servidor web' => fn () => self::phpDeLaWeb(),
            'IPs de plataforma'    => fn () => self::ipsDePlataforma(),
            'Dos pasos'            => fn () => self::dosPasos(),
            'Cola'                 => fn () => self::cola($esperaCola),
        ];

        foreach ($comprobaciones as $nombre => $comprobacion) {
            try {
                $resultado = $comprobacion();
            } catch (\Throwable $e) {
                $resultado = [self::FALLO, 'No se pudo comprobar: '.$e->getMessage()];
            }

            yield $nombre => $resultado;
        }
    }

    // ------------------------------------------------------------ migraciones

    /**
     * Ninguna migración del repositorio sin aplicar. El código cuenta con sus
     * columnas: si sube sin ellas, falla (README, *Al desplegar una versión nueva*).
     *
     * @return array{0: string, 1: string}
     */
    public static function migraciones(): array
    {
        $migrador = app('migrator');

        if (! $migrador->repositoryExists()) {
            return [self::FALLO, 'La base no tiene la tabla `migrations`: está sin migrar. php artisan migrate --force'];
        }

        $archivos = $migrador->getMigrationFiles(array_merge([database_path('migrations')], $migrador->paths()));
        $pendientes = array_values(array_diff(array_keys($archivos), $migrador->getRepository()->getRan()));

        if ($pendientes === []) {
            return [self::OK, count($archivos).' aplicadas, ninguna pendiente.'];
        }

        return [self::FALLO, count($pendientes).' sin aplicar ('.self::lista($pendientes).'). '
            .'php artisan migrate --force, antes de que el código nuevo reciba tráfico.'];
    }

    // -------------------------------------------------------------- scheduler

    /**
     * Lo llama el propio scheduler cada minuto (`routes/console.php`). Es la
     * única forma de saber desde fuera que el cron está vivo: si no corre, no
     * falla, se calla.
     */
    public static function anotarPasoDelScheduler(): void
    {
        Cache::forever(self::CLAVE_SCHEDULER, now()->getTimestamp());
    }

    /** @return array{0: string, 1: string} */
    public static function scheduler(): array
    {
        $ultimo = Cache::get(self::CLAVE_SCHEDULER);

        if ($ultimo === null) {
            return [self::FALLO, 'No consta que haya corrido nunca: falta el cron con `php artisan schedule:run` cada minuto. '
                .'Sin él no se cierran las pruebas vencidas, no se vacía la papelera y no hay copias.'];
        }

        $segundos = now()->getTimestamp() - (int) $ultimo;

        if ($segundos > (int) config('salud.minutos_scheduler') * 60) {
            return [self::FALLO, 'Corrió por última vez hace '.self::hace($segundos).': el cron está parado.'];
        }

        return [self::OK, 'Corrió hace '.self::hace($segundos).'.'];
    }

    // ------------------------------------------------------------------ copia

    /**
     * Una copia reciente **en el disco de copias**. No basta con que el comando
     * exista y funcione a mano: lo que convirtió `TEC-16` en pérdida de datos
     * fue una carpeta de copias vacía porque nadie lo lanzaba.
     *
     * @return array{0: string, 1: string}
     */
    public static function copia(): array
    {
        $disco = (string) config('backups.disco');

        if (Copias::discoEsPublico($disco)) {
            return [self::FALLO, "BACKUP_DISK={$disco} es un disco público: copias:crear se niega a escribir ahí, así que no se está haciendo ninguna."];
        }

        if (app()->isProduction() && config("filesystems.disks.{$disco}.driver") === 'local') {
            return [self::FALLO, "BACKUP_DISK={$disco} es un disco de este mismo servidor: si se pierde el servidor, se pierden "
                .'la base y sus copias a la vez. Apúntalo a r2 o s3.'];
        }

        $almacen = Storage::disk($disco);
        $ultima = collect($almacen->files(Copias::CARPETA))
            ->map(fn (string $archivo) => $almacen->lastModified($archivo))
            ->max();

        if ($ultima === null) {
            return [self::FALLO, "No hay ninguna copia en «{$disco}»: o el scheduler no ha corrido a las 03:30, o copias:crear falla (mira el log)."];
        }

        $segundos = now()->getTimestamp() - $ultima;

        if ($segundos > (int) config('salud.horas_copia') * 3600) {
            return [self::FALLO, 'La más reciente es de hace '.self::hace($segundos)." en «{$disco}»: copias:crear no está corriendo o falla (mira el log)."];
        }

        return [self::OK, 'La más reciente es de hace '.self::hace($segundos)." en «{$disco}»."];
    }

    // ----------------------------------------------------------------- correo

    /**
     * `MAIL_MAILER=log` es el valor por defecto del `.env.example`: si se queda
     * así en el servidor, nada avisa de que ningún correo sale.
     *
     * @return array{0: string, 1: string}
     */
    public static function correo(): array
    {
        $mailer = (string) config('mail.default');

        if (! app()->isProduction()) {
            return [self::NO_APLICA, "MAIL_MAILER={$mailer}; fuera de producción no hace falta que salga nada."];
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            return [self::FALLO, "MAIL_MAILER={$mailer}: ningún correo sale del servidor. Sin correo no se publica ninguna "
                .'tienda nueva (la verificación del alta, FUN-5) ni llega una recuperación de contraseña.'];
        }

        return [self::OK, "MAIL_MAILER={$mailer}."];
    }

    // ------------------------------------------------------ PHP del servidor web

    /**
     * Los topes de PHP **del servidor web**, pedidos por HTTP a `APP_URL`.
     *
     * Esta consola corre con el php.ini de la línea de comandos, que suele ser
     * otro archivo que el del servidor web (y en muchas distribuciones trae
     * `memory_limit = -1`): comparar los suyos daría por buena una subida que la
     * web corta. De paso se comprueba que `APP_URL` responde y que la firma
     * cuadra, que es lo mismo que necesitan los enlaces de verificación de correo.
     *
     * @return array{0: string, 1: string}
     */
    public static function phpDeLaWeb(): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $url = URL::temporarySignedRoute('salud.php', now()->addMinutes(2));

        try {
            $respuesta = Http::acceptJson()->connectTimeout(5)->timeout(10)->get($url);
        } catch (ConnectionException $e) {
            return [self::FALLO, "No se pudo llegar a {$base} (APP_URL) desde el propio servidor: {$e->getMessage()} "
                .'De APP_URL cuelgan también los enlaces de verificación de correo.'];
        }

        if ($respuesta->status() === 403) {
            return [self::FALLO, "{$base} rechazó la firma: el servidor ve una URL distinta de APP_URL (otro esquema u otro "
                .'host, lo típico de un proxy delante sin TrustProxies). Los enlaces de verificación de correo, firmados '
                .'igual, fallarán también.'];
        }

        if ($respuesta->status() === 404) {
            return [self::FALLO, "{$base} respondió 404: en APP_URL no contesta esta aplicación (otra en ese puerto o "
                .'dominio), o contesta una versión anterior sin esta ruta.'];
        }

        if (! $respuesta->successful()) {
            return [self::FALLO, "{$base} respondió {$respuesta->status()} al pedirle sus topes de PHP: revisa APP_URL."];
        }

        $cortos = [];

        foreach ((array) config('salud.php') as $directiva => $minimo) {
            $actual = trim((string) $respuesta->json($directiva));

            if (! self::alcanza($directiva, $actual, (string) $minimo)) {
                $cortos[] = "{$directiva} = ".($actual === '' ? '(sin dato)' : $actual)." (mínimo {$minimo})";
            }
        }

        if ($cortos !== []) {
            return [self::FALLO, 'Por debajo de lo que pide el README: '.implode(', ', $cortos).'. '
                .'Se cambia en el php.ini del servidor web, no en el de consola.'];
        }

        return [self::OK, 'Los del PHP que atiende la web ('.$respuesta->json('sapi').') llegan a los del README.'];
    }

    // ------------------------------------------------------------- plataforma

    /** @return array{0: string, 1: string} */
    public static function ipsDePlataforma(): array
    {
        if (! app()->isProduction()) {
            return [self::NO_APLICA, 'Fuera de producción no se restringe por IP.'];
        }

        $ips = (array) config('platform.allowed_ips', []);

        if ($ips === []) {
            return [self::FALLO, 'PLATFORM_ALLOWED_IPS está vacío: el panel de plataforma no deja entrar a nadie (AUD-13). '
                .'Pon las IPs del operador, o "*" para no restringir por IP.'];
        }

        if (in_array('*', $ips, true)) {
            return [self::OK, 'Sin restricción por IP, puesto a propósito con "*".'];
        }

        return [self::OK, 'Autorizadas: '.implode(', ', $ips).'.'];
    }

    /**
     * Cada operador activo con el segundo paso puesto (`SEC-9`).
     *
     * En producción el panel ya se lo exige al entrar, así que olvidarlo no es
     * silencioso del todo. Lo que sí lo es: que se quite con
     * `platform:quitar-dos-pasos` y nadie la vuelva a poner —hasta entonces,
     * quien tenga la contraseña puede entrar y activarla con su propio móvil—.
     *
     * @return array{0: string, 1: string}
     */
    public static function dosPasos(): array
    {
        if (! DosPasos::obligatoria()) {
            return [self::NO_APLICA, 'Fuera de producción es opcional.'];
        }

        // Sin tienda resuelta el scope de `User` ya lo ve todo; se pide por
        // escrito igual, como en `platform:quitar-dos-pasos`.
        $operadores = User::withoutTenant()
            ->where('role', 'superadmin')
            ->where('is_active', true)
            ->get();

        if ($operadores->isEmpty()) {
            return [self::FALLO, 'No hay ningún operador de plataforma activo: nadie puede asignar un plan, y las tiendas '
                .'se cierran solas al vencer su prueba (FUN-16). php artisan platform:superadmin'];
        }

        $sin = $operadores->reject(fn (User $operador) => $operador->tieneDosPasos())->pluck('email');

        if ($sin->isNotEmpty()) {
            return [self::FALLO, 'Sin la verificación en dos pasos: '.$sin->implode(', ').'. Mientras tanto, quien tenga '
                .'su contraseña puede entrar y activarla con su propio móvil.'];
        }

        return [self::OK, 'Activa en los '.$operadores->count().' operadores.'];
    }

    // ------------------------------------------------------------------- cola

    /** @return array{0: string, 1: string} */
    public static function cola(int $espera): array
    {
        return self::leerPruebaDeLaCola(self::enviarPruebaALaCola(), $espera);
    }

    /**
     * Encola el trabajo de prueba y devuelve con qué clave lo dejará.
     * Separado de la lectura para poder atenderlo con un worker de verdad en
     * medio (lo hace `SaludDelDespliegueTest`).
     */
    public static function enviarPruebaALaCola(): string
    {
        $token = Str::random(32);

        ComprobarCola::dispatch($token);

        return $token;
    }

    /**
     * Espera a que un worker atienda el trabajo y compara su huella con la del
     * disco. Si no coinciden, el worker arrancó antes del último despliegue y
     * sigue enviando la versión vieja (`INF-13`).
     *
     * @return array{0: string, 1: string}
     */
    public static function leerPruebaDeLaCola(string $token, int $espera): array
    {
        $conexion = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$conexion}.driver");

        if ($driver === 'null') {
            return [self::FALLO, "QUEUE_CONNECTION={$conexion} tira todo lo que se encola: no sale ningún correo."];
        }

        $inicio = microtime(true);

        // `pull` y no `get`: la clave es de un solo uso, y en la caché de base de
        // datos una clave leída con `get` se queda en la tabla hasta caducar.
        while (($huella = Cache::pull(ComprobarCola::clave($token))) === null && microtime(true) - $inicio < $espera) {
            usleep(500_000);
        }

        if ($huella === null) {
            return [self::FALLO, "Nadie atendió el trabajo de prueba en {$espera} s: no hay ningún worker corriendo "
                .'(php artisan queue:work), o la caché no se comparte entre procesos (CACHE_STORE=array). Sin worker '
                .'no sale ningún correo y una tienda nueva no se publica (AUD-11).'];
        }

        if ($huella !== HuellaDelCodigo::delDisco()) {
            return [self::FALLO, 'Hay un worker, pero arrancó con un código anterior al que hay en disco: sigue enviando '
                .'la versión vieja sin dar ningún error. php artisan queue:restart (INF-13).'];
        }

        if ($driver === 'sync') {
            return [self::OK, "QUEUE_CONNECTION={$conexion}: lo encolado se ejecuta dentro de la propia petición, no hay worker que comprobar."];
        }

        return [self::OK, 'Un worker atendió el trabajo de prueba en '.self::hace((int) round(microtime(true) - $inicio)).' y corre el código actual.'];
    }

    // ---------------------------------------------------------------- ayudas

    /**
     * Si un valor de php.ini llega al mínimo. `memory_limit = -1` y
     * `post_max_size = 0` son "sin tope".
     */
    private static function alcanza(string $directiva, string $actual, string $minimo): bool
    {
        if ($actual === '') {
            return false;
        }

        if ($directiva === 'max_file_uploads') {
            return (int) $actual >= (int) $minimo;
        }

        if ($directiva === 'post_max_size' && $actual === '0') {
            return true;
        }

        $bytes = Subidas::aBytes($actual);

        return $bytes === null || $bytes >= Subidas::aBytes($minimo);
    }

    /** @param list<string> $nombres */
    private static function lista(array $nombres): string
    {
        $primeros = implode(', ', array_slice($nombres, 0, 3));
        $resto = count($nombres) - 3;

        return $resto > 0 ? "{$primeros} y {$resto} más" : $primeros;
    }

    private static function hace(int $segundos): string
    {
        return match (true) {
            $segundos < 120    => "{$segundos} s",
            $segundos < 7200   => intdiv($segundos, 60).' min',
            $segundos < 172800 => intdiv($segundos, 3600).' h',
            default            => intdiv($segundos, 86400).' días',
        };
    }
}
