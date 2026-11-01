<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\TenantPayment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Un plan cobrado a mano tiene fecha de fin (INF-23).
 *
 * Lo que fija este test:
 *
 * 1. Registrar un pago pone la tienda en ese plan hasta una fecha, la saca de la
 *    prueba y, si estaba cerrada, la reabre. Y queda en el historial y en la
 *    bitácora.
 * 2. Los meses se suman a lo que ya tenga (prueba o plan) y no se desbordan: el
 *    31 de enero más un mes es el 28 de febrero.
 * 3. Al vencer, el cierre automático la cierra; y no se puede reabrir a mano sin
 *    un pago, porque se volvería a cerrar al día siguiente.
 * 4. La fecha se puede corregir, pero solo si ya hay pagos: no es otra forma de
 *    dar un plan sin cobrarlo.
 */
class PagosDelPlanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;
    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Carbon::setTestNow('2026-11-10 15:00:00');

        $this->tienda = Tenant::create([
            'slug' => 'cobrada', 'name' => 'Tienda Cobrada',
            'whatsapp_number' => '593999999999', 'is_active' => true,
        ]);

        $this->operador = new User([
            'name' => 'Operador', 'email' => 'operador@plataforma.test',
            'password' => 'password123', 'role' => 'superadmin', 'is_active' => true,
        ]);
        $this->operador->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function comoOperador(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->operador->createToken('t', ['superadmin'])->plainTextToken);
    }

    private function pagar(array $cambios = []): \Illuminate\Testing\TestResponse
    {
        return $this->comoOperador()->postJson("/api/platform/tenants/{$this->tienda->id}/payments", $cambios + [
            'plan'       => 'pro',
            'months'     => 1,
            'amount_usd' => 29,
            'method'     => 'transferencia',
            'reference'  => 'OP-123',
            'paid_on'    => '2026-11-10',
        ]);
    }

    private function fechas(?Carbon $prueba = null, ?Carbon $plan = null, bool $activa = true): void
    {
        $this->tienda->forceFill([
            'trial_ends_at' => $prueba,
            'plan_ends_at'  => $plan,
            'is_active'     => $activa,
        ])->save();
    }

    // --- Registrar un pago -------------------------------------------------

    public function test_registrar_un_pago_pone_el_plan_hasta_una_fecha_y_lo_anota(): void
    {
        $this->pagar()->assertCreated()->assertJsonPath('tenant.plan', 'pro');

        $tienda = $this->tienda->fresh();
        $this->assertSame('pro', $tienda->plan);
        $this->assertSame('2026-12-10 15:00:00', $tienda->plan_ends_at->toDateTimeString());

        $pago = TenantPayment::sole();
        $this->assertSame($this->tienda->id, $pago->tenant_id);
        $this->assertSame(1, $pago->months);
        $this->assertSame('29.00', (string) $pago->amount_usd);
        $this->assertSame('OP-123', $pago->reference);
        $this->assertSame('2026-11-10 15:00:00', $pago->starts_at->toDateTimeString());
        $this->assertSame($this->operador->id, $pago->registered_by);
        $this->assertSame('operador@plataforma.test', $pago->registered_by_email);

        $linea = ActivityLog::where('action', ActivityLog::TIENDA_PAGO)->sole();
        $this->assertSame($this->tienda->id, $linea->tenant_id);
        $this->assertSame('operador@plataforma.test', $linea->actor_email);
        $this->assertStringContainsString('Pro, 1 mes', $linea->description);
        $this->assertStringContainsString('por transferencia', $linea->description);
        $this->assertStringContainsString('Pagado hasta el 10/12/2026', $linea->description);
        $this->assertSame($pago->id, $linea->context['pago']);
    }

    public function test_pagar_durante_la_prueba_la_termina_y_cuenta_desde_su_final(): void
    {
        $this->fechas(prueba: now()->addDays(3));

        $this->pagar()->assertCreated();

        $tienda = $this->tienda->fresh();
        $this->assertNull($tienda->trial_ends_at);
        $this->assertFalse($tienda->enPrueba());
        // Pagar antes de tiempo no hace perder los días de prueba que quedaban.
        $this->assertSame('2026-12-13 15:00:00', $tienda->plan_ends_at->toDateTimeString());
    }

    public function test_renovar_antes_de_que_venza_suma_desde_la_fecha_que_tenia(): void
    {
        $this->fechas(plan: Carbon::parse('2026-11-20 08:00:00'));

        $this->pagar(['months' => 3, 'amount_usd' => 87])->assertCreated();

        $this->assertSame('2027-02-20 08:00:00', $this->tienda->fresh()->plan_ends_at->toDateTimeString());
        $this->assertSame('2026-11-20 08:00:00', TenantPayment::sole()->starts_at->toDateTimeString());
    }

    public function test_renovar_un_plan_ya_vencido_cuenta_desde_hoy_y_no_desde_la_fecha_vieja(): void
    {
        $this->fechas(plan: now()->subDays(5), activa: false);

        $this->pagar()->assertCreated();

        $this->assertSame('2026-12-10 15:00:00', $this->tienda->fresh()->plan_ends_at->toDateTimeString());
    }

    public function test_un_mes_despues_del_31_de_enero_es_el_28_de_febrero(): void
    {
        Carbon::setTestNow('2027-01-31 10:00:00');

        $this->pagar(['paid_on' => '2027-01-31'])->assertCreated();

        $this->assertSame('2027-02-28 10:00:00', $this->tienda->fresh()->plan_ends_at->toDateTimeString());
    }

    public function test_pagar_reabre_una_tienda_cerrada_por_plan_vencido(): void
    {
        $this->fechas(plan: now()->subDay(), activa: false);

        $this->pagar()->assertCreated()->assertJsonPath('tenant.is_active', true);

        $this->assertTrue($this->tienda->fresh()->is_active);
        $linea = ActivityLog::where('action', ActivityLog::TIENDA_PAGO)->sole();
        $this->assertTrue($linea->context['reabierta']);
        $this->assertStringContainsString('La tienda vuelve a abrir.', $linea->description);
    }

    public function test_una_cortesia_es_sin_cobro_y_un_pago_sin_cobro_es_una_cortesia(): void
    {
        $this->pagar(['method' => 'cortesia', 'amount_usd' => 29])
            ->assertStatus(422)->assertJsonValidationErrors('amount_usd');

        $this->pagar(['method' => 'transferencia', 'amount_usd' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('amount_usd');

        $this->assertSame(0, TenantPayment::count());

        $this->pagar(['method' => 'cortesia', 'amount_usd' => 0, 'reference' => null])->assertCreated();

        $this->assertStringContainsString('sin cobro (cortesía)', ActivityLog::where('action', ActivityLog::TIENDA_PAGO)->sole()->description);
    }

    public function test_rechaza_lo_que_no_es_un_pago_valido(): void
    {
        $this->pagar(['plan' => 'trial'])->assertStatus(422)->assertJsonValidationErrors('plan');
        $this->pagar(['plan' => 'infinito'])->assertStatus(422)->assertJsonValidationErrors('plan');
        $this->pagar(['months' => 0])->assertStatus(422)->assertJsonValidationErrors('months');
        $this->pagar(['months' => TenantPayment::MESES_MAXIMOS + 1])->assertStatus(422)->assertJsonValidationErrors('months');
        $this->pagar(['method' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->pagar(['paid_on' => '2026-11-11'])->assertStatus(422)->assertJsonValidationErrors('paid_on');

        $this->assertSame(0, TenantPayment::count());
        $this->assertNull($this->tienda->fresh()->plan_ends_at);
    }

    public function test_solo_el_operador_registra_pagos(): void
    {
        $duenio = new User([
            'name' => 'Dueño', 'email' => 'duenio@cobrada.test',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $duenio->tenant_id = $this->tienda->id;
        $duenio->save();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$duenio->createToken('t', ['admin'])->plainTextToken,
            'X-Tenant'      => $this->tienda->slug,
        ])->postJson("/api/platform/tenants/{$this->tienda->id}/payments", [
            'plan' => 'enterprise', 'months' => 24, 'amount_usd' => 1,
            'method' => 'otro', 'paid_on' => '2026-11-10',
        ])->assertForbidden();

        $this->assertSame(0, TenantPayment::count());
    }

    public function test_una_tienda_que_pago_no_se_puede_borrar(): void
    {
        $this->pagar()->assertCreated();

        $this->expectException(QueryException::class);

        $this->tienda->delete();
    }

    // --- Vencer ------------------------------------------------------------

    public function test_el_cierre_automatico_cierra_los_planes_vencidos_y_lo_anota(): void
    {
        $this->fechas(plan: now()->subMinute());

        $vigente = Tenant::create(['slug' => 'vigente', 'name' => 'Vigente', 'whatsapp_number' => '1', 'is_active' => true]);
        $vigente->forceFill(['plan_ends_at' => now()->addDay()])->save();

        // Sin fecha: un plan de antes de INF-23. No se toca.
        $sinFecha = Tenant::create(['slug' => 'sin-fecha', 'name' => 'Sin Fecha', 'whatsapp_number' => '1', 'is_active' => true]);

        $this->artisan('trials:cerrar-vencidas')->assertExitCode(0);

        $this->assertFalse($this->tienda->fresh()->is_active);
        $this->assertTrue($vigente->fresh()->is_active);
        $this->assertTrue($sinFecha->fresh()->is_active);

        $linea = ActivityLog::where('action', ActivityLog::TIENDA_PLAN_VENCIDO)->sole();
        $this->assertSame($this->tienda->id, $linea->tenant_id);
        $this->assertNull($linea->actor_id);
    }

    public function test_no_se_reabre_a_mano_una_tienda_con_el_plan_vencido(): void
    {
        $this->fechas(plan: now()->subDay(), activa: false);

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->tienda->id}", ['is_active' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertFalse($this->tienda->fresh()->is_active);
    }

    public function test_no_se_reabre_a_mano_una_tienda_con_la_prueba_vencida(): void
    {
        $this->fechas(prueba: now()->subDay(), activa: false);

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->tienda->id}", ['is_active' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertFalse($this->tienda->fresh()->is_active);
    }

    public function test_una_suspendida_a_mano_con_el_plan_vigente_si_se_reabre(): void
    {
        $this->fechas(plan: now()->addMonth(), activa: false);

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->tienda->id}", ['is_active' => true])
            ->assertOk();

        $this->assertTrue($this->tienda->fresh()->is_active);
    }

    // --- Corregir la fecha -------------------------------------------------

    public function test_se_corrige_la_fecha_de_un_pago_mal_apuntado_y_queda_en_la_bitacora(): void
    {
        $this->pagar(['months' => 12, 'amount_usd' => 29])->assertCreated();

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->tienda->id}", ['plan_ends_at' => '2026-12-10'])
            ->assertOk();

        // Hasta que acaba ese día en la zona de la tienda (UTC por defecto).
        $this->assertSame('2026-12-10 23:59:59', $this->tienda->fresh()->plan_ends_at->toDateTimeString());

        $linea = ActivityLog::where('action', ActivityLog::TIENDA_FECHA_PLAN)->sole();
        $this->assertStringContainsString('del 10/11/2027 al 10/12/2026', $linea->description);
    }

    public function test_la_fecha_corregida_es_el_dia_de_la_tienda(): void
    {
        $this->tienda->update(['timezone' => 'America/Guayaquil']);
        $this->fechas(plan: now()->addDay());

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->tienda->id}", ['plan_ends_at' => '2026-12-10'])
            ->assertOk();

        // 23:59:59 en Guayaquil (UTC-5) son las 04:59:59 del día siguiente en UTC.
        $this->assertSame('2026-12-11 04:59:59', $this->tienda->fresh()->plan_ends_at->toDateTimeString());
    }

    public function test_no_se_pone_fecha_a_una_tienda_sin_pagos(): void
    {
        $this->fechas(prueba: now()->addDays(3));

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->tienda->id}", ['plan_ends_at' => '2027-06-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_ends_at');

        $this->assertNull($this->tienda->fresh()->plan_ends_at);
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::TIENDA_FECHA_PLAN)->count());
    }

    public function test_no_se_corrige_a_una_fecha_pasada(): void
    {
        $this->fechas(plan: now()->addMonth());

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->tienda->id}", ['plan_ends_at' => '2026-11-09'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_ends_at');
    }

    public function test_corregir_la_fecha_y_reabrir_en_la_misma_peticion(): void
    {
        $this->fechas(plan: now()->subDay(), activa: false);

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->tienda->id}", [
            'plan_ends_at' => '2026-11-30',
            'is_active'    => true,
        ])->assertOk()->assertJsonPath('is_active', true);
    }

    // --- Lo que ve el operador ---------------------------------------------

    public function test_la_ficha_trae_los_pagos_y_desde_cuando_contaria_el_siguiente(): void
    {
        $this->pagar()->assertCreated();

        $ficha = $this->comoOperador()->getJson("/api/platform/tenants/{$this->tienda->id}")->assertOk();

        $ficha->assertJsonPath('membresia.cierre', null)
            ->assertJsonPath('membresia.pagos.0.method', 'transferencia')
            ->assertJsonPath('membresia.medios.cortesia', 'Cortesía (sin cobro)')
            ->assertJsonPath('membresia.meses_max', TenantPayment::MESES_MAXIMOS);

        $this->assertSame(['free', 'pro', 'enterprise'], array_column($ficha->json('membresia.planes'), 'key'));
        $this->assertSame('2026-12-10T15:00:00+00:00', $ficha->json('membresia.desde'));
        $this->assertNotNull($ficha->json('tenant.plan_ends_at'));
    }

    public function test_el_resumen_y_el_listado_cuentan_las_que_vencen_esta_semana(): void
    {
        $this->fechas(plan: now()->addDays(3));

        $lejana = Tenant::create(['slug' => 'lejana', 'name' => 'Lejana', 'whatsapp_number' => '1', 'is_active' => true]);
        $lejana->forceFill(['plan_ends_at' => now()->addDays(20)])->save();

        $cerrada = Tenant::create(['slug' => 'cerrada', 'name' => 'Cerrada', 'whatsapp_number' => '1', 'is_active' => false]);
        $cerrada->forceFill(['plan_ends_at' => now()->addDays(2)])->save();

        $this->comoOperador()->getJson('/api/platform/stats')
            ->assertOk()
            ->assertJsonPath('tiendas.por_vencer', 1);

        $lista = $this->comoOperador()->getJson('/api/platform/tenants?status=por_vencer')->assertOk();
        $this->assertSame(['cobrada'], array_column($lista->json('data'), 'slug'));
    }
}
