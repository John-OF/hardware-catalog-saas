<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Cierra las tiendas cuya prueba venció sin que nadie eligiera un plan (FUN-16).
 *
 * Pensado para el scheduler de Laravel (`routes/console.php`), corriendo a
 * diario -necesita el cron del servidor, `* * * * * php artisan schedule:run`,
 * documentado en `.env.example` igual que el worker de colas (AUD-11)-.
 *
 * Suspender (`is_active = false`) es exactamente lo mismo que ya hace el
 * operador a mano desde el panel de plataforma: `InitializeTenantByHeader` y
 * el catálogo público ya le cierran la puerta a una tienda inactiva, así que
 * este comando no necesita enseñarle nada nuevo a esas dos rutas.
 *
 * Sólo toca tiendas ACTIVAS con `trial_ends_at` vencida: una tienda a la que
 * ya se le asignó un plan de verdad tiene esa columna en `null`
 * (`PlatformController::updateTenant()` la vacía al elegir plan), así que no
 * puede volver a aparecer aquí por error.
 */
class CloseExpiredTrials extends Command
{
    protected $signature = 'trials:cerrar-vencidas';

    protected $description = 'Suspende las tiendas activas cuyo período de prueba ya venció';

    public function handle(): int
    {
        $vencidas = Tenant::where('is_active', true)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get();

        foreach ($vencidas as $tenant) {
            $tenant->update(['is_active' => false]);

            // La caché pública guarda el tenant 5 minutos (mismo motivo que
            // `PlatformController::forgetPublicCache`): sin esto, el catálogo
            // seguiría sirviéndose desde caché un rato después de cerrarse.
            Cache::forget("tenant:{$tenant->slug}");

            // Sin actor ni request: es el sistema quien cierra, no un
            // operador. `registrar()` acepta los dos como null.
            ActivityLog::registrar(
                ActivityLog::TIENDA_PRUEBA_VENCIDA,
                "Se cerró {$tenant->name} al vencer su período de prueba sin que se eligiera un plan.",
                $tenant,
                context: ['trial_ends_at' => $tenant->trial_ends_at?->toIso8601String()],
            );
        }

        $this->info("Tiendas cerradas por prueba vencida: {$vencidas->count()}.");

        return self::SUCCESS;
    }
}
