<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `bitacora:purgar` (INF-20): la actividad del panel de tienda caduca al año, y
 * la de plataforma no caduca nunca.
 *
 * Lo que importa vigilar es lo que NO debe borrar: la de plataforma es la que
 * responde "quién suspendió esta tienda", y un `delete()` con un `where` de
 * menos se la llevaría con el resto (el incidente de `TEC-16` fue eso mismo).
 */
class PurgarBitacoraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'tienda-bitacora', 'name' => 'Tienda Bitacora', 'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);
    }

    /** Una línea con la fecha que se quiera: `created_at` no es fillable. */
    private function linea(string $origen, int $haceDias, string $descripcion = 'Algo'): string
    {
        $id = (string) Str::uuid7();

        DB::table('activity_logs')->insert([
            'id' => $id, 'origen' => $origen, 'tenant_id' => $this->tenant->id,
            'action' => $origen === ActivityLog::ORIGEN_TIENDA ? ActivityLog::PRODUCTO_EDITADO : ActivityLog::TIENDA_SOPORTE,
            'description' => $descripcion,
            'created_at' => now()->subDays($haceDias), 'updated_at' => now()->subDays($haceDias),
        ]);

        return $id;
    }

    private function quedan(): array
    {
        return DB::table('activity_logs')->orderBy('description')->pluck('description')->all();
    }

    public function test_borra_la_de_tienda_de_mas_de_un_anio_y_nada_mas(): void
    {
        $this->linea(ActivityLog::ORIGEN_TIENDA, ActivityLog::DIAS_EN_TIENDA + 1, 'tienda vieja');
        $this->linea(ActivityLog::ORIGEN_TIENDA, ActivityLog::DIAS_EN_TIENDA - 1, 'tienda reciente');
        $this->linea(ActivityLog::ORIGEN_PLATAFORMA, ActivityLog::DIAS_EN_TIENDA * 3, 'plataforma vieja');

        $this->artisan('bitacora:purgar')->assertSuccessful();

        $this->assertSame(['plataforma vieja', 'tienda reciente'], $this->quedan());
    }

    public function test_los_dias_se_pueden_cambiar_al_lanzarlo(): void
    {
        $this->linea(ActivityLog::ORIGEN_TIENDA, 40, 'de hace 40');
        $this->linea(ActivityLog::ORIGEN_TIENDA, 20, 'de hace 20');

        $this->artisan('bitacora:purgar', ['--dias' => 30])->assertSuccessful();

        $this->assertSame(['de hace 20'], $this->quedan());
    }

    /** `--dias=0` lo borraría todo: es justo el error que no tiene que salir barato. */
    public function test_cero_dias_se_rechaza_sin_borrar_nada(): void
    {
        $this->linea(ActivityLog::ORIGEN_TIENDA, 5, 'de hace 5');

        $this->artisan('bitacora:purgar', ['--dias' => 0])->assertFailed();

        $this->assertSame(['de hace 5'], $this->quedan());
    }

    /** Por tandas de mil: tiene que seguir hasta acabar, no quedarse en la primera. */
    public function test_borra_aunque_haya_mas_de_una_tanda(): void
    {
        $viejas = [];

        foreach (range(1, 1205) as $n) {
            $viejas[] = [
                'id' => (string) Str::uuid7(), 'origen' => ActivityLog::ORIGEN_TIENDA, 'tenant_id' => $this->tenant->id,
                'action' => ActivityLog::PRODUCTO_EDITADO, 'description' => "vieja {$n}",
                'created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400),
            ];
        }

        foreach (array_chunk($viejas, 300) as $tanda) {
            DB::table('activity_logs')->insert($tanda);
        }

        $this->linea(ActivityLog::ORIGEN_TIENDA, 1, 'la de ayer');

        $this->artisan('bitacora:purgar')
            ->expectsOutputToContain('1205 líneas')
            ->assertSuccessful();

        $this->assertSame(['la de ayer'], $this->quedan());
    }
}
