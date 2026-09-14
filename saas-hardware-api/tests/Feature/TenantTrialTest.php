<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\PlanGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Período de prueba con cierre automático (FUN-16).
 *
 * Desde que se dejó de ofrecer un plan gratis permanente, toda tienda nueva
 * nace con `trial_ends_at` puesta: mientras esa fecha no pasa, el plan
 * EFECTIVO —el que aplican `PlanGate` y la ficha de plataforma— es 'trial'
 * (límites de Pro), sin importar lo que diga `tenants.plan` (que se queda en
 * el plan por defecto hasta que alguien elige uno de verdad).
 */
class TenantTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_registrarse_deja_una_prueba_de_siete_dias(): void
    {
        $this->postJson('/api/auth/register', [
            'store_name'            => 'Tienda Nueva',
            'slug'                  => 'tienda-nueva',
            'whatsapp'              => '51999999999',
            'name'                  => 'Dueña',
            'email'                 => 'duena@tienda-nueva.com',
            'password'              => 'Contrasenia-larga-1',
            'password_confirmation' => 'Contrasenia-larga-1',
        ])->assertCreated();

        $tenant = Tenant::where('slug', 'tienda-nueva')->firstOrFail();

        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertEqualsWithDelta(
            now()->addDays(Tenant::DIAS_DE_PRUEBA)->timestamp,
            $tenant->trial_ends_at->timestamp,
            5, // segundos de margen: la petición tarda un poco en correr.
        );
        $this->assertTrue($tenant->enPrueba());
    }

    public function test_mientras_dura_la_prueba_el_plan_efectivo_es_trial(): void
    {
        $tenant = Tenant::create([
            'slug' => 'en-prueba', 'name' => 'En Prueba',
            'whatsapp_number' => '51999999999', 'is_active' => true,
            // 'plan' se queda en el default ('free'/Básico) a propósito: la
            // prueba no depende de lo que diga esa columna.
        ]);
        $tenant->forceFill(['trial_ends_at' => now()->addDays(5)])->save();
        $tenant->makeCurrent();

        $this->assertSame('trial', PlanGate::plan());
        // Límites de 'trial' (los mismos que Pro), no los del plan Básico.
        $this->assertSame(500, PlanGate::limit('products'));
        $this->assertTrue(PlanGate::allows('custom_domain'));

        Tenant::forgetCurrent();
    }

    public function test_con_la_prueba_vencida_cae_al_plan_de_verdad_de_la_tienda(): void
    {
        $tenant = Tenant::create([
            'slug' => 'prueba-vencida', 'name' => 'Prueba Vencida',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);
        // Vencida pero todavía no la cerró el comando: `enPrueba()` no debe
        // regalarle tiempo de más por un cron que todavía no corrió.
        $tenant->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $tenant->makeCurrent();

        $this->assertFalse($tenant->fresh()->enPrueba());
        $this->assertSame('free', PlanGate::plan());
        $this->assertSame(20, PlanGate::limit('products'));

        Tenant::forgetCurrent();
    }

    public function test_una_tienda_sin_trial_ends_at_no_esta_en_prueba(): void
    {
        // Las tiendas de antes de FUN-16 (o las que crea el propio operador)
        // no tienen esta columna puesta: deben comportarse exactamente igual
        // que antes de que existiera el período de prueba.
        $tenant = Tenant::create([
            'slug' => 'tienda-vieja', 'name' => 'Tienda Vieja',
            'whatsapp_number' => '51999999999', 'is_active' => true, 'plan' => 'pro',
        ]);

        $this->assertFalse($tenant->enPrueba());

        $tenant->makeCurrent();
        $this->assertSame('pro', PlanGate::plan());
        Tenant::forgetCurrent();
    }

    /**
     * Elegir un plan de verdad tiene que sacar a la tienda de la prueba: si
     * no, el comando de cierre automático podría suspenderla de todos modos
     * en cuanto llegara la fecha, aunque ya estuviera pagando.
     */
    public function test_asignar_un_plan_desde_plataforma_termina_la_prueba(): void
    {
        $tenant = Tenant::create([
            'slug' => 'recien-convertida', 'name' => 'Recién Convertida',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);
        $tenant->forceFill(['trial_ends_at' => now()->addDays(3)])->save();

        $superAdmin = new User([
            'name' => 'Operador', 'email' => 'operador@plataforma.com',
            'password' => 'password123', 'role' => 'superadmin', 'is_active' => true,
        ]);
        $superAdmin->save();
        $token = $superAdmin->createToken('test', ['superadmin'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/platform/tenants/{$tenant->id}", ['plan' => 'pro'])
            ->assertOk();

        $tenant->refresh();
        $this->assertNull($tenant->trial_ends_at);
        $this->assertFalse($tenant->enPrueba());
        $this->assertSame('pro', $tenant->plan);
    }

    /**
     * Y lo mismo pero sin cambiar el plan (por ejemplo, sólo suspender o
     * reactivar): eso no debe tocar la prueba en curso.
     */
    public function test_suspender_sin_tocar_el_plan_no_afecta_la_prueba(): void
    {
        $tenant = Tenant::create([
            'slug' => 'suspendida-en-prueba', 'name' => 'Suspendida En Prueba',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);
        $vencePrueba = now()->addDays(4);
        $tenant->forceFill(['trial_ends_at' => $vencePrueba])->save();

        $superAdmin = new User([
            'name' => 'Operador', 'email' => 'operador@plataforma.com',
            'password' => 'password123', 'role' => 'superadmin', 'is_active' => true,
        ]);
        $superAdmin->save();
        $token = $superAdmin->createToken('test', ['superadmin'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/platform/tenants/{$tenant->id}", ['is_active' => false])
            ->assertOk();

        $this->assertNotNull($tenant->fresh()->trial_ends_at);
    }
}
