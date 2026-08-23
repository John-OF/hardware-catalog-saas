<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * El aislamiento multi-tenant falla en cerrado, no en abierto (AUD-4).
 *
 * Antes, el global scope de `BelongsToTenant` solo filtraba si había tienda
 * resuelta. Sin ella no filtraba **nada**: la consulta veía todas las tiendas.
 * Hoy no había ninguna ruta que lo explotara —las públicas filtran a mano y se
 * revisaron una a una—, pero eso es una propiedad del código de hoy, no una
 * garantía: el día que alguien añadiera un endpoint público y olvidara un
 * `where('tenant_id')`, el resultado no habría sido un error ruidoso sino una
 * fuga silenciosa de datos de todas las tiendas.
 *
 * Estos tests fijan las dos mitades del cierre: que sin tienda no se ve nada, y
 * que las rutas públicas sí la resuelven, que es lo que permite lo primero sin
 * dejar el catálogo a oscuras.
 */
class TenantScopeFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tiendaA;

    private Tenant $tiendaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiendaA = $this->makeTenant('tienda-a');
        $this->tiendaB = $this->makeTenant('tienda-b');

        $this->makeProduct($this->tiendaA, 'GPU de A');
        $this->makeProduct($this->tiendaB, 'GPU de B');
        $this->makeProduct($this->tiendaB, 'CPU de B');
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();

        parent::tearDown();
    }

    // --------------------------------------------------- el scope en sí mismo

    public function test_sin_tienda_resuelta_una_consulta_no_devuelve_nada(): void
    {
        $this->assertSame(0, Product::count(), 'Sin tienda actual no se puede ver ningun producto.');
        $this->assertNull(Product::first());
    }

    public function test_withoutTenant_es_la_unica_forma_de_mirar_por_encima(): void
    {
        $this->assertSame(3, Product::withoutTenant()->count());
    }

    public function test_con_tienda_resuelta_solo_se_ve_la_suya(): void
    {
        $this->tiendaB->makeCurrent();

        $this->assertSame(2, Product::count());
        $this->assertSame(
            ['CPU de B', 'GPU de B'],
            Product::orderBy('name')->pluck('name')->all()
        );
    }

    /**
     * `User` es la excepción documentada: la autenticación tiene que poder
     * resolver a alguien antes de que exista tienda. Si esto empieza a fallar es
     * que alguien le ha quitado la excepción, y el panel entero devolverá 401.
     */
    public function test_los_usuarios_siguen_siendo_consultables_sin_tienda(): void
    {
        $admin = new \App\Models\User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-a.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $admin->tenant_id = $this->tiendaA->id;
        $admin->save();

        $this->assertSame(1, \App\Models\User::where('email', 'duenio@tienda-a.com')->count());
    }

    // ------------------------------------------- la red debajo de lo público

    /**
     * El test que da sentido a todo lo demás.
     *
     * Se registra una ruta dentro del mismo grupo público y con el mismo
     * middleware que las de verdad, y **a propósito se le olvida** el
     * `where('tenant_id')`: es el endpoint que alguien escribirá el mes que
     * viene. Antes habría devuelto los 3 productos de las dos tiendas; ahora
     * devuelve solo el de la tienda del slug, sin que su autor haga nada.
     */
    public function test_un_endpoint_publico_que_olvida_filtrar_no_filtra_datos_ajenos(): void
    {
        Route::middleware('tenant.slug')->get('/api/public/{slug}/descuidado', function () {
            return response()->json(['nombres' => Product::orderBy('name')->pluck('name')]);
        });

        $this->getJson("/api/public/{$this->tiendaA->slug}/descuidado")
            ->assertStatus(200)
            ->assertJsonPath('nombres', ['GPU de A']);

        $this->getJson("/api/public/{$this->tiendaB->slug}/descuidado")
            ->assertStatus(200)
            ->assertJsonPath('nombres', ['CPU de B', 'GPU de B']);
    }

    public function test_el_catalogo_publico_sigue_devolviendo_lo_de_su_tienda(): void
    {
        $respuesta = $this->getJson("/api/public/{$this->tiendaB->slug}/products")->assertStatus(200);

        $this->assertSame(
            ['CPU de B', 'GPU de B'],
            collect($respuesta->json('data'))->pluck('name')->sort()->values()->all()
        );
    }

    public function test_un_slug_que_no_existe_da_404(): void
    {
        $this->getJson('/api/public/no-existe/products')->assertStatus(404);
    }

    /**
     * La tienda resuelta no puede sobrevivir a la petición: desde AUD-4 es dato
     * de correctitud, y con Octane o un worker de colas el contenedor se
     * reutiliza. Aquí se ve porque la suite comparte contenedor entre peticiones.
     */
    public function test_la_tienda_actual_no_sobrevive_a_la_peticion(): void
    {
        $this->getJson("/api/public/{$this->tiendaB->slug}/products")->assertStatus(200);

        $this->assertNull(Tenant::current(), 'La tienda deberia haberse olvidado al terminar la peticion.');
        $this->assertSame(0, Product::count());
    }

    // ------------------------------------------------------------------ apoyo

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'slug'            => $slug,
            'name'            => ucfirst($slug),
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);
    }

    private function makeProduct(Tenant $tenant, string $name): Product
    {
        $product = new Product([
            'name'      => $name,
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }
}
