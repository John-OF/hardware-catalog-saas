<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las respuestas no publican detalle interno (AUD-8, AUD-9).
 *
 * Dos fugas distintas con la misma forma: devolver más de lo que hace falta
 * porque nadie decidió qué debía salir.
 */
class ResponseHygieneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-higiene',
            'name'            => 'Tienda Higiene',
            'whatsapp_number' => '51999999999',
            'custom_domain'   => 'mitienda.example',
            'plan'            => 'pro',
            'is_active'       => true,
        ]);
    }

    // ------------------------------------------------------------------ AUD-9

    /**
     * `resolve-domain` no lleva autenticación y devolvía la fila entera de la
     * tienda: un competidor veía qué plan tienes contratado y cuánto tráfico
     * mueves.
     */
    public function test_resolve_domain_no_publica_el_plan_ni_las_visitas(): void
    {
        $this->tenant->increment('views_count', 4200);

        $respuesta = $this->getJson('/api/public/resolve-domain?domain=mitienda.example')
            ->assertStatus(200);

        foreach (['plan', 'views_count', 'is_active', 'custom_domain', 'created_at', 'updated_at'] as $columna) {
            $respuesta->assertJsonMissingPath($columna);
        }
    }

    /**
     * Y lo que el frontend sí necesita sigue estando: las dos rutas alimentan el
     * mismo objeto `tenant` del catálogo, así que recortar de más habría dejado
     * las tiendas con dominio propio sin marca ni moneda.
     */
    public function test_resolve_domain_devuelve_lo_mismo_que_el_catalogo(): void
    {
        $porDominio = $this->getJson('/api/public/resolve-domain?domain=mitienda.example')
            ->assertStatus(200)
            ->json();

        $porSlug = $this->getJson("/api/public/{$this->tenant->slug}")
            ->assertStatus(200)
            ->json();

        $this->assertSame(array_keys($porSlug), array_keys($porDominio));
        $this->assertSame($this->tenant->slug, $porDominio['slug']);
        $this->assertArrayHasKey('theme', $porDominio);
        $this->assertArrayHasKey('currency', $porDominio);
    }

    // ------------------------------------------------------------------ AUD-8

    /**
     * El import devolvía `$e->getMessage()` al navegador, o sea SQL, nombres de
     * columnas y rutas del servidor, **hiciera lo que hiciera `APP_DEBUG`**.
     *
     * Para provocar el fallo se tira la tabla de categorías, que es lo que el
     * import consulta por cada fila con categoría. Sirve cualquier avería: lo
     * que se prueba es qué se cuenta cuando algo se rompe, no qué se rompió.
     */
    public function test_un_fallo_del_import_no_devuelve_el_error_interno(): void
    {
        $admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $admin->tenant_id = $this->tenant->id;
        $admin->save();

        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        Schema::drop('categories');

        $csv = "nombre;marca;precio;stock;categoria\n"
            ."Producto;Marca;100;5;Procesadores\n";

        $respuesta = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant'      => $this->tenant->slug,
        ])->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $csv),
        ]);

        $respuesta->assertStatus(500);
        $respuesta->assertJsonMissingPath('error');
        $respuesta->assertJsonPath('message', 'Ocurrió un error inesperado al procesar el archivo.');

        // Ni por asomo el SQL en ningun rincon de la respuesta.
        $this->assertStringNotContainsStringIgnoringCase('select', $respuesta->getContent());
        $this->assertStringNotContainsStringIgnoringCase('categories', $respuesta->getContent());
    }

    public function test_un_import_correcto_sigue_funcionando(): void
    {
        $admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio2@tienda.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $admin->tenant_id = $this->tenant->id;
        $admin->save();

        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        $csv = "nombre;marca;precio;stock\n"
            ."Producto bueno;Marca;100;5\n";

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant'      => $this->tenant->slug,
        ])->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $csv),
        ])->assertOk();

        $this->assertSame(1, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
    }
}
