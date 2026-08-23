<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * El filtro por specs solo acepta lo que existe en el catálogo (AUD-7).
 *
 * La clave que mandaba el visitante entraba tal cual en la ruta JSON de la
 * consulta, así que `?specs[a"]=x` devolvía un **500** (`Invalid JSON path
 * expression`) sin autenticación. No había inyección —el valor va parametrizado—
 * pero sí un 500 gratis y ruido en el log.
 *
 * El segundo efecto era más silencioso: la clave de caché es un `md5` de los
 * parámetros, `specs` incluido, así que con claves y valores libres se podían
 * crear entradas de caché ilimitadas. En producción `CACHE_STORE=database`: eso
 * es engordar una tabla de MySQL con blobs del tamaño de una página de catálogo.
 * Por eso validar la forma de la clave no bastaba; hay que acotar el conjunto.
 */
class CatalogSpecFilterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-specs',
            'name'            => 'Tienda Specs',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->makeProduct('Ryzen 5', ['Socket' => 'AM5', 'Nucleos' => '6']);
        $this->makeProduct('Ryzen 7', ['Socket' => 'AM5', 'Nucleos' => '8']);
        $this->makeProduct('Core i5', ['Socket' => 'LGA1700', 'Nucleos' => '6']);
    }

    /**
     * Lo primero: que el filtro siga funcionando. El resto del arreglo no vale
     * nada si de paso se ha cargado la función.
     */
    public function test_el_filtro_por_spec_sigue_funcionando(): void
    {
        $this->assertSame(['Ryzen 5', 'Ryzen 7'], $this->nombresFiltrando(['Socket' => 'AM5']));
        $this->assertSame(['Core i5'], $this->nombresFiltrando(['Socket' => 'LGA1700']));
    }

    public function test_dos_specs_a_la_vez_se_combinan(): void
    {
        $this->assertSame(
            ['Ryzen 5'],
            $this->nombresFiltrando(['Socket' => 'AM5', 'Nucleos' => '6'])
        );
    }

    /**
     * El caso que reventaba: una comilla dentro de la clave.
     *
     * Ojo con lo que este test puede y no puede probar: el **500** que midió la
     * auditoría (`Invalid JSON path expression`) es de MySQL, y los tests corren
     * sobre SQLite, que ante la misma ruta JSON rota no protesta —simplemente no
     * casa nada— y devolvía el catálogo VACÍO. O sea que aquí se fija el
     * comportamiento correcto (200 y filtro ignorado) y se detecta la regresión
     * en los dos motores, pero el 500 concreto solo se reproduce contra MySQL.
     */
    public function test_una_clave_con_comillas_responde_200_y_no_500(): void
    {
        $respuesta = $this->getJson(
            "/api/public/{$this->tenant->slug}/products?".http_build_query(['specs' => ['a"' => 'x']])
        );

        $respuesta->assertStatus(200);

        // Y el filtro imposible se ignora en vez de dejar el catálogo vacío.
        $this->assertCount(3, $respuesta->json('data'));
    }

    public function test_una_clave_que_no_existe_se_ignora(): void
    {
        $this->assertSame(
            ['Core i5', 'Ryzen 5', 'Ryzen 7'],
            $this->nombresFiltrando(['ColorFavorito' => 'azul'])
        );
    }

    /**
     * Clave real pero valor inventado: también fuera. Si solo se validara la
     * clave, la caché seguiría siendo inundable con valores al azar.
     */
    public function test_un_valor_que_no_existe_se_ignora(): void
    {
        $this->assertSame(
            ['Core i5', 'Ryzen 5', 'Ryzen 7'],
            $this->nombresFiltrando(['Socket' => 'SOCKET-QUE-NO-EXISTE'])
        );
    }

    /**
     * La mitad menos visible del arreglo: que no se puedan fabricar entradas de
     * caché a voluntad. Cien filtros basura tienen que compartir la entrada del
     * catálogo sin filtro, porque todos se quedan en el mismo sitio: en nada.
     */
    public function test_los_filtros_basura_no_multiplican_las_entradas_de_cache(): void
    {
        // Se calienta primero la entrada del catalogo sin filtro: es donde tienen
        // que caer todos los filtros basura, porque de todos ellos no queda nada.
        $this->getJson("/api/public/{$this->tenant->slug}/products")->assertStatus(200);

        $antes = $this->clavesDeCatalogo();
        $this->assertSame(1, $antes);

        for ($i = 0; $i < 20; $i++) {
            $this->getJson(
                "/api/public/{$this->tenant->slug}/products?".http_build_query(['specs' => ["basura{$i}" => "valor{$i}"]])
            )->assertStatus(200);
        }

        $this->assertSame(
            $antes,
            $this->clavesDeCatalogo(),
            '20 filtros inventados no deberian dejar 20 entradas de cache nuevas.'
        );
    }

    /**
     * El mismo filtro escrito al revés es el mismo filtro, y debe reusar la
     * entrada de caché en vez de duplicarla.
     */
    public function test_el_orden_de_los_filtros_no_duplica_la_cache(): void
    {
        $this->nombresFiltrando(['Socket' => 'AM5', 'Nucleos' => '6']);
        $conUnOrden = $this->clavesDeCatalogo();

        $this->nombresFiltrando(['Nucleos' => '6', 'Socket' => 'AM5']);

        $this->assertSame($conUnOrden, $this->clavesDeCatalogo());
    }

    // ------------------------------------------------------------------ apoyo

    /** @param array<string, string> $specs */
    private function nombresFiltrando(array $specs): array
    {
        $respuesta = $this->getJson(
            "/api/public/{$this->tenant->slug}/products?".http_build_query(['specs' => $specs])
        );

        $respuesta->assertStatus(200);

        $nombres = array_column($respuesta->json('data'), 'name');
        sort($nombres);

        return $nombres;
    }

    /**
     * Cuántas entradas de caché de catálogo hay ahora mismo.
     *
     * El store de tests es `array`, así que se puede mirar por dentro; con
     * `database` habría que contar filas y el test diría lo mismo.
     */
    private function clavesDeCatalogo(): int
    {
        $todas = array_keys(Cache::getStore()->all());

        return count(array_filter($todas, fn ($clave) => str_contains($clave, 'catalog:')));
    }

    /** @param array<string, string> $specs */
    private function makeProduct(string $name, array $specs): Product
    {
        $product = new Product([
            'name'      => $name,
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
            'specs'     => $specs,
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        return $product;
    }
}
