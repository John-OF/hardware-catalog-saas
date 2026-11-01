<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlantillasWhatsapp;
use App\Support\Verticales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El rubro de cada tienda (MOD-19, pasos 1 y 2).
 *
 * Lo que fija, por orden de lo que costaría romperlo:
 *
 * 1. **Falla en cerrado.** Una vertical que no existe cae en la de por
 *    defecto, y un módulo sin lo que `requiere` se apaga; nunca «todo
 *    encendido».
 * 2. **Una tienda que no es de PC no enseña nada de PC**: ni armador (página,
 *    API, sitemap, vista para buscadores, mensaje de WhatsApp,
 *    complementarios), ni campos fijos de ficha técnica, ni deduce el tipo de
 *    componente de sus categorías.
 * 3. **La vertical decide qué se enseña, no qué se guarda**: cambiarla no
 *    borra ni convierte nada, y volver la deja como estaba.
 * 4. Los módulos viajan con la tienda (catálogo público por slug y por
 *    dominio, y el panel), y el frontend copia las mismas claves.
 */
class VerticalesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $hardware;
    private Tenant $general;
    private User $operador;
    private string $guardOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->guardOriginal = (string) config('auth.defaults.guard');

        $this->hardware = $this->crearTienda('tienda-pc');
        $this->general = $this->crearTienda('tienda-ropa', 'general');
        $this->operador = $this->crearUsuario(null, 'operador@plataforma.test', 'superadmin');
    }

    // ------------------------------------------------------------ la matriz

    public function test_la_config_es_coherente(): void
    {
        $modulos = config('verticales.modulos');

        $this->assertArrayHasKey(config('verticales.default'), config('verticales.verticales'), 'La vertical por defecto tiene que existir.');
        $this->assertSame('hardware', config('verticales.default'), 'Tiene que coincidir con el valor por defecto de la columna en la migración.');

        foreach ($modulos as $clave => $modulo) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $clave, 'Las claves van sin tildes: viajan al frontend.');
            $this->assertNotEmpty($modulo['label'], $clave);

            foreach ($modulo['requiere'] as $requerido) {
                $this->assertArrayHasKey($requerido, $modulos, "{$clave} requiere un módulo que no existe: {$requerido}.");
            }
        }

        foreach (config('verticales.verticales') as $clave => $vertical) {
            $this->assertNotEmpty($vertical['label'], $clave);

            foreach ($vertical['modulos'] as $modulo) {
                $this->assertArrayHasKey($modulo, $modulos, "La vertical {$clave} enciende un módulo que no existe: {$modulo}.");
            }
        }

        // Las constantes con las que el código pregunta tienen que existir en la config.
        foreach ([Verticales::TIPO_DE_COMPONENTE, Verticales::FICHA_TECNICA, Verticales::ARMADOR] as $constante) {
            $this->assertArrayHasKey($constante, $modulos);
        }
    }

    public function test_hardware_lo_tiene_todo_y_general_nada(): void
    {
        $this->assertSame(['tipo_de_componente' => true, 'ficha_tecnica' => true, 'armador' => true], Verticales::modulos('hardware'));
        $this->assertSame(['tipo_de_componente' => false, 'ficha_tecnica' => false, 'armador' => false], Verticales::modulos('general'));
    }

    public function test_una_vertical_que_no_existe_cae_en_la_de_por_defecto(): void
    {
        $this->assertSame('hardware', Verticales::clave('ferreteria'));
        $this->assertSame('hardware', Verticales::clave(null));
        $this->assertSame('hardware', Verticales::clave(''));

        // Escrita a mano en la base, sin pasar por la validación del operador.
        DB::table('tenants')->where('id', $this->general->id)->update(['vertical' => 'inventada']);
        $this->assertSame('hardware', Verticales::clave($this->general->fresh()));
    }

    public function test_un_modulo_sin_lo_que_requiere_se_apaga(): void
    {
        config()->set('verticales.verticales.a_medias', ['label' => 'A medias', 'modulos' => ['armador']]);

        $this->assertSame(
            ['tipo_de_componente' => false, 'ficha_tecnica' => false, 'armador' => false],
            Verticales::modulos('a_medias'),
            'El armador sin ficha técnica no tiene datos que comparar.',
        );

        config()->set('verticales.verticales.a_medias.modulos', ['tipo_de_componente', 'armador']);
        $this->assertFalse(Verticales::tiene('a_medias', Verticales::ARMADOR));
        $this->assertTrue(Verticales::tiene('a_medias', Verticales::TIPO_DE_COMPONENTE));
    }

    public function test_una_config_circular_no_cuelga_y_apaga(): void
    {
        config()->set('verticales.modulos.uno', ['label' => 'Uno', 'requiere' => ['dos']]);
        config()->set('verticales.modulos.dos', ['label' => 'Dos', 'requiere' => ['uno']]);
        config()->set('verticales.verticales.circular', ['label' => 'Circular', 'modulos' => ['uno', 'dos']]);

        $this->assertFalse(Verticales::tiene('circular', 'uno'));
        $this->assertFalse(Verticales::tiene('circular', 'dos'));
    }

    public function test_un_modulo_que_no_existe_no_esta(): void
    {
        $this->assertFalse(Verticales::tiene($this->hardware, 'no_existe'));
    }

    /** El frontend copia solo las claves (`utils/verticales.ts`): una errata escondería el armador. */
    public function test_las_claves_del_frontend_son_las_mismas(): void
    {
        $archivo = base_path('../saas-hardware-frontend/src/utils/verticales.ts');

        if (! is_file($archivo)) {
            $this->markTestSkipped('El frontend no está al lado (CI de la API sola).');
        }

        $contenido = (string) file_get_contents($archivo);
        $this->assertSame(1, preg_match('/export const MODULOS_DE_VERTICAL = \[(.*?)\] as const;/s', $contenido, $bloque));
        preg_match_all("/'([a-z_]+)'/", $bloque[1], $claves);

        $this->assertSame(Verticales::claves(), $claves[1], 'config/verticales.php y utils/verticales.ts tienen módulos distintos (o en otro orden).');
    }

    // ---------------------------------------------------- viajan con la tienda

    public function test_el_catalogo_publico_lleva_los_modulos_por_slug_y_por_dominio(): void
    {
        $this->getJson('/api/public/tienda-pc')->assertOk()
            ->assertJsonPath('modulos.armador', true)
            ->assertJsonPath('vertical', 'hardware');

        $this->getJson('/api/public/tienda-ropa')->assertOk()
            ->assertJsonPath('modulos', ['tipo_de_componente' => false, 'ficha_tecnica' => false, 'armador' => false]);

        $this->getJson('/api/public/resolve-domain?domain=ropa.example')->assertOk()
            ->assertJsonPath('modulos.armador', false);
    }

    public function test_lo_cacheado_antes_de_la_columna_se_lee_como_la_vertical_por_defecto(): void
    {
        // Lo que guardaba la caché antes de MOD-19: sin `vertical` ni `modulos`.
        Cache::put('tenant:tienda-pc', ['id' => $this->hardware->id, 'slug' => 'tienda-pc', 'name' => 'Tienda PC'], 300);

        $this->getJson('/api/public/tienda-pc')->assertOk()->assertJsonPath('modulos.armador', true);
    }

    public function test_el_panel_recibe_los_modulos_de_su_tienda(): void
    {
        $duenia = $this->crearUsuario($this->general, 'duenia@ropa.test', 'admin');

        $this->como($duenia, $this->general)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('tenant.vertical', 'general')
            ->assertJsonPath('tenant.modulos.ficha_tecnica', false);

        $this->como($duenia, $this->general)->getJson('/api/tenant')->assertOk()
            ->assertJsonPath('modulos.armador', false);
    }

    public function test_el_duenio_no_cambia_la_vertical_desde_configuracion(): void
    {
        $duenia = $this->crearUsuario($this->general, 'duenia@ropa.test', 'admin');

        $this->como($duenia, $this->general)->putJson('/api/tenant', ['vertical' => 'hardware', 'name' => 'Ropa'])
            ->assertOk();

        $this->assertSame('general', $this->general->fresh()->vertical);
    }

    // ------------------------------------------------------------- el armador

    public function test_sin_armador_la_api_del_armador_no_existe(): void
    {
        $this->getJson('/api/public/tienda-ropa/builder/compatibility')->assertNotFound();
        $this->getJson('/api/public/tienda-pc/builder/compatibility')->assertOk();
    }

    public function test_sin_armador_no_sale_en_el_sitemap_ni_para_los_buscadores(): void
    {
        $this->get('/tienda-pc/sitemap.xml')->assertOk()->assertSee('/tienda-pc/builder', false);
        $this->get('/tienda-ropa/sitemap.xml')->assertOk()->assertDontSee('/builder', false);

        $this->withHeaders(['User-Agent' => 'googlebot'])->get('/tienda-ropa/builder')->assertNotFound();
        $this->withHeaders(['User-Agent' => 'googlebot'])->get('/tienda-pc/builder')->assertOk();
    }

    public function test_sin_armador_no_hay_mensaje_del_armador_ni_en_el_catalogo_ni_en_el_panel(): void
    {
        $this->getJson('/api/public/tienda-pc')->assertJsonPath('plantillas_whatsapp.armado_pc', fn ($texto) => is_string($texto) && $texto !== '');
        $this->assertArrayNotHasKey('armado_pc', $this->getJson('/api/public/tienda-ropa')->json('plantillas_whatsapp'));

        $duenia = $this->crearUsuario($this->general, 'duenia@ropa.test', 'admin');
        $claves = collect($this->como($duenia, $this->general)->getJson('/api/whatsapp-templates')->assertOk()->json('plantillas'))->pluck('clave');

        $this->assertNotContains('armado_pc', $claves);
        $this->assertCount(count(PlantillasWhatsapp::catalogo()) - 1, $claves);
    }

    public function test_sin_armador_la_ficha_de_producto_no_sugiere_complementarios(): void
    {
        // Una tienda que fue de PC: sus categorías conservan el tipo. Los
        // complementarios van primero en `related_products`, delante de los de
        // su misma categoría; sin ellos, primero va otro procesador.
        $procesadores = $this->categoria($this->general, 'Procesadores', 'cpu');
        $placas = $this->categoria($this->general, 'Placas', 'motherboard');
        $cpu = $this->producto($this->general, 'Ryzen 5', $procesadores);
        $otroCpu = $this->producto($this->general, 'Ryzen 7', $procesadores);
        $placa = $this->producto($this->general, 'Placa B650', $placas);

        $this->getJson("/api/public/tienda-ropa/products/{$cpu->id}")->assertOk()
            ->assertJsonPath('related_products.0.id', $otroCpu->id);

        $this->ponerVertical($this->general, 'hardware');
        Cache::flush();

        $this->getJson("/api/public/tienda-ropa/products/{$cpu->id}")->assertOk()
            ->assertJsonPath('related_products.0.id', $placa->id);
    }

    // -------------------------------------------------- tipo y ficha técnica

    public function test_sin_tipo_de_componente_una_categoria_nueva_no_se_deduce_del_nombre(): void
    {
        $enRopa = $this->categoria($this->general, 'Memorias USB');
        $enPc = $this->categoria($this->hardware, 'Memorias RAM');

        $this->assertSame(ComponentType::Other, $enRopa->component_type);
        $this->assertSame(ComponentType::Ram, $enPc->component_type);
    }

    public function test_sin_ficha_tecnica_no_se_validan_campos_fijos_que_nadie_ve(): void
    {
        $duenia = $this->crearUsuario($this->general, 'duenia@ropa.test', 'admin');
        $procesadores = $this->categoria($this->general, 'Procesadores', 'cpu');

        // En una tienda de PC, «mucho» no es un TDP y se rechaza.
        $admin = $this->crearUsuario($this->hardware, 'admin@pc.test', 'admin');
        $this->como($admin, $this->hardware)->post('/api/products', [
            'name' => 'Ryzen', 'price' => '100', 'stock' => '1',
            'category_id' => $this->categoria($this->hardware, 'CPU', 'cpu')->id,
            'specs' => json_encode(['tdp' => 'mucho']),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('specs.tdp');

        // Sin ficha técnica es una spec libre más y se guarda tal cual.
        $this->como($duenia, $this->general)->post('/api/products', [
            'name' => 'Ryzen', 'price' => '100', 'stock' => '1',
            'category_id' => $procesadores->id,
            'specs' => json_encode(['tdp' => 'mucho']),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['tdp' => 'mucho'], Product::withoutTenant()->where('tenant_id', $this->general->id)->first()->specs);
    }

    // ----------------------------------------------------- el operador la cambia

    public function test_el_operador_cambia_la_vertical_y_queda_en_su_bitacora(): void
    {
        $this->getJson('/api/public/tienda-pc')->assertJsonPath('modulos.armador', true);

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->hardware->id}", ['vertical' => 'general'])
            ->assertOk()
            ->assertJsonPath('vertical', 'general')
            ->assertJsonPath('modulos.armador', false);

        $linea = ActivityLog::where('action', ActivityLog::TIENDA_VERTICAL)->firstOrFail();
        $this->assertSame('hardware', $linea->context['antes']);
        $this->assertSame('general', $linea->context['despues']);
        $this->assertStringContainsString('Componentes de PC → Catálogo general', $linea->description);

        // La caché pública se olvida: el catálogo no sigue enseñando el armador cinco minutos.
        $this->getJson('/api/public/tienda-pc')->assertJsonPath('modulos.armador', false);

        // Volver a poner la misma no anota nada.
        $this->comoOperador()->putJson("/api/platform/tenants/{$this->hardware->id}", ['vertical' => 'general'])->assertOk();
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::TIENDA_VERTICAL)->count());
    }

    public function test_el_operador_no_puede_poner_una_vertical_que_no_existe(): void
    {
        $this->comoOperador()->putJson("/api/platform/tenants/{$this->hardware->id}", ['vertical' => 'ferreteria'])
            ->assertStatus(422)->assertJsonValidationErrors('vertical');

        $this->assertSame('hardware', $this->hardware->fresh()->vertical);
    }

    public function test_la_ficha_del_operador_trae_las_verticales_con_su_nombre(): void
    {
        $this->comoOperador()->getJson("/api/platform/tenants/{$this->general->id}")->assertOk()
            ->assertJsonPath('verticales', ['hardware' => 'Componentes de PC', 'general' => 'Catálogo general'])
            ->assertJsonPath('tenant.vertical', 'general');
    }

    public function test_cambiar_de_vertical_no_borra_ni_convierte_nada(): void
    {
        $procesadores = $this->categoria($this->hardware, 'Procesadores', 'cpu');
        $this->producto($this->hardware, 'Ryzen 5', $procesadores, ['socket' => 'AM5']);

        $antes = [
            DB::table('categories')->where('tenant_id', $this->hardware->id)->get()->toArray(),
            DB::table('products')->where('tenant_id', $this->hardware->id)->get(['id', 'specs'])->toArray(),
        ];

        $this->comoOperador()->putJson("/api/platform/tenants/{$this->hardware->id}", ['vertical' => 'general'])->assertOk();
        $this->comoOperador()->putJson("/api/platform/tenants/{$this->hardware->id}", ['vertical' => 'hardware'])->assertOk();

        $this->assertEquals($antes, [
            DB::table('categories')->where('tenant_id', $this->hardware->id)->get()->toArray(),
            DB::table('products')->where('tenant_id', $this->hardware->id)->get(['id', 'specs'])->toArray(),
        ]);
    }

    public function test_una_tienda_nueva_nace_de_hardware(): void
    {
        $nueva = Tenant::create(['slug' => 'nueva', 'name' => 'Nueva', 'whatsapp_number' => '51999999999']);

        $this->assertSame('hardware', $nueva->fresh()->vertical);
        $this->assertTrue($nueva->modulos['armador']);
    }

    // ---------------------------------------------------------------- ayudas

    private function crearTienda(string $slug, ?string $vertical = null): Tenant
    {
        $tienda = Tenant::create([
            'slug' => $slug, 'name' => 'Tienda '.$slug, 'whatsapp_number' => '51999999999',
            'is_active' => true, 'is_published' => true, 'plan' => 'enterprise',
        ]);

        if ($vertical) {
            $this->ponerVertical($tienda, $vertical);
        }

        if ($slug === 'tienda-ropa') {
            $tienda->forceFill(['custom_domain' => 'ropa.example', 'custom_domain_verified_at' => now()])->save();
        }

        return $tienda->fresh();
    }

    private function ponerVertical(Tenant $tienda, string $vertical): void
    {
        DB::table('tenants')->where('id', $tienda->id)->update(['vertical' => $vertical]);
    }

    private function crearUsuario(?Tenant $tienda, string $email, string $rol): User
    {
        $usuario = new User(['name' => 'Persona', 'email' => $email, 'password' => 'secret1234', 'role' => $rol, 'is_active' => true]);
        $usuario->tenant_id = $tienda?->id;
        $usuario->save();

        return $usuario;
    }

    private function categoria(Tenant $tienda, string $nombre, ?string $tipo = null): Category
    {
        $categoria = new Category(['name' => $nombre, 'component_type' => $tipo, 'is_active' => true]);
        $categoria->tenant_id = $tienda->id;
        $categoria->save();

        return $categoria->fresh();
    }

    private function producto(Tenant $tienda, string $nombre, Category $categoria, array $specs = []): Product
    {
        $producto = new Product([
            'name' => $nombre, 'price' => 100, 'stock' => 5, 'is_active' => true, 'status' => 'published',
            'category_id' => $categoria->id, 'specs' => $specs,
        ]);
        $producto->tenant_id = $tienda->id;
        $producto->save();

        return $producto;
    }

    private function como(User $usuario, Tenant $tienda): self
    {
        $this->desdeCero();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant'      => $tienda->slug,
        ]);
    }

    private function comoOperador(): self
    {
        $this->desdeCero();

        return $this->withHeader('Authorization', 'Bearer '.$this->operador->createToken('test', ['superadmin'])->plainTextToken);
    }

    /** Ver `TeamUsersTest::desdeCero()`: el guard recuerda al usuario de la petición anterior. */
    private function desdeCero(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse($this->guardOriginal);
    }
}
