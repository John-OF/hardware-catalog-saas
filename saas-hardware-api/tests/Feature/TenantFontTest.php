<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Tipografia de la tienda (PERS-6 / 10.3).
 *
 * Hasta 10.3 habia una sola clave, `font`, que era una pareja cerrada: elegir
 * "serif" ponia Merriweather en los titulos Y en el texto. Ahora son dos
 * familias sueltas, `font_heading` y `font_body`.
 *
 * Lo que hay que fijar por test es, ademas de la whitelist —que como en el tono
 * y la forma vive separada del catalogo del frontend—, **la compatibilidad
 * hacia atras**: una tienda dada de alta antes de 10.3 solo tiene `font`, y de
 * ahi tiene que seguir saliendo su letra. Es lo unico de esta funcionalidad que
 * puede romperle el aspecto a una tienda que ya estaba funcionando.
 */
class TenantFontTest extends TestCase
{
    use RefreshDatabase;

    private const FONTS_TS = '../saas-hardware-frontend/src/utils/fonts.ts';

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

    public function test_acepta_todas_las_familias_del_catalogo_en_los_dos_huecos(): void
    {
        // La lista se lee de fonts.ts en vez de copiarse aqui: copiada, anadir
        // una familia al selector y olvidarse de la whitelist dejaria este test
        // en verde y al dueno con un 422 al guardar.
        foreach ($this->familiasDelFrontend() as $familia) {
            $this->asAdmin()
                ->putJson('/api/tenant', ['theme' => [
                    'font_heading' => $familia,
                    'font_body'    => $familia,
                ]])
                ->assertOk("La API rechazo la familia '{$familia}', que si ofrece el selector.")
                ->assertJsonPath('theme.font_heading', $familia)
                ->assertJsonPath('theme.font_body', $familia);
        }
    }

    public function test_los_titulos_y_el_texto_se_guardan_por_separado(): void
    {
        // Es toda la gracia de 10.3: la pareja vieja no sabia decir "Playfair
        // arriba y Lora abajo", que es la combinacion que hace que dos tiendas
        // no se lean igual.
        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => [
                'font_heading' => 'playfair',
                'font_body'    => 'lora',
            ]])
            ->assertOk();

        $theme = $this->tenant->fresh()->theme;

        $this->assertSame('playfair', $theme['font_heading']);
        $this->assertSame('lora', $theme['font_body']);
    }

    public function test_rechaza_una_familia_fuera_del_catalogo(): void
    {
        $this->tenant->update(['theme' => ['font_heading' => 'playfair']]);

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['font_heading' => 'comic-sans']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('theme.font_heading');

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['font_body' => 'comic-sans']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('theme.font_body');

        $this->assertSame('playfair', $this->tenant->fresh()->theme['font_heading']);
    }

    public function test_una_tienda_anterior_a_10_3_conserva_su_pareja_vieja(): void
    {
        // El panel ya no manda `font`, pero el backend hace merge del theme: la
        // clave sigue ahi y es de donde resolveFonts saca las dos familias
        // mientras el dueno no entre a Configuracion. Si un PUT cualquiera se la
        // llevara por delante, la tienda pasaria de Merriweather a Inter sin que
        // nadie hubiera tocado la tipografia.
        $this->tenant->update(['theme' => ['font' => 'serif', 'layout' => 'grid']]);

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['color_mode' => 'light']])
            ->assertOk();

        $theme = $this->tenant->fresh()->theme;

        $this->assertSame('serif', $theme['font']);
        $this->assertArrayNotHasKey('font_heading', $theme);
        $this->assertArrayNotHasKey('font_body', $theme);
    }

    public function test_la_pareja_vieja_sigue_aceptandose(): void
    {
        // No es decoracion: mientras exista una sola tienda guardada con `font`,
        // quitarla de la whitelist convierte su siguiente "Guardar" en un 422.
        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['font' => 'mono']])
            ->assertOk()
            ->assertJsonPath('theme.font', 'mono');
    }

    public function test_el_catalogo_publico_expone_las_dos_familias(): void
    {
        // Sin esto la tienda publica no sabria que letra pedirle a Google: la
        // carga de fuentes se decide con esta respuesta.
        $this->tenant->update(['theme' => [
            'font_heading' => 'space-grotesk',
            'font_body'    => 'inter',
        ]]);

        $this->getJson("/api/public/{$this->tenant->slug}")
            ->assertOk()
            ->assertJsonPath('theme.font_heading', 'space-grotesk')
            ->assertJsonPath('theme.font_body', 'inter');
    }

    /**
     * Lee los valores de FONT_FAMILIES en fonts.ts.
     *
     * Parseo por regex de un array literal, como el de ThemePresetTest y el de
     * TenantHeroStyleTest: aguanta porque el fichero es una lista de objetos
     * planos. Si deja de serlo, el assert de abajo falla y avisa en vez de dar
     * por buena una lista vacia.
     */
    private function familiasDelFrontend(): array
    {
        $ruta = base_path(self::FONTS_TS);

        if (! is_file($ruta)) {
            $this->markTestSkipped('No esta el frontend en este checkout: '.self::FONTS_TS);
        }

        // Solo el cuerpo del array: la cabecera documenta nombres de campo y no
        // debe colarse.
        $src    = file_get_contents($ruta);
        $cuerpo = substr($src, (int) strpos($src, 'FONT_FAMILIES'));

        preg_match_all("/value: '([a-z-]+)'/", $cuerpo, $m);

        $this->assertGreaterThanOrEqual(
            6,
            count($m[1]),
            'No se pudieron leer las familias de fonts.ts; revisa el formato del fichero.'
        );

        return $m[1];
    }
}
