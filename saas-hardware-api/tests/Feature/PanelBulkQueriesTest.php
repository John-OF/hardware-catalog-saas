<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Consultas en bucle del panel (11.15 — AUD-22, AUD-23).
 *
 * Dos sitios donde el panel hacia una consulta por producto: `reorder`, que es
 * la peor porque se dispara en cada arrastre del raton, y el borrado en lote,
 * que cargaba la galeria producto a producto.
 *
 * Los tests cuentan consultas, no milisegundos: lo que hay que fijar es que el
 * coste deje de crecer con el numero de productos, y eso un cronometro sobre
 * SQLite en memoria no lo demuestra.
 */
class PanelBulkQueriesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-panel',
            'name'            => 'Tienda Panel',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-panel.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    private function producto(string $nombre, ?Tenant $tenant = null): Product
    {
        $producto = new Product([
            'name'      => $nombre,
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $producto->tenant_id = ($tenant ?? $this->tenant)->id;
        $producto->save();

        return $producto;
    }

    private function panel(): self
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ]);
    }

    /**
     * Cuenta las consultas que casan con un patron durante la llamada.
     *
     * @return array{0: mixed, 1: int}
     */
    private function contando(string $patron, callable $accion): array
    {
        $consultas = 0;

        DB::listen(function ($query) use ($patron, &$consultas) {
            if (preg_match($patron, $query->sql)) {
                $consultas++;
            }
        });

        $resultado = $accion();

        DB::flushQueryLog();

        return [$resultado, $consultas];
    }

    /*
    |--------------------------------------------------------------------------
    | AUD-22 — reorder
    |--------------------------------------------------------------------------
    */

    public function test_reordenar_es_un_solo_update_aunque_haya_muchos_productos(): void
    {
        $productos = collect(range(1, 12))->map(fn ($i) => $this->producto("Producto {$i}"));

        [$respuesta, $updates] = $this->contando(
            '/^update .*products/i',
            fn () => $this->panel()->postJson('/api/products/reorder', [
                'ids' => $productos->pluck('id')->reverse()->values()->all(),
            ])
        );

        $respuesta->assertOk();
        $this->assertSame(1, $updates, "Reordenar 12 productos hizo {$updates} UPDATE en vez de uno.");
    }

    public function test_reordenar_deja_las_posiciones_en_el_orden_enviado(): void
    {
        $a = $this->producto('A');
        $b = $this->producto('B');
        $c = $this->producto('C');

        $this->panel()->postJson('/api/products/reorder', [
            'ids' => [$c->id, $a->id, $b->id],
        ])->assertOk();

        $orden = Product::withoutTenant()
            ->where('tenant_id', $this->tenant->id)
            ->pluck('sort_order', 'id');

        $this->assertSame(0, (int) $orden[$c->id]);
        $this->assertSame(1, (int) $orden[$a->id]);
        $this->assertSame(2, (int) $orden[$b->id]);
    }

    public function test_reordenar_no_alcanza_a_los_productos_de_otra_tienda(): void
    {
        $otraTienda = Tenant::create([
            'slug'            => 'tienda-ajena',
            'name'            => 'Tienda Ajena',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);

        $propio = $this->producto('Propio');
        $ajeno = $this->producto('Ajeno', $otraTienda);

        // Se cuela el id de la otra tienda en la lista: el CASE lo nombraria,
        // pero el WHERE por tenant_id no lo deja pasar.
        $this->panel()->postJson('/api/products/reorder', [
            'ids' => [$ajeno->id, $propio->id],
        ])->assertOk();

        $this->assertSame(
            0,
            (int) Product::withoutTenant()->find($ajeno->id)->sort_order,
            'El reorden de una tienda movio un producto de otra.'
        );
        $this->assertSame(1, (int) Product::withoutTenant()->find($propio->id)->sort_order);
    }

    public function test_reordenar_invalida_la_cache_publica(): void
    {
        $producto = $this->producto('Unico');
        $antes = (int) Cache::get("tenant:{$this->tenant->slug}:cache_version", 0);

        $this->panel()->postJson('/api/products/reorder', ['ids' => [$producto->id]])->assertOk();

        $this->assertGreaterThan($antes, (int) Cache::get("tenant:{$this->tenant->slug}:cache_version", 0));
    }

    /*
    |--------------------------------------------------------------------------
    | AUD-23 — borrado en lote
    |--------------------------------------------------------------------------
    */

    private function conGaleria(Product $producto, int $imagenes = 2): Product
    {
        for ($i = 0; $i < $imagenes; $i++) {
            $imagen = new ProductImage([
                'image_url'     => "/storage/p/{$producto->id}-{$i}.webp",
                'thumbnail_url' => "/storage/p/{$producto->id}-{$i}-thumb.webp",
                'sort_order'    => $i,
            ]);
            $imagen->product_id = $producto->id;
            $imagen->save();
        }

        return $producto;
    }

    public function test_borrar_en_lote_no_consulta_la_galeria_producto_a_producto(): void
    {
        $productos = collect(range(1, 6))->map(fn ($i) => $this->conGaleria($this->producto("Con galeria {$i}")));

        [$respuesta, $consultas] = $this->contando(
            '/^select .*product_images/i',
            fn () => $this->panel()->postJson('/api/products/bulk', [
                'product_ids' => $productos->pluck('id')->all(),
                'bulk_action' => 'delete',
            ])
        );

        $respuesta->assertOk();
        $this->assertLessThanOrEqual(
            1,
            $consultas,
            "Borrar 6 productos hizo {$consultas} consultas a product_images; con eager loading es una."
        );
    }

    public function test_borrar_en_lote_se_lleva_la_galeria_y_deja_la_cache_al_dia(): void
    {
        $productos = collect(range(1, 3))->map(fn ($i) => $this->conGaleria($this->producto("Con galeria {$i}")));
        $antes = (int) Cache::get("tenant:{$this->tenant->slug}:cache_version", 0);

        $idsDeProductos = $productos->pluck('id')->all();

        $this->assertSame(6, ProductImage::whereIn('product_id', $idsDeProductos)->count());

        $this->panel()->postJson('/api/products/bulk', [
            'product_ids' => $productos->pluck('id')->all(),
            'bulk_action' => 'delete',
        ])->assertOk();

        $this->assertSame(0, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(
            0,
            ProductImage::whereIn('product_id', $idsDeProductos)->count(),
            'La galeria sobrevivio al borrado en lote.'
        );
        $this->assertGreaterThan(
            $antes,
            (int) Cache::get("tenant:{$this->tenant->slug}:cache_version", 0),
            'El borrado en lote no invalido la cache publica.'
        );
    }

    public function test_borrar_en_lote_no_alcanza_a_otra_tienda(): void
    {
        $otraTienda = Tenant::create([
            'slug'            => 'tienda-ajena',
            'name'            => 'Tienda Ajena',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);

        $propio = $this->producto('Propio');
        $ajeno = $this->producto('Ajeno', $otraTienda);

        $this->panel()->postJson('/api/products/bulk', [
            'product_ids' => [$propio->id, $ajeno->id],
            'bulk_action' => 'delete',
        ])->assertOk();

        $this->assertNull(Product::withoutTenant()->find($propio->id));
        $this->assertNotNull(
            Product::withoutTenant()->find($ajeno->id),
            'El borrado en lote de una tienda se llevo un producto de otra.'
        );
    }
}
