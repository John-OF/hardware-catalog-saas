<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fija las dos mitades de AUD-2: el catálogo público tiene techo, y verlo no
 * escribe en la base una vez por visita.
 *
 * Son dos problemas distintos que se agravaban entre sí. Sin límite, un script
 * podía repetir `GET /products` sin coste; y como cada una de esas peticiones
 * hacía un `increment('views_count')` sobre la MISMA fila de `tenants`, la
 * ráfaga se convertía en contención de bloqueo sobre una fila caliente. La
 * respuesta salía de caché, pero el UPDATE se hacía igual.
 */
class PublicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-limites',
            'name'            => 'Tienda Limites',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $product = new Product([
            'name'      => 'SSD 1TB',
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        $this->product = $product;
    }

    /**
     * La auditoría comprobó 70 peticiones seguidas con 70 respuestas 200. El
     * límite es 120/min, así que hacen falta 121 para verlo caer.
     */
    public function test_una_rafaga_al_catalogo_acaba_en_429(): void
    {
        $url = "/api/public/{$this->tenant->slug}/products";

        for ($i = 0; $i < 120; $i++) {
            $this->getJson($url)->assertStatus(200);
        }

        $this->getJson($url)->assertStatus(429);
    }

    /**
     * El límite es del grupo entero, no de la ruta: antes solo lo llevaban las
     * rutas que escriben, y las de lectura eran las repetibles sin coste.
     */
    public function test_el_limite_cubre_tambien_la_portada_y_las_facetas(): void
    {
        // Las peticiones de las tres rutas suman contra el mismo contador,
        // porque la clave del limitador es la IP y no la ruta.
        for ($i = 0; $i < 40; $i++) {
            $this->getJson("/api/public/{$this->tenant->slug}")->assertStatus(200);
            $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertStatus(200);
            $this->getJson("/api/public/{$this->tenant->slug}/categories")->assertStatus(200);
        }

        $this->getJson("/api/public/{$this->tenant->slug}/facets")->assertStatus(429);
    }

    /**
     * Lo que pedía la aceptación: ver el catálogo no genera escrituras en
     * `tenants`. Una sí —la que abre la ventana—, no una por visita.
     */
    public function test_ver_el_catalogo_no_escribe_una_vez_por_visita(): void
    {
        $updates = $this->contarUpdatesA('tenants');

        for ($i = 0; $i < 10; $i++) {
            $this->getJson("/api/public/{$this->tenant->slug}/products")->assertStatus(200);
        }

        $this->assertSame(1, $updates(), '10 visitas deberian dejar 1 UPDATE, no 10.');
        $this->assertSame(1, $this->tenant->fresh()->views_count);

        // Las otras 9 no se han perdido: siguen en el contador, esperando a que
        // se abra la ventana siguiente.
        $this->assertSame(9, (int) Cache::get("views:tenants:{$this->tenant->id}"));
    }

    /**
     * Y cuando la ventana vence, lo acumulado se vuelca entero: el contador
     * amortigua las escrituras, no descarta visitas.
     */
    public function test_al_vencer_la_ventana_se_vuelca_todo_lo_acumulado(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getJson("/api/public/{$this->tenant->slug}/products")->assertStatus(200);
        }

        // Dejar vencer la ventana de verdad serían 5 minutos de test; tirar la
        // clave de la puerta es exactamente lo mismo que hace su TTL.
        Cache::forget("views:tenants:{$this->tenant->id}:puerta");

        $this->getJson("/api/public/{$this->tenant->slug}/products")->assertStatus(200);

        // 11 visitas contadas con 2 escrituras.
        $this->assertSame(11, $this->tenant->fresh()->views_count);
        $this->assertSame(0, (int) Cache::get("views:tenants:{$this->tenant->id}", 0));
    }

    /**
     * Misma amortiguación en la ficha de producto, donde la fila caliente es la
     * del producto de moda.
     */
    public function test_las_visitas_a_un_producto_tambien_se_acumulan(): void
    {
        $updates = $this->contarUpdatesA('products');

        for ($i = 0; $i < 8; $i++) {
            $this->getJson("/api/public/{$this->tenant->slug}/products/{$this->product->id}")
                ->assertStatus(200);
        }

        $this->assertSame(1, $updates());
        $this->assertSame(1, $this->product->fresh()->views_count);
        $this->assertSame(7, (int) Cache::get("views:products:{$this->product->id}"));
    }

    /**
     * Cuenta los UPDATE que llegan a una tabla desde este punto.
     *
     * @return callable(): int
     */
    private function contarUpdatesA(string $tabla): callable
    {
        $updates = 0;

        DB::listen(function ($query) use (&$updates, $tabla) {
            $sql = strtolower($query->sql);

            if (str_starts_with(trim($sql), 'update') && str_contains($sql, $tabla)) {
                $updates++;
            }
        });

        // Closure larga y no arrow function: `fn () => $updates` captura por
        // VALOR en el momento de crearse, asi que devolveria 0 para siempre.
        return function () use (&$updates): int {
            return $updates;
        };
    }
}
