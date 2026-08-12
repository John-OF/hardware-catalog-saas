<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Import de productos por CSV (OWN-5 / 8.8).
 *
 * El fallo original: la plantilla que el propio sistema entregaba usaba ';' a la
 * vez como delimitador de columnas y como separador de specs, y sin comillas.
 * Al reimportarla, "Nucleos:20" se leia como una columna extra y las specs se
 * perdian. La plantilla oficial no round-trippeaba.
 *
 * Este test reproduce EXACTAMENTE el contenido que genera el boton "Descargar
 * plantilla" del panel, BOM incluido.
 */
class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    /** Igual que `handleDownloadTemplate` en ProductsPage.tsx. */
    private const PLANTILLA_CABECERA = "nombre;marca;precio;precio_oferta;stock;categoria;descripcion;especificaciones\n";

    private const PLANTILLA_FILA = 'Intel Core i7-14700K;Intel;409.99;389.99;15;Procesadores;"Procesador de alto rendimiento para socket LGA1700";"Frecuencia:3.4 GHz|Núcleos:20"'."\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-a.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    private function importar(string $contenido): \Illuminate\Testing\TestResponse
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ])->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $contenido),
        ]);
    }

    public function test_la_plantilla_oficial_round_trippea_con_sus_specs(): void
    {
        // Con BOM, que es lo que escribe la plantilla para que Excel la abra bien.
        $contenido = "\xEF\xBB\xBF".self::PLANTILLA_CABECERA.self::PLANTILLA_FILA;

        $this->importar($contenido)->assertOk();

        $producto = Product::where('tenant_id', $this->tenant->id)->first();

        $this->assertNotNull($producto, 'La plantilla oficial no importó ningún producto.');
        $this->assertSame('Intel Core i7-14700K', $producto->name);
        $this->assertSame('Intel', $producto->brand);
        $this->assertSame('409.99', $producto->price);
        $this->assertSame('389.99', $producto->sale_price);
        $this->assertSame(15, $producto->stock);

        // Lo que se perdía antes: las dos specs completas.
        $this->assertSame(
            ['Frecuencia' => '3.4 GHz', 'Núcleos' => '20'],
            $producto->specs
        );
    }

    public function test_el_bom_no_rompe_el_mapeo_de_columnas(): void
    {
        // El BOM ensucia SOLO la primera cabecera. Con el orden de la plantilla
        // eso pasa desapercibido porque 'nombre' tiene fallback a la posición 0,
        // asi que aqui se reordenan las columnas para que la primera sea 'marca',
        // que no tiene fallback: sin limpiar el BOM, la marca se importa vacia.
        $contenido = "\xEF\xBB\xBF"
            ."marca;nombre;precio;stock\n"
            ."Intel;Intel Core i7-14700K;409.99;15\n";

        $this->importar($contenido)->assertOk();

        $producto = Product::where('tenant_id', $this->tenant->id)->first();

        $this->assertSame('Intel Core i7-14700K', $producto->name);
        $this->assertSame('Intel', $producto->brand);
    }

    public function test_sigue_aceptando_specs_separadas_por_punto_y_coma(): void
    {
        // Los archivos que ya usaba la gente: ';' dentro de la columna, pero
        // entrecomillada para que no la parta el delimitador.
        $contenido = self::PLANTILLA_CABECERA
            .'AMD Ryzen 5 7600X;AMD;229;;12;Procesadores;"Un procesador";"Socket:AM5;Nucleos:6"'."\n";

        $this->importar($contenido)->assertOk();

        $producto = Product::where('tenant_id', $this->tenant->id)->first();

        $this->assertSame(['Socket' => 'AM5', 'Nucleos' => '6'], $producto->specs);
    }

    public function test_importa_varias_filas_y_reporta_las_malas(): void
    {
        $contenido = self::PLANTILLA_CABECERA
            .'Producto bueno;Marca;100;;5;Categoria;"Desc";"Socket:AM5"'."\n"
            .';Marca;100;;5;Categoria;"Sin nombre";""'."\n"
            .'Precio invalido;Marca;abc;;5;Categoria;"Desc";""'."\n";

        $respuesta = $this->importar($contenido)->assertOk();

        $this->assertSame(1, Product::where('tenant_id', $this->tenant->id)->count());
        $this->assertNotEmpty($respuesta->json('errors'));
    }

    public function test_el_import_es_de_la_tienda_del_token(): void
    {
        $otraTienda = Tenant::create([
            'slug'            => 'tienda-b',
            'name'            => 'Tienda B',
            'whatsapp_number' => '51888888888',
            'is_active'       => true,
        ]);

        $this->importar(self::PLANTILLA_CABECERA.self::PLANTILLA_FILA)->assertOk();

        $this->assertSame(1, Product::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, Product::where('tenant_id', $otraTienda->id)->count());
    }
}
