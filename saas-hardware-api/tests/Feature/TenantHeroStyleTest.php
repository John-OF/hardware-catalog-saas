<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Estilos de portada (PERS-5 / 10.2).
 *
 * Como con el tono y la forma, las medidas viven en el frontend y el backend
 * solo guarda la clave dentro del JSON `theme`. Lo que hay que fijar por test es
 * que la whitelist acepte exactamente los mismos valores que ofrece el selector
 * —el 422 es el unico sintoma de que las dos listas se separaron— y que cambiar
 * de estilo no se lleve por delante la imagen de portada, que es la trampa
 * propia de esta funcionalidad: hay estilos que no la pintan.
 */
class TenantHeroStyleTest extends TestCase
{
    use RefreshDatabase;

    private const HERO_TS = '../saas-hardware-frontend/src/utils/hero.ts';

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

    public function test_una_tienda_existente_no_trae_estilo_y_el_frontend_cae_al_clasico(): void
    {
        // Sin clave, heroStyleOf() devuelve `classic`, que es el hero que ya
        // tenian todas: 10.2 no le cambia la portada a nadie hasta que la toca.
        $theme = $this->tenant->fresh()->theme ?? [];

        $this->assertArrayNotHasKey('hero_style', $theme);
    }

    public function test_acepta_todos_los_estilos_que_ofrece_el_selector(): void
    {
        // La lista se lee de hero.ts en vez de copiarse aqui: copiada, anadir un
        // estilo al selector y olvidarse de la whitelist dejaria este test en
        // verde y al dueno con un 422 al guardar.
        $estilos = $this->estilosDelFrontend();

        foreach ($estilos as $estilo) {
            $this->asAdmin()
                ->putJson('/api/tenant', ['theme' => ['hero_style' => $estilo]])
                ->assertOk("La API rechazo el estilo '{$estilo}', que si ofrece el selector.")
                ->assertJsonPath('theme.hero_style', $estilo);
        }
    }

    public function test_rechaza_un_estilo_fuera_de_la_lista(): void
    {
        $this->tenant->update(['theme' => ['hero_style' => 'split']]);

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['hero_style' => 'carrusel']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('theme.hero_style');

        $this->assertSame('split', $this->tenant->fresh()->theme['hero_style']);
    }

    public function test_cambiar_de_estilo_no_borra_la_imagen_de_portada(): void
    {
        // `minimal` no pinta el banner, pero no lo tira: si al volver a
        // `classic` la imagen ya no estuviera, probar un estilo le costaria al
        // dueno subirla otra vez.
        $this->tenant->update(['theme' => [
            'banner_url'    => 'https://cdn.example.com/portada.webp',
            'hero_title'    => 'Las mejores piezas',
            'hero_subtitle' => 'Stock real',
        ]]);

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['hero_style' => 'minimal']])
            ->assertOk();

        $theme = $this->tenant->fresh()->theme;

        $this->assertSame('minimal', $theme['hero_style']);
        $this->assertSame('https://cdn.example.com/portada.webp', $theme['banner_url']);
        $this->assertSame('Las mejores piezas', $theme['hero_title']);
        $this->assertSame('Stock real', $theme['hero_subtitle']);
    }

    public function test_el_catalogo_publico_expone_el_estilo(): void
    {
        // Sin esto el catalogo no sabria que disposicion pintar: la portada la
        // arma el frontend publico a partir de esta respuesta.
        $this->tenant->update(['theme' => ['hero_style' => 'centered']]);

        $this->getJson("/api/public/{$this->tenant->slug}")
            ->assertOk()
            ->assertJsonPath('theme.hero_style', 'centered');
    }

    /**
     * Lee los valores de HERO_STYLES en hero.ts.
     *
     * Parseo por regex de un array literal, como el de ThemePresetTest: aguanta
     * porque el fichero es una lista de objetos planos. Si deja de serlo, el
     * assert de abajo falla y avisa en vez de dar por buena una lista vacia.
     */
    private function estilosDelFrontend(): array
    {
        $ruta = base_path(self::HERO_TS);

        if (! is_file($ruta)) {
            $this->markTestSkipped('No esta el frontend en este checkout: '.self::HERO_TS);
        }

        // Solo el cuerpo del array: la cabecera documenta nombres de campo y no
        // debe colarse.
        $src    = file_get_contents($ruta);
        $cuerpo = substr($src, (int) strpos($src, 'HERO_STYLES'));

        preg_match_all("/value: '([a-z]+)'/", $cuerpo, $m);

        $this->assertGreaterThanOrEqual(
            4,
            count($m[1]),
            'No se pudieron leer los estilos de hero.ts; revisa el formato del fichero.'
        );

        return $m[1];
    }
}
