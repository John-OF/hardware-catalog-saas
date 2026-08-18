<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Temas prediseñados (PERS-3 / 9.3).
 *
 * Un preset no es un campo nuevo: es una combinacion de perillas que ya existian
 * (primario, acento, tono, modo, fuente, plantilla y, desde 10.1 y 10.2, forma
 * de tarjeta, radio y estilo de portada) aplicadas de una vez. Por eso el backend no cambio para esto y lo que hay que probar es que esa
 * combinacion entra entera en un solo PUT y no se lleva por delante el contenido
 * de la tienda.
 *
 * El tercer test lee los presets REALES del frontend y los valida contra la API.
 * `tsc -b` ya caza un valor fuera de las uniones (`neutral: 'forest'`), pero no
 * puede comprobar el formato del hex: un `#fff` de tres digitos compila y luego
 * le devuelve un 422 al dueño. Eso es lo que cubre este test.
 */
class ThemePresetTest extends TestCase
{
    use RefreshDatabase;

    private const PRESETS_TS = '../saas-hardware-frontend/src/utils/themePresets.ts';

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

    /** El payload que manda Configuracion al aplicar un preset y guardar. */
    private function payload(array $preset): array
    {
        return [
            'primary_color' => $preset['primary_color'],
            'theme'         => [
                'accent_color' => $preset['accent_color'],
                'neutral'      => $preset['neutral'],
                'color_mode'   => $preset['color_mode'],
                'font'         => $preset['font'],
                'layout'       => $preset['layout'],
                'radius'       => $preset['radius'],
                'card_style'   => $preset['card_style'],
                'hero_style'   => $preset['hero_style'],
            ],
        ];
    }

    public function test_una_combinacion_de_preset_se_guarda_entera(): void
    {
        $preset = [
            'primary_color' => '#8b5cf6',
            'accent_color'  => '#22d3ee',
            'neutral'       => 'plum',
            'color_mode'    => 'dark',
            'font'          => 'heading',
            'layout'        => 'grid',
            'radius'        => 'round',
            'card_style'    => 'glass',
            'hero_style'    => 'centered',
        ];

        $this->asAdmin()
            ->putJson('/api/tenant', $this->payload($preset))
            ->assertOk();

        $fresh = $this->tenant->fresh();

        $this->assertSame('#8b5cf6', $fresh->primary_color);
        $this->assertSame('#22d3ee', $fresh->theme['accent_color']);
        $this->assertSame('plum', $fresh->theme['neutral']);
        $this->assertSame('dark', $fresh->theme['color_mode']);
        $this->assertSame('heading', $fresh->theme['font']);
        $this->assertSame('grid', $fresh->theme['layout']);
        $this->assertSame('round', $fresh->theme['radius']);
        $this->assertSame('glass', $fresh->theme['card_style']);
        $this->assertSame('centered', $fresh->theme['hero_style']);
    }

    public function test_aplicar_un_preset_respeta_la_densidad_del_dueno(): void
    {
        // La densidad es preferencia de uso, no identidad de marca: el payload
        // del preset ni la menciona, asi que la que hubiera puesta sobrevive.
        $this->tenant->update(['theme' => ['density' => 'compact']]);

        $this->asAdmin()
            ->putJson('/api/tenant', $this->payload([
                'primary_color' => '#8b5cf6',
                'accent_color'  => '#22d3ee',
                'neutral'       => 'plum',
                'color_mode'    => 'dark',
                'font'          => 'heading',
                'layout'        => 'grid',
                'radius'        => 'round',
                'card_style'    => 'glass',
                'hero_style'    => 'centered',
            ]))
            ->assertOk();

        $this->assertSame('compact', $this->tenant->fresh()->theme['density']);
    }

    public function test_aplicar_un_preset_no_toca_el_contenido_de_la_tienda(): void
    {
        // Un preset cambia el aspecto, no lo que el dueño escribio. Si pisara
        // esto, aplicarlo para "probar como queda" le borraria trabajo.
        $this->tenant->update(['theme' => [
            'hero_title'    => 'Las mejores piezas',
            'hero_subtitle' => 'Stock real, entrega inmediata',
            'banner_url'    => 'https://cdn.example.com/portada.webp',
            'page_title'    => 'Mi Tienda',
            'sections'      => '[{"id":"1","type":"hero","enabled":true}]',
        ]]);

        $this->asAdmin()
            ->putJson('/api/tenant', $this->payload([
                'primary_color' => '#b45309',
                'accent_color'  => '#a16207',
                'neutral'       => 'stone',
                'color_mode'    => 'light',
                'font'          => 'serif',
                'layout'        => 'grid',
                'radius'        => 'round',
                'card_style'    => 'solid',
                'hero_style'    => 'minimal',
            ]))
            ->assertOk();

        $theme = $this->tenant->fresh()->theme;

        $this->assertSame('Las mejores piezas', $theme['hero_title']);
        $this->assertSame('Stock real, entrega inmediata', $theme['hero_subtitle']);
        $this->assertSame('https://cdn.example.com/portada.webp', $theme['banner_url']);
        $this->assertSame('Mi Tienda', $theme['page_title']);
        $this->assertSame('[{"id":"1","type":"hero","enabled":true}]', $theme['sections']);
        $this->assertSame('serif', $theme['font']);
    }

    public function test_todos_los_presets_del_frontend_pasan_la_validacion(): void
    {
        $presets = $this->presetsDelFrontend();

        foreach ($presets as $preset) {
            $this->asAdmin()
                ->putJson('/api/tenant', $this->payload($preset))
                ->assertOk("El preset '{$preset['id']}' fue rechazado por la API.");
        }
    }

    /**
     * Lee los presets de themePresets.ts.
     *
     * Es un parseo por regex de un array literal, que aguanta porque el fichero
     * es una lista de objetos planos con `clave: 'valor'`. Si algun dia deja de
     * serlo, el assert del final falla y avisa en vez de dejar pasar un fichero
     * que no se supo leer.
     */
    private function presetsDelFrontend(): array
    {
        $ruta = base_path(self::PRESETS_TS);

        if (! is_file($ruta)) {
            $this->markTestSkipped('No esta el frontend en este checkout: '.self::PRESETS_TS);
        }

        $src = file_get_contents($ruta);

        // Solo el cuerpo del array: la cabecera del fichero documenta valores
        // invalidos a proposito (`neutral: 'forest'`) y no deben colarse.
        $cuerpo = substr($src, (int) strpos($src, 'THEME_PRESETS'));

        // Fuera los comentarios de linea, que tambien mencionan nombres de campo.
        $cuerpo = preg_replace('#^\s*//.*$#m', '', $cuerpo);

        // Al aniadir una perilla a los presets hay que aniadirla tambien aqui, o
        // el guard deja de cubrirla en silencio. `radius` y `card_style`
        // entraron con 10.1; `hero_style`, con 10.2.
        $campos = [
            'id', 'primary_color', 'accent_color', 'neutral',
            'color_mode', 'font', 'layout', 'radius', 'card_style', 'hero_style',
        ];
        $trozos  = preg_split("/(?=\bid:\s*')/", $cuerpo);
        $presets = [];

        foreach ($trozos as $trozo) {
            $preset = [];

            foreach ($campos as $campo) {
                if (preg_match("/\b{$campo}:\s*'([^']*)'/", $trozo, $m)) {
                    $preset[$campo] = $m[1];
                }
            }

            if (count($preset) === count($campos)) {
                $presets[] = $preset;
            }
        }

        $this->assertGreaterThanOrEqual(
            6,
            count($presets),
            'No se pudieron leer los presets de themePresets.ts; revisa el formato del fichero.'
        );

        return $presets;
    }
}
