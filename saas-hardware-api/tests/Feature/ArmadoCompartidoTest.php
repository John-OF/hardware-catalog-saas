<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Las piezas de un armado compartido por enlace (MOD-20): el catálogo público
 * las devuelve por id, con la misma forma que el listado.
 *
 * Lo que se vigila es lo de siempre en una ruta pública: que un id no saque
 * nada que el catálogo no enseñe —borradores, desactivados, otra tienda— y que
 * los ids no se conviertan en una puerta para llenar la caché.
 */
class ArmadoCompartidoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();

        $this->tenant = $this->tienda('tienda-armado');
    }

    private function tienda(string $slug): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => $slug, 'whatsapp_number' => '51999999999', 'is_active' => true, 'is_published' => true]);
    }

    private function producto(string $nombre, array $atributos = [], ?Tenant $tienda = null): Product
    {
        $tienda ??= $this->tenant;

        $categoria = new Category(['name' => "Procesadores {$nombre}", 'component_type' => 'cpu', 'is_active' => true]);
        $categoria->tenant_id = $tienda->id;
        $categoria->save();

        $producto = new Product($atributos + [
            'name' => $nombre, 'price' => 100, 'stock' => 3, 'is_active' => true, 'status' => 'published',
            'category_id' => $categoria->id,
        ]);
        $producto->tenant_id = $tienda->id;
        $producto->save();

        return $producto;
    }

    private function pedir(array $ids, string $slug = 'tienda-armado')
    {
        return $this->getJson("/api/public/{$slug}/products?".http_build_query(['ids' => $ids]));
    }

    public function test_devuelve_las_piezas_pedidas_con_el_tipo_de_su_categoria(): void
    {
        $ryzen = $this->producto('Ryzen');
        $this->producto('Otro que no se pidio');

        $respuesta = $this->pedir([$ryzen->id])->assertOk();

        $this->assertSame([$ryzen->id], array_column($respuesta->json('data'), 'id'));
        // El armador comprueba con esto que cada pieza del enlace es de su paso.
        $this->assertSame('cpu', $respuesta->json('data.0.category.component_type'));
    }

    public function test_no_saca_borradores_desactivados_ni_productos_de_otra_tienda(): void
    {
        $borrador = $this->producto('Borrador', ['status' => 'draft']);
        $apagado = $this->producto('Apagado', ['is_active' => false]);
        $ajeno = $this->producto('Ajeno', [], $this->tienda('otra-tienda'));

        $this->assertSame([], $this->pedir([$borrador->id, $apagado->id, $ajeno->id])->assertOk()->json('data'));
    }

    /** Un id que no es un UUID ni llega a la consulta, y una lista vacía no devuelve el catálogo entero. */
    public function test_lo_que_no_es_un_uuid_se_descarta_y_vacio_no_es_todo(): void
    {
        $this->producto('Ryzen');

        $this->assertSame([], $this->pedir(["1' OR '1'='1", 'cualquier-cosa'])->assertOk()->json('data'));
    }

    /** Sin caché: cada combinación de ids sería una entrada nueva (la fuga de AUD-7). */
    public function test_las_piezas_por_id_no_se_guardan_en_cache(): void
    {
        $ryzen = $this->producto('Ryzen');

        // El listado normal sí deja su entrada: es lo que demuestra que la
        // comprobación de abajo mira donde tiene que mirar.
        $this->getJson('/api/public/tienda-armado/products')->assertOk();
        $this->assertTrue($this->hayCacheDelCatalogo(), 'El listado normal no dejó entrada: la comprobación no sirve.');

        Cache::flush();
        $this->pedir([$ryzen->id])->assertOk();

        $this->assertFalse($this->hayCacheDelCatalogo(), 'El pedido por ids dejó una entrada en la caché del catálogo.');
    }

    private function hayCacheDelCatalogo(): bool
    {
        // La suite usa el almacén `array`, que guarda las claves en `$storage`.
        $claves = array_keys((fn () => $this->storage)->call(Cache::getStore()));

        return collect($claves)->contains(fn (string $clave) => str_starts_with($clave, 'catalog:tienda-armado'));
    }
}
