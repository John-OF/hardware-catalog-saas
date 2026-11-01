<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Support\PlanGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Cierra las tiendas cuya prueba venció sin que se pagara un plan (FUN-16), y
 * las que tenían un plan pagado a mano que ya venció (INF-23).
 *
 * Pensado para el scheduler de Laravel (`routes/console.php`), corriendo a
 * diario -necesita el cron del servidor, `* * * * * php artisan schedule:run`,
 * documentado en `.env.example` igual que el worker de colas (AUD-11)-.
 *
 * Se llama `trials:` por historia: nació solo para las pruebas, y renombrarlo
 * cambiaría también su vigilancia (`VIGILANCIA_PRUEBAS_URL`, INF-18) sin ganar
 * nada. Las dos cosas son el mismo cierre: una fecha que pasó.
 *
 * Suspender (`is_active = false`) es exactamente lo mismo que hace el operador a
 * mano desde el panel de plataforma: `InitializeTenantByHeader` y el catálogo
 * público ya le cierran la puerta a una tienda inactiva. Lo que cambia es lo que
 * ve su dueño al entrar (FUN-28): `Tenant::cierre()` deduce el motivo de las
 * mismas dos fechas que mira este comando.
 *
 * Solo toca tiendas ACTIVAS. Una que pagó tiene `trial_ends_at` en `null`
 * (`Membresia::registrarPago()` la vacía), así que no puede caer por la prueba; y
 * una con el plan vencido que el operador intente reabrir sin pago se rechaza
 * antes (`Membresia::motivoParaNoReabrir()`), en vez de volver a cerrarse aquí.
 */
class CloseExpiredTrials extends Command
{
    protected $signature = 'trials:cerrar-vencidas';

    protected $description = 'Suspende las tiendas activas cuya prueba o plan pagado ya venció';

    public function handle(): int
    {
        $pruebas = Tenant::where('is_active', true)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get();

        foreach ($pruebas as $tenant) {
            $this->cerrar($tenant);

            // Sin actor ni request: es el sistema quien cierra, no un
            // operador. `registrar()` acepta los dos como null.
            ActivityLog::registrar(
                ActivityLog::TIENDA_PRUEBA_VENCIDA,
                "Se cerró {$tenant->name} al vencer su período de prueba sin que se pagara un plan.",
                $tenant,
                context: ['trial_ends_at' => $tenant->trial_ends_at?->toIso8601String()],
            );
        }

        $planes = Tenant::where('is_active', true)
            ->whereNotNull('plan_ends_at')
            ->where('plan_ends_at', '<', now())
            ->get();

        foreach ($planes as $tenant) {
            $this->cerrar($tenant);

            $plan = PlanGate::labelDe((string) $tenant->plan);

            ActivityLog::registrar(
                ActivityLog::TIENDA_PLAN_VENCIDO,
                "Se cerró {$tenant->name} al vencer su plan {$plan} sin que se registrara la renovación.",
                $tenant,
                context: ['plan' => $tenant->plan, 'plan_ends_at' => $tenant->plan_ends_at?->toIso8601String()],
            );
        }

        $this->info("Tiendas cerradas por prueba vencida: {$pruebas->count()}.");
        $this->info("Tiendas cerradas por plan vencido: {$planes->count()}.");

        return self::SUCCESS;
    }

    private function cerrar(Tenant $tenant): void
    {
        $tenant->update(['is_active' => false]);

        // La caché pública guarda el tenant 5 minutos (mismo motivo que
        // `PlatformController::forgetPublicCache`): sin esto, el catálogo
        // seguiría sirviéndose desde caché un rato después de cerrarse.
        Cache::forget("tenant:{$tenant->slug}");
    }
}
