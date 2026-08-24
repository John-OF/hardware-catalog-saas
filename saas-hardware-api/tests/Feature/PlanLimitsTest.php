<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Limites por plan (SAAS-3 — paso 7.7a del backlog).
 *
 * Hasta aqui `tenants.plan` se guardaba, se pintaba como badge y no decidia
 * nada: una tienda gratis podia crear productos sin fin, poner dominio propio e
 * importar catalogos enteros por CSV. Lo que fija este test es que el plan
 * mande, y sobre todo las dos formas de saltarselo que no son la evidente:
 *
 *   - `duplicate`, que crea un producto sin pasar por `store`;
 *   - `import`, que mete un catalogo entero de una sentada.
 *
 * Los limites de los tests no son los de config/plans.php: se fijan aqui con
 * config()->set() para que ajustar la matriz comercial no obligue a reescribir
 * el test —ni al reves— y para que crear 20 productos por HTTP no sea el precio
 * de comprobar un tope.
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-planes',
            'name'            => 'Tienda Planes',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-planes.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();

        $this->definirPlanes([
            'test_bajo' => [
                'products'           => 2,
                'images_per_product' => 2,
                'categories'         => 1,
                'pages'              => 1,
                'custom_domain'      => false,
                'csv_import'         => false,
            ],
            'test_alto' => [
                'products'           => 10,
                'images_per_product' => 5,
                'categories'         => 10,
                'pages'              => 10,
                'custom_domain'      => true,
                'csv_import'         => true,
            ],
            'test_ilimitado' => [
                'products'           => null,
                'images_per_product' => null,
                'categories'         => null,
                'pages'              => null,
                'custom_domain'      => true,
                'csv_import'         => true,
            ],
        ]);
    }

    /** Matriz de planes de mentira, con `test_bajo` como plan por defecto. */
    private function definirPlanes(array $planes): void
    {
        $config = [];

        foreach ($planes as $clave => $limites) {
            $config[$clave] = ['label' => $clave, 'limits' => $limites];
        }

        config()->set('plans.default', 'test_bajo');
        config()->set('plans.plans', $config);
    }

    private function enPlan(string $plan): void
    {
        $this->tenant->update(['plan' => $plan]);
    }

    private function comoAdmin(): self
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ]);
    }

    private function crearProducto(string $nombre): \Illuminate\Testing\TestResponse
    {
        return $this->comoAdmin()->postJson('/api/products', [
            'name'  => $nombre,
            'price' => 100,
            'stock' => 1,
        ]);
    }

    /** Productos sembrados por modelo: no gastan tope, que se aplica en el controlador. */
    private function sembrarProductos(int $cuantos): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            $this->sembrarProducto($this->tenant, 'Sembrado '.$i);
        }
    }

    /** `tenant_id` esta en $guarded: se asigna a mano, como en el resto de tests. */
    private function sembrarProducto(Tenant $tenant, string $nombre): Product
    {
        $producto = new Product([
            'name'      => $nombre,
            'price'     => 100,
            'stock'     => 1,
            'is_active' => true,
        ]);
        $producto->tenant_id = $tenant->id;
        $producto->save();

        return $producto;
    }

    // ---------------------------------------------------------------- productos

    public function test_el_plan_topa_la_creacion_de_productos(): void
    {
        $this->enPlan('test_bajo');
        $this->sembrarProductos(2);

        $respuesta = $this->crearProducto('El que sobra');

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('code', 'plan_limit');
        $respuesta->assertJsonPath('limit_key', 'products');
        $respuesta->assertJsonPath('limit', 2);
        $respuesta->assertJsonPath('current', 2);

        $this->assertSame(2, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_un_plan_superior_levanta_el_tope(): void
    {
        $this->enPlan('test_alto');
        $this->sembrarProductos(2);

        $this->crearProducto('Cabe de sobra')->assertCreated();
    }

    public function test_un_plan_sin_tope_no_topa(): void
    {
        $this->enPlan('test_ilimitado');
        $this->sembrarProductos(50);

        $this->crearProducto('Sin techo')->assertCreated();
    }

    /**
     * `null` en la matriz es "ilimitado", asi que un plan que no existe NO puede
     * caer en null: caeria en barra libre. Cae en el plan por defecto, igual de
     * cerrado que el aislamiento entre tiendas (AUD-4).
     */
    public function test_un_plan_desconocido_se_comporta_como_el_plan_por_defecto(): void
    {
        $this->enPlan('plan_que_no_existe');
        $this->sembrarProductos(2);

        $this->crearProducto('Con un plan inventado')
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_limit');
    }

    /**
     * Un downgrade no puede destruir datos del cliente: es justo lo que hace el
     * operador al gestionar un moroso desde el panel de plataforma (SAAS-4).
     */
    public function test_bajar_de_plan_conserva_lo_que_ya_existe_y_solo_impide_crear_mas(): void
    {
        $this->enPlan('test_alto');
        $this->sembrarProductos(6);

        $this->enPlan('test_bajo');

        $this->assertSame(6, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());

        $this->comoAdmin()->getJson('/api/products')->assertOk()->assertJsonPath('total', 6);

        $this->crearProducto('Uno mas')->assertStatus(422);
    }

    /** `duplicate` crea un producto sin pasar por `store`: es la puerta de al lado. */
    public function test_duplicar_un_producto_tambien_gasta_tope(): void
    {
        $this->enPlan('test_bajo');
        $this->sembrarProductos(2);

        $original = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        $this->comoAdmin()->postJson("/api/products/{$original->id}/duplicate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_limit');

        $this->assertSame(2, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
    }

    // ------------------------------------------------------------------ galeria

    public function test_la_galeria_topa_por_producto(): void
    {
        $this->enPlan('test_bajo');

        $respuesta = $this->comoAdmin()->post('/api/products', [
            'name'    => 'Con demasiadas fotos',
            'price'   => 100,
            'stock'   => 1,
            'gallery' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
                UploadedFile::fake()->image('c.jpg'),
            ],
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('limit_key', 'images_per_product');

        // El producto tampoco se creo: el tope se mira antes de escribir nada.
        $this->assertSame(0, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * Cambiar fotos por otras tantas no es añadir. Sin contar el saldo, un
     * producto en el tope se quedaba sin poder editar sus imagenes nunca mas.
     */
    public function test_sustituir_fotos_de_galeria_no_gasta_hueco(): void
    {
        $this->enPlan('test_bajo');
        $this->sembrarProductos(1);

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        foreach (['vieja-1', 'vieja-2'] as $i => $nombre) {
            $producto->images()->create([
                'image_url'     => "/storage/{$nombre}.webp",
                'thumbnail_url' => "/storage/{$nombre}-thumb.webp",
                'sort_order'    => $i,
            ]);
        }

        $ids = $producto->images()->pluck('id')->all();

        $this->comoAdmin()->post("/api/products/{$producto->id}", [
            '_method'           => 'PUT',
            'name'              => $producto->name,
            'price'             => 100,
            'stock'             => 1,
            'deleted_image_ids' => $ids,
            'gallery'           => [
                UploadedFile::fake()->image('nueva-1.jpg'),
                UploadedFile::fake()->image('nueva-2.jpg'),
            ],
        ])->assertOk();

        $this->assertSame(2, $producto->images()->count());
    }

    // ------------------------------------------------------- categorias y paginas

    public function test_las_categorias_tienen_su_propio_tope(): void
    {
        $this->enPlan('test_bajo');

        $this->comoAdmin()->postJson('/api/categories', ['name' => 'Procesadores'])->assertCreated();

        $this->comoAdmin()->postJson('/api/categories', ['name' => 'Tarjetas graficas'])
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'categories');
    }

    public function test_las_paginas_informativas_tienen_su_propio_tope(): void
    {
        $this->enPlan('test_bajo');

        $this->comoAdmin()->postJson('/api/pages', ['title' => 'Envios', 'slug' => 'envios'])->assertCreated();

        $this->comoAdmin()->postJson('/api/pages', ['title' => 'Garantia', 'slug' => 'garantia'])
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'pages');
    }

    // -------------------------------------------------------------- import CSV

    public function test_el_import_no_esta_incluido_en_los_planes_que_no_lo_traen(): void
    {
        $this->enPlan('test_bajo');

        $this->importar("nombre;precio;stock\nGPU;100;5\n")
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_limit')
            ->assertJsonPath('limit_key', 'csv_import');

        $this->assertSame(0, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * El tope corta el import, no lo rechaza entero: si el archivo trae 6 filas
     * y solo caben 4, entran 4. Rechazarlo entero obligaria al dueño a editar el
     * CSV a mano para no perder lo que si cabia.
     */
    public function test_el_import_llena_el_hueco_que_queda_y_avisa_del_resto(): void
    {
        $this->enPlan('test_alto');   // 10 productos
        $this->sembrarProductos(6);   // quedan 4 de hueco

        $filas = '';
        for ($i = 0; $i < 6; $i++) {
            $filas .= "Importado {$i};100;5\n";
        }

        $respuesta = $this->importar("nombre;precio;stock\n".$filas);

        $respuesta->assertOk();
        $respuesta->assertJsonPath('success_count', 4);

        $this->assertSame(10, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
        $this->assertStringContainsString('4 productos más', implode(' ', $respuesta->json('errors')));
    }

    /** Las categorias nuevas del CSV tambien gastan tope, y sin tirar el producto. */
    public function test_el_import_deja_sin_categoria_lo_que_no_cabe_en_el_tope_de_categorias(): void
    {
        $this->enPlan('test_alto');
        config()->set('plans.plans.test_alto.limits.categories', 1);

        $filas = "Uno;100;5;Procesadores\nDos;100;5;Tarjetas graficas\n";

        $respuesta = $this->importar("nombre;precio;stock;categoria\n".$filas);

        $respuesta->assertOk();
        $respuesta->assertJsonPath('success_count', 2);

        $this->assertSame(1, Category::withoutTenant()->where('tenant_id', $this->tenant->id)->count());

        $productos = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->get();
        $this->assertNotNull($productos->firstWhere('name', 'Uno')->category_id);
        $this->assertNull($productos->firstWhere('name', 'Dos')->category_id);

        $this->assertStringContainsString('sin categoría', implode(' ', $respuesta->json('errors')));
    }

    private function importar(string $contenido): \Illuminate\Testing\TestResponse
    {
        return $this->comoAdmin()->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $contenido),
        ]);
    }

    // ---------------------------------------------------------- dominio propio

    public function test_el_dominio_propio_se_rechaza_si_el_plan_no_lo_incluye(): void
    {
        $this->enPlan('test_bajo');

        $this->comoAdmin()->putJson('/api/tenant', ['custom_domain' => 'mitienda.com'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_limit')
            ->assertJsonPath('limit_key', 'custom_domain');

        $this->assertNull($this->tenant->fresh()->custom_domain);
    }

    public function test_el_dominio_propio_se_acepta_en_un_plan_que_lo_incluye(): void
    {
        $this->enPlan('test_alto');

        $this->comoAdmin()->putJson('/api/tenant', ['custom_domain' => 'mitienda.com'])->assertOk();

        $this->assertSame('mitienda.com', $this->tenant->fresh()->custom_domain);
    }

    /**
     * Quitarlo tiene que poder hacerse siempre. Si el gate mirara tambien el
     * vaciado, una tienda que baja de plan se quedaria con el dominio puesto y
     * sin manera de soltarlo desde el panel.
     */
    public function test_se_puede_vaciar_el_dominio_propio_aunque_el_plan_ya_no_lo_incluya(): void
    {
        $this->tenant->update(['custom_domain' => 'mitienda.com']);
        $this->enPlan('test_bajo');

        $this->comoAdmin()->putJson('/api/tenant', ['custom_domain' => null])->assertOk();

        $this->assertNull($this->tenant->fresh()->custom_domain);
    }

    /** Guardar el resto de la configuracion no puede tropezar con el dominio ya puesto. */
    public function test_guardar_otros_campos_no_dispara_el_gate_del_dominio(): void
    {
        $this->tenant->update(['custom_domain' => 'mitienda.com']);
        $this->enPlan('test_bajo');

        $this->comoAdmin()->putJson('/api/tenant', [
            'name'          => 'Nombre nuevo',
            'custom_domain' => 'mitienda.com',
        ])->assertOk();

        $this->assertSame('Nombre nuevo', $this->tenant->fresh()->name);
    }

    // ------------------------------------------------------------ endpoint /plan

    public function test_el_endpoint_de_plan_devuelve_limites_y_consumo(): void
    {
        $this->enPlan('test_alto');
        $this->sembrarProductos(3);

        $categoria = new Category(['name' => 'Procesadores', 'is_active' => true]);
        $categoria->tenant_id = $this->tenant->id;
        $categoria->save();

        $pagina = new Page(['title' => 'Envios', 'slug' => 'envios', 'is_active' => true]);
        $pagina->tenant_id = $this->tenant->id;
        $pagina->save();

        $respuesta = $this->comoAdmin()->getJson('/api/plan');

        $respuesta->assertOk();
        $respuesta->assertJsonPath('plan', 'test_alto');
        $respuesta->assertJsonPath('limits.products', 10);
        $respuesta->assertJsonPath('limits.custom_domain', true);
        $respuesta->assertJsonPath('usage.products', 3);
        $respuesta->assertJsonPath('usage.categories', 1);
        $respuesta->assertJsonPath('usage.pages', 1);
    }

    /** El consumo de una tienda no puede incluir el de la de al lado. */
    public function test_el_consumo_no_cuenta_lo_de_otras_tiendas(): void
    {
        $otra = Tenant::create([
            'slug'            => 'tienda-vecina',
            'name'            => 'Tienda Vecina',
            'whatsapp_number' => '51988888888',
            'is_active'       => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->sembrarProducto($otra, 'De la vecina '.$i);
        }

        $this->enPlan('test_alto');
        $this->sembrarProductos(2);

        $this->comoAdmin()->getJson('/api/plan')
            ->assertOk()
            ->assertJsonPath('usage.products', 2);
    }
}
