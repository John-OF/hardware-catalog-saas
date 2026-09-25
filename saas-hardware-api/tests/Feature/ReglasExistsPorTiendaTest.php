<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `ACC-5`. La regla `exists:tabla,id` del validador consulta la tabla a pelo y no
 * pasa por el scope de `BelongsToTenant`, asi que un id de OTRA tienda la
 * superaba. Los ids de las categorias son publicos (`/public/{slug}/categories`),
 * y con ellos un producto de A quedaba guardado con una categoria de B; en lote y
 * al reordenar, un 422 frente a un 200 decia si un id existia en otra tienda.
 */
class ReglasExistsPorTiendaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tiendaA;
    private User $adminDeA;
    private Category $categoriaDeA;
    private Product $productoDeA;
    private Category $categoriaDeB;
    private Product $productoDeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tiendaA = $this->tienda('tienda-a');
        $tiendaB = $this->tienda('tienda-b');

        $this->adminDeA = new User(['name' => 'Duenio A', 'email' => 'duenio@a.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $this->adminDeA->tenant_id = $this->tiendaA->id;
        $this->adminDeA->save();

        $this->categoriaDeA = $this->categoria($this->tiendaA, 'GPUs de A');
        $this->categoriaDeB = $this->categoria($tiendaB, 'GPUs de B');
        $this->productoDeA = $this->producto($this->tiendaA, 'RTX de A', $this->categoriaDeA);
        $this->productoDeB = $this->producto($tiendaB, 'RTX de B', $this->categoriaDeB);
    }

    // -------------------------------------- la categoria de otra tienda

    public function test_no_se_crea_un_producto_con_la_categoria_de_otra_tienda(): void
    {
        $this->panel()->postJson('/api/products', [
            'name' => 'Nuevo', 'price' => 10, 'stock' => 1, 'category_id' => $this->categoriaDeB->id,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->assertSame(0, DB::table('products')->where('category_id', $this->categoriaDeB->id)->where('tenant_id', $this->tiendaA->id)->count());
    }

    public function test_no_se_edita_un_producto_para_ponerle_la_categoria_de_otra_tienda(): void
    {
        $this->panel()->putJson("/api/products/{$this->productoDeA->id}", [
            'name' => 'RTX de A', 'price' => 10, 'stock' => 1, 'category_id' => $this->categoriaDeB->id,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->assertSame($this->categoriaDeA->id, DB::table('products')->where('id', $this->productoDeA->id)->value('category_id'));
    }

    /** Control: con su propia categoria, sin cambios. */
    public function test_con_una_categoria_propia_se_crea_igual(): void
    {
        $this->panel()->postJson('/api/products', [
            'name' => 'Nuevo', 'price' => 10, 'stock' => 1, 'category_id' => $this->categoriaDeA->id,
        ])->assertCreated();
    }

    // --------------------------------------------- el oraculo de ids ajenos

    public function test_las_acciones_en_lote_no_aceptan_ids_de_otra_tienda(): void
    {
        $this->panel()->postJson('/api/products/bulk', [
            'product_ids' => [$this->productoDeB->id], 'bulk_action' => 'deactivate',
        ])->assertStatus(422);

        $this->panel()->postJson('/api/products/bulk', [
            'category_id' => $this->categoriaDeB->id, 'bulk_action' => 'deactivate',
        ])->assertStatus(422);

        $this->assertTrue((bool) DB::table('products')->where('id', $this->productoDeB->id)->value('is_active'));
    }

    public function test_reordenar_no_acepta_ids_de_otra_tienda(): void
    {
        $this->panel()->postJson('/api/products/reorder', ['ids' => [$this->productoDeB->id]])->assertStatus(422);
        $this->panel()->postJson('/api/categories/reorder', ['ids' => [$this->categoriaDeB->id]])->assertStatus(422);
    }

    /**
     * La prueba del oraculo: un id ajeno y uno inventado tienen que responder
     * IGUAL. Si el ajeno pasara y el inventado no, la respuesta diria que existe.
     */
    public function test_un_id_ajeno_responde_igual_que_uno_que_no_existe(): void
    {
        $ajeno = $this->panel()->postJson('/api/products/reorder', ['ids' => [$this->productoDeB->id]]);
        $inventado = $this->panel()->postJson('/api/products/reorder', ['ids' => ['01a0dada-0000-7000-8000-000000000000']]);

        $this->assertSame($inventado->getStatusCode(), $ajeno->getStatusCode());
        $this->assertSame(
            str_replace($this->productoDeB->id, 'X', $ajeno->getContent()),
            str_replace('01a0dada-0000-7000-8000-000000000000', 'X', $inventado->getContent()),
        );
    }

    /** Control: con sus propios ids, sin cambios. */
    public function test_con_ids_propios_el_lote_y_el_orden_funcionan(): void
    {
        $this->panel()->postJson('/api/products/bulk', ['product_ids' => [$this->productoDeA->id], 'bulk_action' => 'deactivate'])->assertOk();
        $this->panel()->postJson('/api/products/reorder', ['ids' => [$this->productoDeA->id]])->assertOk();
        $this->panel()->postJson('/api/categories/reorder', ['ids' => [$this->categoriaDeA->id]])->assertOk();
    }

    // ------------------------------------------------------------ apoyo

    private function panel(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->adminDeA->createToken('t', ['admin'])->plainTextToken,
            'X-Tenant'      => $this->tiendaA->slug,
        ]);
    }

    private function tienda(string $slug): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => $slug, 'whatsapp_number' => '51999999999', 'is_active' => true, 'plan' => 'enterprise']);
    }

    private function categoria(Tenant $tienda, string $nombre): Category
    {
        $categoria = new Category(['name' => $nombre, 'is_active' => true]);
        $categoria->tenant_id = $tienda->id;
        $categoria->save();

        return $categoria;
    }

    private function producto(Tenant $tienda, string $nombre, Category $categoria): Product
    {
        $producto = new Product(['name' => $nombre, 'price' => 100, 'stock' => 5, 'status' => 'published', 'is_active' => true, 'category_id' => $categoria->id]);
        $producto->tenant_id = $tienda->id;
        $producto->save();

        return $producto;
    }
}
