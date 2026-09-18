<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Copias de seguridad de la base (`INF-7`).
 *
 * **Qué es y qué no es.** Esto vuelca la **base de datos**: tiendas, catálogos,
 * pedidos, usuarios y bitácora. Las imágenes viven en almacenamiento de objetos
 * (R2/S3) y no entran aquí: tienen su propio versionado en el proveedor y
 * meterlas en el mismo `.sql` haría una copia de gigabytes que nadie va a
 * restaurar nunca. Lo que se pierde si sólo se restaura esto está escrito en el
 * README, en *Restaurar una copia*.
 *
 * **La comprobación no es opcional.** Cada copia se relee y se comprueba que
 * tiene dentro las tablas que tiene que tener. El fallo clásico de los backups
 * no es que no se hagan: es que se hacen vacíos —`mysqldump` que no está en el
 * PATH, credenciales que cambiaron— y nadie se entera hasta el día que hacen
 * falta. Un archivo de 0 bytes creado puntualmente cada noche es peor que no
 * tener nada, porque además da tranquilidad.
 */
class Copias
{
    /** Dónde se guardan dentro del disco. */
    public const CARPETA = 'copias';

    /**
     * Tablas que TIENEN que aparecer en el volcado.
     *
     * No es una lista de todas: son tres que no pueden faltar nunca y que
     * cubren los tres tipos de dato que dolería perder —las tiendas, lo que
     * venden y lo que han vendido—. Si un volcado no las trae, no es una copia
     * de esta base.
     */
    private const TABLAS_OBLIGATORIAS = ['tenants', 'products', 'orders'];

    /**
     * Cuánto puede tardar `mysqldump`. Diez minutos es de sobra para una base de
     * este tamaño y a la vez evita que un proceso colgado se quede vivo hasta el
     * volcado siguiente.
     */
    private const SEGUNDOS_DE_TOPE = 600;

    /**
     * El contenido de la copia, listo para escribir.
     *
     * Se devuelve en memoria y no se escribe aquí para que quien llama decida
     * dónde va —y para poder comprobarlo antes de guardarlo—. Con una base de
     * decenas de gigas habría que pasar a streaming; hasta entonces, esto es lo
     * simple que se entiende de un vistazo.
     *
     * @return array{contenido: string, extension: string}
     *
     * @throws \RuntimeException si el volcado falla o sale vacío
     */
    public static function volcar(): array
    {
        $conexion = DB::connection();
        $driver = $conexion->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => ['contenido' => self::volcarMysql(), 'extension' => 'sql'],
            // La suite corre sobre SQLite, así que este camino existe para que
            // el comando se pueda probar de verdad de punta a punta y no sólo
            // con un doble. Un fichero SQLite YA es su propia copia.
            'sqlite' => ['contenido' => self::volcarSqlite($conexion->getDatabaseName()), 'extension' => 'sqlite'],
            default => throw new \RuntimeException("No sé hacer copias de una base {$driver}."),
        };
    }

    /**
     * Comprueba que lo volcado es de verdad una copia de esta base.
     *
     * @throws \RuntimeException con lo que falta, para que el fallo se lea en el
     *                           log del cron sin tener que investigar
     */
    public static function comprobar(string $contenido, string $extension): void
    {
        if (trim($contenido) === '') {
            throw new \RuntimeException('El volcado salió vacío.');
        }

        // Un fichero SQLite no lleva las tablas como texto: se comprueba su
        // cabecera, que es lo que lo identifica como base de datos.
        if ($extension === 'sqlite') {
            if (! str_starts_with($contenido, 'SQLite format 3')) {
                throw new \RuntimeException('El archivo no es una base SQLite.');
            }

            return;
        }

        $faltan = array_values(array_filter(
            self::TABLAS_OBLIGATORIAS,
            fn (string $tabla) => ! str_contains($contenido, $tabla),
        ));

        if ($faltan !== []) {
            throw new \RuntimeException('Al volcado le faltan tablas: '.implode(', ', $faltan).'.');
        }
    }

    /** Cómo se llama la copia de hoy. La fecha va delante para que ordenen solas. */
    public static function nombre(string $extension): string
    {
        return self::CARPETA.'/'.now()->format('Y-m-d_His').'-'.config('app.env').'.'.$extension;
    }

    /**
     * Un disco donde NO se puede guardar una copia.
     *
     * Una copia lleva dentro los datos de todas las tiendas y los correos de
     * todos sus clientes. En un disco público —el de las imágenes en local— se
     * serviría por HTTP desde `/storage`, o sea que un volcado de la base entera
     * quedaría descargable por cualquiera que adivine el nombre del archivo. Es
     * el peor fallo posible de esta función, y por eso no se avisa: se corta.
     */
    public static function discoEsPublico(string $disco): bool
    {
        return ($disco === 'public')
            || (config("filesystems.disks.{$disco}.visibility") === 'public');
    }

    private static function volcarMysql(): string
    {
        $config = config('database.connections.'.config('database.default'));

        $proceso = new Process([
            self::binario('mysqldump'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? 'root'),
            // Una transacción consistente sin bloquear la tienda entera mientras
            // se vuelca: con `--lock-tables` (el defecto) nadie puede comprar
            // durante la copia.
            '--single-transaction',
            // Sin él, `mysqldump` pide el privilegio PROCESS, que un usuario de
            // aplicación no suele tener: la copia fallaba por permisos en un
            // hosting compartido.
            '--no-tablespaces',
            '--routines',
            '--events',
            $config['database'],
        ], env: array_filter([
            // La contraseña por entorno y no como argumento: en la línea de
            // comandos la vería cualquiera con un `ps`.
            'MYSQL_PWD' => $config['password'] ?? null,
        ]));

        $proceso->setTimeout(self::SEGUNDOS_DE_TOPE);
        $proceso->run();

        if (! $proceso->isSuccessful()) {
            throw new \RuntimeException('mysqldump falló: '.trim($proceso->getErrorOutput()));
        }

        return $proceso->getOutput();
    }

    private static function volcarSqlite(string $ruta): string
    {
        if ($ruta === ':memory:' || ! is_file($ruta)) {
            throw new \RuntimeException('La base SQLite no está en un archivo, así que no hay nada que copiar.');
        }

        return (string) file_get_contents($ruta);
    }

    /**
     * El ejecutable, con la ruta que se haya configurado.
     *
     * En muchos hostings `mysqldump` no está en el PATH del usuario del cron
     * aunque sí en el de la sesión interactiva, que es de los fallos más
     * molestos de diagnosticar: a mano funciona y de noche no.
     */
    private static function binario(string $nombre): string
    {
        $ruta = rtrim((string) config('backups.ruta_binarios'), '/\\');

        return $ruta !== '' ? $ruta.DIRECTORY_SEPARATOR.$nombre : $nombre;
    }
}
