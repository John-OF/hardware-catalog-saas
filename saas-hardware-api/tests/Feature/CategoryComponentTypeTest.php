<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * El armador ya no depende de cómo se llamen las categorías (FUN-8).
 *
 * Hasta aquí cada paso del armador buscaba su categoría por un trozo del
 * nombre ('procesador', 'placa', 'tarjeta'…). Una tienda que dijera "CPU" o
 * "Gráficas" se quedaba sin armador **y sin aviso**: el paso salía vacío, igual
 * que si no hubiera stock. Ahora la categoría lleva escrito qué vende
 * (`component_type`) y el catálogo público sabe filtrar por eso.
 */
class CategoryComponentTypeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-armador',
            'name'            => 'Tienda Armador',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = User::create([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-armador.test',
            'password'  => bcrypt('secret1234'),
            'role'      => 'admin',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function comoAdmin(): self
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ]);
    }

    // --------------------------------------------------------------- el tipo

    /**
     * Lo que arregla FUN-8: el nombre deja de mandar. Da igual cómo se llame la
     * categoría mientras diga de qué es.
     */
    public function test_el_tipo_se_respeta_aunque_el_nombre_no_diga_nada(): void
    {
        $respuesta = $this->comoAdmin()->postJson('/api/categories', [
            'name'           => 'Lo mejorcito de Intel',
            'component_type' => 'cpu',
        ])->assertCreated();

        $this->assertSame('cpu', $respuesta->json('component_type'));
    }

    /**
     * Quien no lo manda no se queda sin tipo. Son dos puertas: el panel de un
     * cliente viejo y el import CSV, que crea categorías desde una columna del
     * archivo sin preguntar nada.
     */
    public function test_sin_tipo_se_deduce_del_nombre(): void
    {
        $respuesta = $this->comoAdmin()
            ->postJson('/api/categories', ['name' => 'Tarjetas Graficas'])
            ->assertCreated();

        $this->assertSame('gpu', $respuesta->json('component_type'));
        // Y el icono va detrás del tipo, sin elegirlo a mano.
        $this->assertSame('gpu', $respuesta->json('icon'));
    }

    public function test_lo_que_no_es_pieza_de_pc_cae_en_otros(): void
    {
        $respuesta = $this->comoAdmin()
            ->postJson('/api/categories', ['name' => 'Sillas gamer'])
            ->assertCreated();

        $this->assertSame('other', $respuesta->json('component_type'));
        $this->assertSame('folder', $respuesta->json('icon'));
    }

    /**
     * Un tipo inventado no puede entrar: la categoría se quedaría fuera del
     * armador para siempre y nadie lo notaría, que es el fallo original.
     */
    public function test_un_tipo_inventado_se_rechaza(): void
    {
        $this->comoAdmin()->postJson('/api/categories', [
            'name'           => 'Procesadores',
            'component_type' => 'cpus',
        ])->assertStatus(422)->assertJsonValidationErrors('component_type');
    }

    /** Editar el nombre sin tocar el tipo no lo borra (la columna es NOT NULL). */
    public function test_editar_sin_mandar_el_tipo_lo_conserva(): void
    {
        $categoria = $this->crearCategoria('Procesadores', ComponentType::Cpu);

        $this->comoAdmin()->putJson("/api/categories/{$categoria->id}", [
            'name'           => 'CPUs',
            'component_type' => null,
        ])->assertOk();

        $this->assertSame(ComponentType::Cpu, $categoria->fresh()->component_type);
    }

    // ------------------------------------------------------------ lo publico

    public function test_el_catalogo_publico_expone_el_tipo(): void
    {
        $this->crearCategoria('CPUs', ComponentType::Cpu);

        $this->getJson("/api/public/{$this->tenant->slug}/categories")
            ->assertOk()
            ->assertJsonPath('0.component_type', 'cpu');
    }

    /**
     * El motivo de filtrar por tipo y no por `category_id`: una tienda parte los
     * procesadores en dos categorías por marca. Con un solo id, la mitad del
     * stock no aparecía en el paso.
     */
    public function test_el_filtro_por_tipo_junta_las_categorias_del_mismo_tipo(): void
    {
        $intel = $this->crearCategoria('Procesadores Intel', ComponentType::Cpu);
        $amd   = $this->crearCategoria('Procesadores AMD', ComponentType::Cpu);
        $ram   = $this->crearCategoria('Memorias', ComponentType::Ram);

        $this->crearProducto('Core i5', $intel->id);
        $this->crearProducto('Ryzen 5', $amd->id);
        $this->crearProducto('Fury 16GB', $ram->id);

        $this->assertSame(['Core i5', 'Ryzen 5'], $this->nombresPorTipo('cpu'));

        // Y la segunda consulta no se come la caché de la primera: el tipo entra
        // en la clave.
        $this->assertSame(['Fury 16GB'], $this->nombresPorTipo('ram'));
    }

    /** Un tipo que no existe no revienta ni se inventa un filtro. */
    public function test_un_tipo_inventado_en_lo_publico_se_ignora(): void
    {
        $cpus = $this->crearCategoria('Procesadores', ComponentType::Cpu);
        $this->crearProducto('Core i5', $cpus->id);

        $this->getJson("/api/public/{$this->tenant->slug}/products?component_type=cpus")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Marcar el tipo tiene que verse ya. La lista pública de categorías tenía
     * clave de caché propia y sin versión: el dueño elegía "Procesadores", se iba
     * a mirar su tienda y el armador seguía vacío cinco minutos.
     */
    public function test_cambiar_el_tipo_se_ve_sin_esperar_a_la_cache(): void
    {
        $categoria = $this->crearCategoria('Lo mejorcito', ComponentType::Other);

        $this->getJson("/api/public/{$this->tenant->slug}/categories")
            ->assertOk()
            ->assertJsonPath('0.component_type', 'other');

        $this->comoAdmin()->putJson("/api/categories/{$categoria->id}", [
            'name'           => 'Lo mejorcito',
            'component_type' => 'cpu',
        ])->assertOk();

        $this->getJson("/api/public/{$this->tenant->slug}/categories")
            ->assertOk()
            ->assertJsonPath('0.component_type', 'cpu');
    }

    /**
     * Los productos relacionados de la ficha salen del TIPO, no del nombre.
     *
     * Los nombres de este caso están elegidos para que el emparejamiento
     * anterior fallara dos veces: la categoría del producto se llama "CPUs"
     * -no contiene 'procesador' ni 'processor'- y las complementarias tampoco
     * se llaman "placas madre" ni "memoria ram", que era lo que se buscaba con
     * un LIKE sobre el nombre.
     */
    public function test_los_relacionados_salen_del_tipo_y_no_del_nombre(): void
    {
        $cpus     = $this->crearCategoria('CPUs', ComponentType::Cpu);
        $placas   = $this->crearCategoria('Tarjetas base', ComponentType::Motherboard);
        $memorias = $this->crearCategoria('Memorias', ComponentType::Ram);
        $pantallas = $this->crearCategoria('Pantallas', ComponentType::Monitor);

        $cpu    = $this->crearProducto('Core i5', $cpus->id);
        $placa  = $this->crearProducto('Placa B760', $placas->id);
        $ram    = $this->crearProducto('Fury 16GB', $memorias->id);

        // El monitor es el producto MAS VISTO de la tienda a proposito: es lo
        // que devuelve el respaldo de "lo mas visto" cuando no hay
        // complementarios, asi que si el emparejamiento por tipo fallara se
        // colaria en los dos primeros puestos y este test se pondria rojo. Sin
        // esto, el respaldo devolvia por casualidad la placa y la memoria y el
        // test pasaba igual de roto.
        $monitor = $this->crearProducto('Monitor 24 pulgadas', $pantallas->id);
        $monitor->views_count = 500;
        $monitor->save();

        $relacionados = $this->getJson("/api/public/{$this->tenant->slug}/products/{$cpu->id}")
            ->assertOk()
            ->json('related_products');

        // Los dos complementarios van primero; el monitor puede aparecer
        // despues como relleno de "lo mas visto", pero nunca por delante.
        $primeros = array_slice(array_column($relacionados, 'id'), 0, 2);

        $this->assertEqualsCanonicalizing([$placa->id, $ram->id], $primeros);
    }

    /** Lo que no es pieza de armado no sugiere complementarios de PC. */
    public function test_lo_que_no_es_pieza_no_tiene_complementarios(): void
    {
        $sillas = $this->crearCategoria('Sillas gamer', ComponentType::Other);
        $placas = $this->crearCategoria('Tarjetas base', ComponentType::Motherboard);

        $silla = $this->crearProducto('Silla reclinable', $sillas->id);
        $placa = $this->crearProducto('Placa B760', $placas->id);

        $relacionados = $this->getJson("/api/public/{$this->tenant->slug}/products/{$silla->id}")
            ->assertOk()
            ->json('related_products');

        // La placa entra por el respaldo de "lo mas visto", no como
        // complementaria: lo que se comprueba es que no revienta y que no se
        // inventa una pareja de montaje para una silla.
        $this->assertSame([$placa->id], array_column($relacionados, 'id'));
    }

    // ------------------------------------------------------------ auxiliares

    /**
     * `Category` no deja escribir `tenant_id` a mano (AUD-4: el scope decide),
     * asi que se crea con la tienda puesta como actual y se olvida despues,
     * igual que hace el middleware al terminar la peticion.
     */
    private function crearCategoria(string $nombre, ComponentType $tipo): Category
    {
        $this->tenant->makeCurrent();

        $categoria = Category::create(['name' => $nombre, 'component_type' => $tipo]);

        Tenant::forgetCurrent();

        return $categoria;
    }

    private function crearProducto(string $nombre, string $categoryId): Product
    {
        $producto = new Product([
            'name'      => $nombre,
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $producto->tenant_id   = $this->tenant->id;
        $producto->category_id = $categoryId;
        $producto->save();

        return $producto;
    }

    /** @return array<int, string> */
    private function nombresPorTipo(string $tipo): array
    {
        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}/products?component_type={$tipo}")
            ->assertOk();

        return array_column($respuesta->json('data'), 'name');
    }
}
