<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Tono neutral por tienda (PERS-2 / 9.2).
 *
 * Los colores del tono viven en el frontend (index.css + src/utils/neutrals.ts);
 * el backend solo guarda la clave dentro del JSON `theme`. Por eso el test que
 * importa es el del 422: es lo que avisa si la lista de tonos del selector y la
 * whitelist de TenantController se desincronizan, que es el unico modo de que
 * esto se rompa en silencio.
 */
class TenantNeutralTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-a.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    private function asAdmin(): static
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ]);
    }

    public function test_una_tienda_existente_no_trae_tono_y_el_frontend_cae_al_de_siempre(): void
    {
        // Sin clave `neutral`, neutralClass() del frontend devuelve `neutral-slate`,
        // que es la paleta historica: la migracion a 9.2 no le cambia la vista a
        // ninguna tienda ya dada de alta.
        $this->assertArrayNotHasKey('neutral', $this->tenant->fresh()->theme ?? []);
    }

    public function test_el_dueno_puede_elegir_un_tono(): void
    {
        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['neutral' => 'stone']])
            ->assertOk()
            ->assertJsonPath('theme.neutral', 'stone');

        $this->assertSame('stone', $this->tenant->fresh()->theme['neutral']);
    }

    public function test_acepta_los_cinco_tonos_del_selector(): void
    {
        foreach (['slate', 'zinc', 'stone', 'navy', 'plum'] as $tono) {
            $this->asAdmin()
                ->putJson('/api/tenant', ['theme' => ['neutral' => $tono]])
                ->assertOk()
                ->assertJsonPath('theme.neutral', $tono);
        }
    }

    public function test_rechaza_un_tono_fuera_de_la_lista(): void
    {
        $this->tenant->update(['theme' => ['neutral' => 'navy']]);

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['neutral' => 'chartreuse']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('theme.neutral');

        $this->assertSame('navy', $this->tenant->fresh()->theme['neutral']);
    }

    public function test_guardar_el_tono_no_pisa_el_resto_del_theme(): void
    {
        $this->tenant->update(['theme' => [
            'hero_title' => 'Las mejores piezas',
            'color_mode' => 'light',
            'font'       => 'serif',
        ]]);

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['neutral' => 'plum']])
            ->assertOk();

        $theme = $this->tenant->fresh()->theme;

        $this->assertSame('plum', $theme['neutral']);
        $this->assertSame('Las mejores piezas', $theme['hero_title']);
        $this->assertSame('serif', $theme['font']);

        // El tono y el modo son ortogonales: cada uno elige una mitad de la
        // paleta (`.neutral-plum` vs `.neutral-plum.light-mode`), asi que
        // guardar uno no puede tocar el otro.
        $this->assertSame('light', $theme['color_mode']);
    }

    public function test_el_catalogo_publico_expone_el_tono(): void
    {
        $this->tenant->update(['theme' => ['neutral' => 'zinc']]);

        $this->getJson("/api/public/{$this->tenant->slug}")
            ->assertOk()
            ->assertJsonPath('theme.neutral', 'zinc');
    }
}
