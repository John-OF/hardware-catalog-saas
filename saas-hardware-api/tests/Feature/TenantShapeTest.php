<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Forma de la tienda (PERS-4 / 10.1): radio de bordes, estilo de tarjeta y
 * densidad.
 *
 * Como con el tono neutral, las medidas viven en el frontend y el backend solo
 * guarda la clave dentro del JSON `theme`. Lo que hay que fijar por test es que
 * la whitelist acepte exactamente los mismos valores que ofrece el selector: el
 * 422 es el unico sintoma de que las dos listas se separaron.
 */
class TenantShapeTest extends TestCase
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

    public function test_una_tienda_existente_no_trae_forma_y_el_frontend_cae_a_la_de_siempre(): void
    {
        // Sin claves, shapeClasses() devuelve radius-soft / cards-glass /
        // density-normal, que son las medidas historicas: 10.1 no le cambia la
        // vista a ninguna tienda ya dada de alta.
        $theme = $this->tenant->fresh()->theme ?? [];

        $this->assertArrayNotHasKey('radius', $theme);
        $this->assertArrayNotHasKey('card_style', $theme);
        $this->assertArrayNotHasKey('density', $theme);
    }

    public function test_acepta_todos_los_valores_del_selector(): void
    {
        $opciones = [
            'radius'     => ['sharp', 'soft', 'round'],
            'card_style' => ['glass', 'solid', 'flat'],
            'density'    => ['compact', 'normal', 'comfortable'],
        ];

        foreach ($opciones as $campo => $valores) {
            foreach ($valores as $valor) {
                $this->asAdmin()
                    ->putJson('/api/tenant', ['theme' => [$campo => $valor]])
                    ->assertOk("Rechazado {$campo}={$valor}")
                    ->assertJsonPath("theme.{$campo}", $valor);
            }
        }
    }

    public function test_rechaza_valores_fuera_de_la_lista(): void
    {
        $this->tenant->update(['theme' => ['radius' => 'round', 'card_style' => 'flat', 'density' => 'compact']]);

        foreach (['radius' => 'pill', 'card_style' => 'neon', 'density' => 'tiny'] as $campo => $valor) {
            $this->asAdmin()
                ->putJson('/api/tenant', ['theme' => [$campo => $valor]])
                ->assertStatus(422)
                ->assertJsonValidationErrors("theme.{$campo}");
        }

        // Y nada de lo que ya estaba se movio.
        $theme = $this->tenant->fresh()->theme;
        $this->assertSame('round', $theme['radius']);
        $this->assertSame('flat', $theme['card_style']);
        $this->assertSame('compact', $theme['density']);
    }

    public function test_las_tres_perillas_son_independientes(): void
    {
        // Cualquier combinacion vale: son ejes distintos y ninguno restringe a
        // los otros. Guardar uno no puede tocar los demas.
        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => [
                'radius'     => 'sharp',
                'card_style' => 'solid',
                'density'    => 'comfortable',
            ]])
            ->assertOk();

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['card_style' => 'flat']])
            ->assertOk();

        $theme = $this->tenant->fresh()->theme;

        $this->assertSame('flat', $theme['card_style']);
        $this->assertSame('sharp', $theme['radius']);
        $this->assertSame('comfortable', $theme['density']);
    }

    public function test_el_catalogo_publico_expone_la_forma(): void
    {
        $this->tenant->update(['theme' => [
            'radius'     => 'round',
            'card_style' => 'flat',
            'density'    => 'compact',
        ]]);

        $this->getJson("/api/public/{$this->tenant->slug}")
            ->assertOk()
            ->assertJsonPath('theme.radius', 'round')
            ->assertJsonPath('theme.card_style', 'flat')
            ->assertJsonPath('theme.density', 'compact');
    }
}
