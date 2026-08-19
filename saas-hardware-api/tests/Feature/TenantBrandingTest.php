<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Elementos de marca: franja de anuncios y datos del pie (PERS-7 / 10.4).
 *
 * Dos cosas que fijar aqui y que no se parecen entre si:
 *
 * 1. Como en el resto de la personalizacion, la whitelist vive separada del
 *    catalogo del frontend, asi que se lee de branding.ts en vez de copiarse.
 *
 * 2. **Los enlaces de redes son el primer campo del theme que acaba en un
 *    href.** Todo lo demas que escribe el dueno se pinta como texto y React lo
 *    escapa solo; un `javascript:` en un enlace, no. La regla `url` de Laravel
 *    ya rechaza por su cuenta javascript:, data: y vbscript: —el test lo
 *    comprueba, para que se entere alguien si eso cambia—, pero acepta
 *    cualquier otro esquema con host, ftp:// incluido. De ahi el
 *    `url:http,https`: en un enlace a una red social no hay mas esquemas que
 *    esos dos, y la seguridad de un href no deberia depender de los detalles
 *    internos de una regla de proposito general.
 */
class TenantBrandingTest extends TestCase
{
    use RefreshDatabase;

    private const BRANDING_TS = '../saas-hardware-frontend/src/utils/branding.ts';

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

    public function test_una_tienda_existente_no_trae_franja_ni_datos(): void
    {
        // 10.4 no le cambia el pie a nadie: sin claves, el componente pinta lo
        // mismo que habia antes (paginas y copyright) y la franja ni aparece.
        $theme = $this->tenant->fresh()->theme ?? [];

        $this->assertArrayNotHasKey('announcement', $theme);
        $this->assertArrayNotHasKey('footer_address', $theme);
    }

    public function test_acepta_todos_los_colores_de_franja_que_ofrece_el_selector(): void
    {
        foreach ($this->coloresDelFrontend() as $color) {
            $this->asAdmin()
                ->putJson('/api/tenant', ['theme' => [
                    'announcement'       => 'Envio gratis en Lima',
                    'announcement_style' => $color,
                ]])
                ->assertOk("La API rechazo el color '{$color}', que si ofrece el selector.")
                ->assertJsonPath('theme.announcement_style', $color);
        }
    }

    public function test_rechaza_un_color_de_franja_fuera_de_la_lista(): void
    {
        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['announcement_style' => 'fucsia']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('theme.announcement_style');
    }

    public function test_vaciar_el_mensaje_apaga_la_franja(): void
    {
        // No hay booleano de encendido: el texto ES el interruptor. Si esto
        // dejara de guardarse en null, el dueno borraria el mensaje y la franja
        // seguiria ahi (vacia) sin forma de quitarla desde el panel.
        $this->tenant->update(['theme' => ['announcement' => 'Cyber Wow', 'announcement_style' => 'accent']]);

        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => ['announcement' => null]])
            ->assertOk();

        $theme = $this->tenant->fresh()->theme;

        $this->assertNull($theme['announcement']);
        // El color sobrevive: volver a escribir un mensaje no obliga a elegirlo
        // otra vez.
        $this->assertSame('accent', $theme['announcement_style']);
    }

    public function test_guarda_los_datos_del_pie(): void
    {
        $this->asAdmin()
            ->putJson('/api/tenant', ['theme' => [
                'footer_address' => 'Av. Garcilaso de la Vega 1234, Lima',
                'footer_hours'   => 'Lun a Sab de 10:00 a 20:00',
                'footer_tax_id'  => 'RUC 20512345678',
            ]])
            ->assertOk();

        $theme = $this->tenant->fresh()->theme;

        $this->assertSame('Av. Garcilaso de la Vega 1234, Lima', $theme['footer_address']);
        $this->assertSame('Lun a Sab de 10:00 a 20:00', $theme['footer_hours']);
        $this->assertSame('RUC 20512345678', $theme['footer_tax_id']);
    }

    public function test_acepta_todas_las_redes_que_ofrece_el_formulario(): void
    {
        // La lista se lee de branding.ts: el formulario se pinta recorriendola,
        // asi que una red nueva aparece sola en el panel y solo se le puede
        // olvidar a uno la whitelist. Este test es el que lo caza.
        foreach ($this->redesDelFrontend() as $red) {
            $this->asAdmin()
                ->putJson('/api/tenant', ['theme' => [$red => 'https://ejemplo.com/mitienda']])
                ->assertOk("La API rechazo la red '{$red}', que si ofrece el formulario.")
                ->assertJsonPath("theme.{$red}", 'https://ejemplo.com/mitienda');
        }
    }

    public function test_rechaza_un_enlace_de_red_que_no_sea_http(): void
    {
        // Los tres primeros los rechaza ya la regla `url` de Laravel; estan
        // aqui como aviso por si alguna version deja de hacerlo, porque este
        // valor se pinta en un href y un javascript: guardado seria XSS
        // almacenado contra cada visitante de la tienda.
        //
        // El ftp:// es el que hace falta el `url:http,https`: comprobado que
        // este test cae si se relaja la regla a `url` a secas.
        $malos = [
            'javascript://comment%0Aalert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            'ftp://ejemplo.com/mitienda',
        ];

        foreach ($malos as $malo) {
            $this->asAdmin()
                ->putJson('/api/tenant', ['theme' => ['footer_facebook' => $malo]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('theme.footer_facebook');
        }

        $this->assertArrayNotHasKey('footer_facebook', $this->tenant->fresh()->theme ?? []);
    }

    public function test_el_catalogo_publico_expone_la_franja_y_el_pie(): void
    {
        // La tienda publica arma los dos con esta respuesta; sin exponerlos, el
        // dueno los rellena en el panel y no aparecen.
        $this->tenant->update(['theme' => [
            'announcement'     => 'Envio gratis en Lima',
            'footer_address'   => 'Av. Garcilaso de la Vega 1234, Lima',
            'footer_instagram' => 'https://instagram.com/mitienda',
        ]]);

        $this->getJson("/api/public/{$this->tenant->slug}")
            ->assertOk()
            ->assertJsonPath('theme.announcement', 'Envio gratis en Lima')
            ->assertJsonPath('theme.footer_address', 'Av. Garcilaso de la Vega 1234, Lima')
            ->assertJsonPath('theme.footer_instagram', 'https://instagram.com/mitienda');
    }

    /** Los valores de ANNOUNCEMENT_STYLES en branding.ts. */
    private function coloresDelFrontend(): array
    {
        $cuerpo = $this->cuerpoDesde('ANNOUNCEMENT_STYLES');

        preg_match_all("/value: '([a-z]+)'/", $cuerpo, $m);

        $this->assertGreaterThanOrEqual(
            3,
            count($m[1]),
            'No se pudieron leer los colores de branding.ts; revisa el formato del fichero.'
        );

        return $m[1];
    }

    /** Las claves de SOCIAL_NETWORKS en branding.ts. */
    private function redesDelFrontend(): array
    {
        $cuerpo = $this->cuerpoDesde('SOCIAL_NETWORKS');

        preg_match_all("/key: '(footer_[a-z_]+)'/", $cuerpo, $m);

        $this->assertGreaterThanOrEqual(
            2,
            count($m[1]),
            'No se pudieron leer las redes de branding.ts; revisa el formato del fichero.'
        );

        return $m[1];
    }

    /**
     * El fichero a partir de la constante pedida.
     *
     * Parseo por regex de un array literal, como en TenantHeroStyleTest: aguanta
     * porque son listas de objetos planos, y si dejan de serlo los asserts de
     * arriba fallan y avisan en vez de dar por buena una lista vacia.
     */
    private function cuerpoDesde(string $constante): string
    {
        $ruta = base_path(self::BRANDING_TS);

        if (! is_file($ruta)) {
            $this->markTestSkipped('No esta el frontend en este checkout: '.self::BRANDING_TS);
        }

        $src = file_get_contents($ruta);

        return substr($src, (int) strpos($src, $constante));
    }
}
