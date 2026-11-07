<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AbandonedTrialNotification;
use App\Services\ImageService;
use App\Support\PruebasAbandonadas;
use App\Support\Salud;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa a las pruebas abandonadas y, tras dos avisos, borra sus datos (INF-24).
 *
 * Qué le toca a cada tienda lo decide `App\Support\PruebasAbandonadas`; aquí
 * solo se ejecuta, un paso por tienda y por día como mucho, para que dos avisos
 * nunca lleguen juntos aunque el cron haya estado parado.
 *
 * Pensado para el scheduler, a diario y después de la copia de las 03:30: **no
 * borra nada sin una copia de la base de las últimas horas** (`Salud::copia()`,
 * lo mismo que mira `salud:comprobar`). Sin ella, los avisos salen igual, el
 * borrado se salta y el comando sale con error, para que su vigilancia avise
 * (`VIGILANCIA_PURGA_URL`, INF-18). En local no hay copias, así que en local no
 * borra nada a menos que se haga una a mano (`copias:crear`).
 *
 * `--simular` dice qué haría con cada tienda sin avisar ni borrar nada; es lo
 * primero que se lanza en un servidor nuevo. `--tienda=slug` lo limita a una.
 */
class PurgeAbandonedTrials extends Command
{
    protected $signature = 'pruebas:purgar
        {--simular : Dice qué haría con cada tienda, sin avisar ni borrar nada}
        {--tienda= : Solo la tienda con este slug}';

    protected $description = 'Avisa a las pruebas cerradas sin pagar y, a los 90 días y tras dos avisos, borra sus datos';

    public function handle(ImageService $imagenes): int
    {
        $simular = (bool) $this->option('simular');

        $consulta = PruebasAbandonadas::candidatas()->orderBy('trial_ends_at');
        if ($slug = $this->option('tienda')) {
            $consulta->where('slug', $slug);
        }

        // La copia se mira una vez, y solo si a alguna le toca borrar.
        $copia = null;
        $hechos = [PruebasAbandonadas::PASO_PRIMER_AVISO => 0, PruebasAbandonadas::PASO_SEGUNDO_AVISO => 0, PruebasAbandonadas::PASO_BORRAR => 0];
        $fallos = 0;

        foreach ($consulta->get() as $tenant) {
            $paso = PruebasAbandonadas::pasoDeHoy($tenant);

            if ($paso === null) {
                continue;
            }

            if ($paso === PruebasAbandonadas::PASO_BORRAR) {
                $copia ??= Salud::copia();

                if ($copia[0] !== Salud::OK) {
                    $fallos++;
                    $this->error("{$tenant->slug}: no se borra sin una copia reciente de la base. {$copia[1]}");

                    continue;
                }
            }

            if ($simular) {
                $this->line("{$tenant->slug}: ".$this->queHaria($tenant, $paso));

                continue;
            }

            try {
                match ($paso) {
                    PruebasAbandonadas::PASO_PRIMER_AVISO  => $this->avisar($tenant, false),
                    PruebasAbandonadas::PASO_SEGUNDO_AVISO => $this->avisar($tenant, true),
                    PruebasAbandonadas::PASO_BORRAR        => $this->borrar($tenant, $imagenes),
                };
                $hechos[$paso]++;
            } catch (\Throwable $e) {
                // Una tienda que falla no frena al resto; el código de salida
                // hace que su vigilancia avise.
                $fallos++;
                report($e);
                $this->error("{$tenant->slug}: {$e->getMessage()}");
            }
        }

        if (! $simular) {
            $this->info("Primeros avisos: {$hechos[PruebasAbandonadas::PASO_PRIMER_AVISO]}. Últimos avisos: {$hechos[PruebasAbandonadas::PASO_SEGUNDO_AVISO]}. Tiendas borradas: {$hechos[PruebasAbandonadas::PASO_BORRAR]}.");
        }

        return $fallos > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function avisar(Tenant $tenant, bool $ultimo): void
    {
        $fecha = $ultimo ? PruebasAbandonadas::fechaAlRecordar($tenant) : PruebasAbandonadas::fechaAlAvisar($tenant);

        $admins = User::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get();

        // La fecha y el correo, juntos: si encolar el correo falla, el aviso no
        // cuenta como dado y se repite mañana. Un aviso de más es mejor que un
        // borrado sin avisar.
        DB::transaction(function () use ($tenant, $ultimo, $fecha, $admins) {
            $tenant->forceFill($ultimo
                ? ['purge_scheduled_at' => $fecha, 'purge_reminded_at' => now()]
                : ['purge_scheduled_at' => $fecha])->save();

            Notification::send($admins, new AbandonedTrialNotification(
                $tenant->name,
                $this->enLaZona($tenant->trial_ends_at, $tenant),
                $this->enLaZona($fecha, $tenant),
                $ultimo,
            ));

            $aQuien = $admins->isEmpty()
                ? 'a nadie: no tiene ningún administrador activo'
                : ($admins->count() === 1 ? 'a su administrador' : "a sus {$admins->count()} administradores");

            ActivityLog::registrar(
                ActivityLog::TIENDA_AVISO_DE_PURGA,
                ($ultimo ? 'Último aviso' : 'Primer aviso')." a {$tenant->name}: sus datos se borran el {$this->enLaZona($fecha, $tenant)} si no elige un plan. Enviado {$aQuien}.",
                $tenant,
                context: [
                    'paso' => $ultimo ? PruebasAbandonadas::PASO_SEGUNDO_AVISO : PruebasAbandonadas::PASO_PRIMER_AVISO,
                    'borrado_desde' => $fecha->toIso8601String(),
                    'avisados' => $admins->count(),
                ],
            );
        });

        $this->line("{$tenant->slug}: ".($ultimo ? 'último' : 'primer').' aviso, borrado el '.$this->enLaZona($fecha, $tenant).'.');
    }

    private function borrar(Tenant $tenant, ImageService $imagenes): void
    {
        $filas = PruebasAbandonadas::borrar($tenant, $imagenes);

        $tenant->forceFill(['data_purged_at' => now()])->save();

        ActivityLog::registrar(
            ActivityLog::TIENDA_DATOS_BORRADOS,
            // «Productos: 1» y no «1 productos». Los clientes son las fichas del
            // directorio: las de quien tiene cuenta se vuelven a crear vacías.
            // Los días de verdad, no los 90 de la regla: con el cron parado son más.
            "Se borraron los datos de {$tenant->name}, a los ".(int) $tenant->trial_ends_at->diffInDays(now()).' días de cerrarse su prueba sin pagar. '
                ."Productos: {$filas['products']}. Pedidos: {$filas['orders']}. Clientes del directorio: {$filas['customers']}.",
            $tenant,
            context: ['filas' => $filas],
        );

        // También al log: es un borrado masivo, y la bitácora vive en la misma
        // base que se acaba de tocar.
        Log::info("pruebas:purgar borró los datos de {$tenant->slug} ({$tenant->id}).", $filas);

        $this->line("{$tenant->slug}: datos borrados (".collect($filas)->map(fn ($n, $tabla) => "{$tabla} {$n}")->implode(', ').').');
    }

    private function queHaria(Tenant $tenant, string $paso): string
    {
        return match ($paso) {
            PruebasAbandonadas::PASO_PRIMER_AVISO  => 'primer aviso, con el borrado el '.$this->enLaZona(PruebasAbandonadas::fechaAlAvisar($tenant), $tenant).'.',
            PruebasAbandonadas::PASO_SEGUNDO_AVISO => 'último aviso, con el borrado el '.$this->enLaZona(PruebasAbandonadas::fechaAlRecordar($tenant), $tenant).'.',
            PruebasAbandonadas::PASO_BORRAR        => 'borraría '.collect(PruebasAbandonadas::contar((string) $tenant->id))->map(fn ($n, $tabla) => "{$tabla} {$n}")->implode(', ').'.',
        };
    }

    /** Una fecha como la lee el dueño: el día en la zona de su tienda (MOD-13). */
    private function enLaZona(CarbonInterface $fecha, Tenant $tenant): string
    {
        return $fecha->copy()->timezone($tenant->timezone ?: 'UTC')->format('d/m/Y');
    }
}
