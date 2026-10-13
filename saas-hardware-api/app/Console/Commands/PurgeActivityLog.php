<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/**
 * Borra la actividad de tienda que pasó su tiempo (`INF-20`).
 *
 * Pensado para el scheduler (`routes/console.php`), a diario, con el mismo cron
 * que ya hacía falta desde `FUN-16`. Sin él `activity_logs` crece sin límite:
 * cada import escribe una línea con el detalle de hasta 100 productos.
 *
 * Solo toca `origen = tienda`. La de plataforma no caduca (ver
 * `ActivityLog::DIAS_EN_TIENDA`). `ActivityLog` no usa `BelongsToTenant`, así
 * que aquí no hay scope que se salte ni que haga falta saltarse: la consulta
 * lleva sus dos `where` escritos, y nunca se lanza sin ellos (operacion.md,
 * regla 1).
 *
 * Borra por tandas: un `DELETE` de un año de imports de golpe bloquearía la
 * tabla mientras el equipo de cada tienda sigue escribiendo en ella.
 */
class PurgeActivityLog extends Command
{
    protected $signature = 'bitacora:purgar {--dias= : Días de retención, por defecto los de ActivityLog::DIAS_EN_TIENDA}';

    protected $description = 'Borra la actividad del panel de tienda de más de un año';

    private const TANDA = 1000;

    public function handle(): int
    {
        $dias = (int) ($this->option('dias') ?? ActivityLog::DIAS_EN_TIENDA);

        if ($dias < 1) {
            $this->error('Los días de retención tienen que ser al menos 1.');

            return self::FAILURE;
        }

        $corte = now()->subDays($dias);
        $borradas = 0;

        do {
            $ids = ActivityLog::query()
                ->where('origen', ActivityLog::ORIGEN_TIENDA)
                ->where('created_at', '<', $corte)
                ->limit(self::TANDA)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $borradas += ActivityLog::query()->whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === self::TANDA);

        // Sin anotarlo en ninguna bitácora: aquí no hay "quién", y la pantalla
        // de Actividad ya dice cuánto tiempo se guarda.
        $this->info("Purgadas de la actividad de tienda: {$borradas} líneas (más de {$dias} días).");

        return self::SUCCESS;
    }
}
