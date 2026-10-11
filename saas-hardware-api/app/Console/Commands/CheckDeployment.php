<?php

namespace App\Console\Commands;

use App\Support\Salud;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Comprueba lo que el despliegue necesita y que, si falta, no da ningún error
 * (`INF-19`).
 *
 * Se lanza **al final de cada despliegue** (README, *Al desplegar una versión
 * nueva*), después de `migrate --force` y `queue:restart`: así comprueba que las
 * dos se hicieron, y no solo que se escribieron en una lista. Y cada hora desde
 * el scheduler, con su aviso de `config/salud.php` (`INF-18`): si algo falla,
 * avisa a `/fail`; si el propio cron se para, el aviso deja de llegar.
 *
 * Sale con código distinto de cero si falla cualquiera, y lo escribe en el log:
 * el qué se comprueba y el porqué de cada una está en `App\Support\Salud`.
 */
class CheckDeployment extends Command
{
    protected $signature = 'salud:comprobar
        {--espera= : Segundos que se espera a que un worker atienda el trabajo de prueba; por defecto, los de config/salud.php}';

    protected $description = 'Comprueba lo que el despliegue necesita y falla en silencio si falta: worker, cron, copias, correo, PHP… (INF-19)';

    public function handle(): int
    {
        $espera = (int) ($this->option('espera') ?? config('salud.espera_cola'));

        $this->components->info('Comprobando el despliegue ('.config('app.env').')');

        $fallos = [];

        foreach (Salud::comprobar($espera) as $nombre => [$estado, $detalle]) {
            $this->components->twoColumnDetail($nombre, match ($estado) {
                Salud::OK    => '<fg=green;options=bold>OK</>',
                Salud::FALLO => '<fg=red;options=bold>FALLO</>',
                default      => '<fg=gray>NO APLICA</>',
            });
            $this->line("  <fg=gray>{$detalle}</>");

            if ($estado === Salud::FALLO) {
                $fallos[$nombre] = $detalle;
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>Desde aquí no se ve: VITE_PLATFORM_HOSTS (va en el build del frontend), el client_max_body_size de nginx ni los registros SPF/DKIM del correo.</>');
        $this->newLine();

        if ($fallos !== []) {
            Log::error('salud:comprobar encontró fallos en el despliegue', ['fallos' => $fallos]);
            $this->components->error(count($fallos).' de las comprobaciones fallaron.');

            return self::FAILURE;
        }

        $this->components->info('Todo en orden.');

        return self::SUCCESS;
    }
}
