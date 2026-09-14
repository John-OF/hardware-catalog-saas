<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El comando programado que cierra las pruebas vencidas (FUN-16).
 *
 * Necesita el cron del servidor corriendo `artisan schedule:run` para
 * dispararse solo en producción (documentado en `.env.example`, mismo tipo de
 * dependencia de despliegue que el worker de colas de `AUD-11`); aquí se
 * prueba el comando en sí, no el scheduler.
 */
class CloseExpiredTrialsTest extends TestCase
{
    use RefreshDatabase;

    private function crearTienda(string $slug, ?\Illuminate\Support\Carbon $trialEndsAt, bool $activa = true): Tenant
    {
        $tenant = Tenant::create([
            'slug' => $slug, 'name' => $slug,
            'whatsapp_number' => '51999999999', 'is_active' => $activa,
        ]);

        if ($trialEndsAt) {
            $tenant->forceFill(['trial_ends_at' => $trialEndsAt])->save();
        }

        return $tenant;
    }

    public function test_cierra_una_tienda_activa_con_la_prueba_vencida(): void
    {
        $tenant = $this->crearTienda('vencida', now()->subDay());

        $this->artisan('trials:cerrar-vencidas')->assertExitCode(0);

        $this->assertFalse($tenant->fresh()->is_active);
    }

    public function test_no_toca_una_tienda_cuya_prueba_todavia_no_vence(): void
    {
        $tenant = $this->crearTienda('vigente', now()->addDays(2));

        $this->artisan('trials:cerrar-vencidas');

        $this->assertTrue($tenant->fresh()->is_active);
    }

    /**
     * Sin `trial_ends_at` la tienda nunca estuvo en prueba (de antes de
     * FUN-16, o creada directamente por el operador): el comando no puede
     * tocarla.
     */
    public function test_no_toca_una_tienda_sin_periodo_de_prueba(): void
    {
        $tenant = $this->crearTienda('sin-prueba', null);

        $this->artisan('trials:cerrar-vencidas');

        $this->assertTrue($tenant->fresh()->is_active);
    }

    /**
     * Ya asignada a un plan de verdad -`trial_ends_at` se vacía al elegir
     * plan (ver `TenantTrialTest`)-, así que aunque la fecha antigua siguiera
     * ahí no debería volver a aparecer. Se prueba directamente el caso que
     * SÍ podría dar problema: una tienda YA inactiva con prueba vencida no
     * debe generar una segunda entrada en la bitácora ni tocarse de nuevo.
     */
    public function test_no_repite_el_cierre_de_una_tienda_ya_inactiva(): void
    {
        $tenant = $this->crearTienda('ya-cerrada', now()->subDays(10), activa: false);

        $this->artisan('trials:cerrar-vencidas');

        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertFalse($tenant->fresh()->is_active);
    }

    public function test_deja_registro_en_la_bitacora_sin_actor(): void
    {
        $tenant = $this->crearTienda('para-bitacora', now()->subHour());

        $this->artisan('trials:cerrar-vencidas');

        $linea = ActivityLog::where('action', ActivityLog::TIENDA_PRUEBA_VENCIDA)->first();

        $this->assertNotNull($linea);
        $this->assertSame($tenant->id, $linea->tenant_id);
        $this->assertNull($linea->actor_id);
        $this->assertNull($linea->actor_email);
    }

    public function test_cierra_varias_tiendas_vencidas_de_una_vez(): void
    {
        $this->crearTienda('vencida-1', now()->subDay());
        $this->crearTienda('vencida-2', now()->subDays(3));
        $this->crearTienda('vigente', now()->addDay());

        $this->artisan('trials:cerrar-vencidas');

        $this->assertSame(2, ActivityLog::where('action', ActivityLog::TIENDA_PRUEBA_VENCIDA)->count());
        $this->assertFalse(Tenant::where('slug', 'vencida-1')->first()->is_active);
        $this->assertFalse(Tenant::where('slug', 'vencida-2')->first()->is_active);
        $this->assertTrue(Tenant::where('slug', 'vigente')->first()->is_active);
    }
}
