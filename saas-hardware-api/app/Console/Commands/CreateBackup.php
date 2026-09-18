<?php

namespace App\Console\Commands;

use App\Support\Copias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Crea una copia de seguridad de la base y borra las que ya caducaron (`INF-7`).
 *
 * Pensado para el scheduler (`routes/console.php`), a diario — necesita el cron
 * del servidor, `* * * * * php artisan schedule:run`, el mismo que ya hacía
 * falta desde `FUN-16`. Sin él esto no se dispara nunca, aunque el comando
 * funcione perfecto al ejecutarlo a mano.
 *
 * **Falla ruidosamente a propósito.** Devuelve un código de salida distinto de
 * cero y escribe en el log de errores, porque el fallo típico de un backup no es
 * que reviente: es que produzca un archivo vacío durante meses sin que nadie
 * mire. Un cron que no avisa es una copia que no existe.
 *
 * **No hay comando para restaurar**, y es deliberado: restaurar pisa la base de
 * producción entera. Un comando que puede hacer eso a un tecleo de distancia es
 * un peligro mayor que la comodidad que da; el procedimiento está escrito en el
 * README (*Restaurar una copia*) para hacerlo a mano y mirando.
 */
class CreateBackup extends Command
{
    protected $signature = 'copias:crear
        {--disco= : El disco donde guardarla; por defecto, el de config/backups.php}
        {--retener= : Dias que se guardan las copias; por defecto, el de la config}';

    protected $description = 'Vuelca la base de datos a una copia de seguridad y purga las caducadas';

    public function handle(): int
    {
        $disco = (string) ($this->option('disco') ?: config('backups.disco'));
        $dias = (int) ($this->option('retener') ?: config('backups.retencion_dias'));

        // Lo primero, antes de volcar nada: una copia en un disco publico es un
        // volcado de la base entera descargable por HTTP.
        if (Copias::discoEsPublico($disco)) {
            $this->error("El disco «{$disco}» es publico: una copia ahi quedaria descargable por cualquiera. Usa 'local', 'r2' o 's3'.");

            return self::FAILURE;
        }

        try {
            ['contenido' => $contenido, 'extension' => $extension] = Copias::volcar();

            // Se comprueba ANTES de guardarla. Guardar primero y comprobar
            // despues dejaria el archivo malo en el disco y, peor, contando como
            // la copia del dia.
            Copias::comprobar($contenido, $extension);

            $nombre = Copias::nombre($extension);

            Storage::disk($disco)->put($nombre, $contenido);
        } catch (\Throwable $e) {
            Log::error('Fallo la copia de seguridad', ['disco' => $disco, 'exception' => $e]);
            $this->error('No se pudo crear la copia: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Copia creada: '.$nombre.' ('.$this->enMegas(strlen($contenido)).' MB) en el disco '.$disco.'.');

        $borradas = $this->purgar($disco, $dias);

        $this->info("Copias caducadas borradas: {$borradas} (se guardan {$dias} dias).");

        return self::SUCCESS;
    }

    /**
     * Borra las copias mas viejas que la retencion.
     *
     * Por la fecha del archivo en el disco y no por su nombre: el nombre lo
     * puede haber cambiado alguien a mano, y lo que decide si una copia caduco
     * es cuando se escribio.
     */
    private function purgar(string $disco, int $dias): int
    {
        $almacen = Storage::disk($disco);
        $corte = now()->subDays($dias)->getTimestamp();
        $borradas = 0;

        foreach ($almacen->files(Copias::CARPETA) as $archivo) {
            if ($almacen->lastModified($archivo) < $corte) {
                $almacen->delete($archivo);
                $borradas++;
            }
        }

        return $borradas;
    }

    private function enMegas(int $bytes): string
    {
        return number_format($bytes / 1048576, 2);
    }
}
