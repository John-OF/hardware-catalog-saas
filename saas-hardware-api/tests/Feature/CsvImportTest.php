<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
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
    private const PLANTILLA_CABECERA = "nombre;marca;variante;precio;precio_oferta;stock;categoria;descripcion;especificaciones\n";

    private const PLANTILLA_FILA = 'Intel Core i7-14700K;Intel;;409.99;389.99;15;Procesadores;"Procesador de alto rendimiento para socket LGA1700";"Frecuencia:3.4 GHz|Núcleos:20"'."\n";

    /** Las otras dos filas de la plantilla, el ejemplo de variantes (MOD-12). */
    private const PLANTILLA_FILAS_VARIANTE = 'Kingston NV3;Kingston;Capacidad: 1 TB;289.99;;8;Almacenamiento;"SSD NVMe Gen4";"Interfaz:PCIe 4.0"'."\n"
        .'Kingston NV3;;Capacidad: 2 TB;499.99;;3;;;'."\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        // SAAS-3: el import es una funcion de plan y los topes de catalogo son
        // por plan. Este test es sobre el parseo del CSV, no sobre los limites
        // (esos van en PlanLimitsTest), asi que la tienda va en el plan que no
        // topa nada y el import se mide contra el archivo, no contra el plan.
        $this->tenant = Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
            'plan'            => 'enterprise',
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

        // FUN-18: el import ya no crea categorias, solo usa las que hay. Estas
        // son las que nombran los archivos de este test.
        foreach (['Procesadores' => 'cpu', 'Almacenamiento' => 'ssd', 'Categoria' => 'other', 'Cables' => 'other', 'Perifericos' => 'peripheral'] as $nombre => $tipo) {
            $this->categoria($nombre, $tipo);
        }
    }

    private function categoria(string $nombre, string $tipo = 'other'): \App\Models\Category
    {
        $categoria = new \App\Models\Category(['name' => $nombre, 'component_type' => $tipo, 'is_active' => true]);
        $categoria->tenant_id = $this->tenant->id;
        $categoria->save();

        return $categoria;
    }

    private function importarEnModo(string $contenido, string $modo): \Illuminate\Testing\TestResponse
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
            'Accept'        => 'application/json',
        ])->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $contenido),
            'modo' => $modo,
        ]);
    }

    /** Un producto que ya estaba en la tienda antes del import. */
    private function existente(string $nombre, array $atributos = []): Product
    {
        $producto = new Product($atributos + ['name' => $nombre, 'price' => 100, 'stock' => 1, 'is_active' => true]);
        $producto->tenant_id = $this->tenant->id;
        $producto->save();

        return $producto;
    }

    private function productosDeLaTienda(): \Illuminate\Support\Collection
    {
        return Product::withoutTenant()->where('tenant_id', $this->tenant->id)->get();
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

        // `withoutTenant()` porque aqui ya no hay tienda actual: la resolvio la
        // peticion de import y muere con ella (AUD-4). El filtro por tenant_id
        // que sigue es el que hace el trabajo, y es el que el test quiere probar.
        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

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

    public function test_el_ejemplo_de_variantes_de_la_plantilla_entra_como_una_ficha_con_dos(): void
    {
        // MOD-12: la plantilla trae ahora tres filas —un producto suelto y un SSD
        // con dos capacidades—. Si el ejemplo que reparte el panel no importara
        // bien, el dueño parte de un archivo roto.
        $contenido = "\xEF\xBB\xBF".self::PLANTILLA_CABECERA.self::PLANTILLA_FILA.self::PLANTILLA_FILAS_VARIANTE;

        $respuesta = $this->importar($contenido)->assertOk();

        $this->assertSame(2, $respuesta->json('success_count'));
        $this->assertSame([], $respuesta->json('errors'));

        $ssd = Product::withoutTenant()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Kingston NV3')
            ->firstOrFail();

        $variantes = $this->variantesDe($ssd);

        $this->assertCount(2, $variantes);
        $this->assertSame('Kingston', $ssd->brand);
        $this->assertSame(['Interfaz' => 'PCIe 4.0'], $ssd->specs);
        // El resumen de la ficha: la mas barata y el stock sumado (MOD-5).
        $this->assertSame('289.99', $ssd->price);
        $this->assertSame(11, $ssd->stock);
        $this->assertSame(
            [['name' => 'Capacidad', 'value' => '1 TB']],
            $variantes->first()->options,
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

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        $this->assertSame('Intel Core i7-14700K', $producto->name);
        $this->assertSame('Intel', $producto->brand);
    }

    public function test_sigue_aceptando_specs_separadas_por_punto_y_coma(): void
    {
        // Los archivos que ya usaba la gente: ';' dentro de la columna, pero
        // entrecomillada para que no la parta el delimitador.
        $contenido = self::PLANTILLA_CABECERA
            .'AMD Ryzen 5 7600X;AMD;;229;;12;Procesadores;"Un procesador";"Socket:AM5;Nucleos:6"'."\n";

        $this->importar($contenido)->assertOk();

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        $this->assertSame(['Socket' => 'AM5', 'Nucleos' => '6'], $producto->specs);
    }

    public function test_importa_varias_filas_y_reporta_las_malas(): void
    {
        $contenido = self::PLANTILLA_CABECERA
            .'Producto bueno;Marca;;100;;5;Categoria;"Desc";"Socket:AM5"'."\n"
            .';Marca;;100;;5;Categoria;"Sin nombre";""'."\n"
            .'Precio invalido;Marca;;abc;;5;Categoria;"Desc";""'."\n";

        $respuesta = $this->importar($contenido)->assertOk();

        $this->assertSame(1, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
        $this->assertNotEmpty($respuesta->json('errors'));
    }

    /**
     * AUD-10: un archivo por encima del tope se rechaza con un mensaje que se
     * entiende, en vez de dejar la transaccion abierta hasta agotar el tiempo
     * de ejecucion y devolver un 504 sin explicacion.
     */
    public function test_un_csv_por_encima_del_tope_se_rechaza_sin_importar_nada(): void
    {
        $filas = '';
        for ($i = 0; $i < 2001; $i++) {
            $filas .= "Producto {$i};Marca;;100;;5;Categoria;\"Desc\";\"\"\n";
        }

        $respuesta = $this->importar(self::PLANTILLA_CABECERA.$filas);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('2000', $respuesta->json('message'));
        $this->assertSame(0, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * AUD-10: el import ya no guarda fila a fila. El INSERT en lote se salta los
     * hooks del modelo, asi que lo que ellos ponian hay que comprobarlo: uuid,
     * tenant_id, timestamps, los casts y la invalidacion de la cache publica.
     */
    public function test_el_insert_en_lote_cruza_varios_lotes_y_conserva_lo_que_ponian_los_hooks(): void
    {
        $versionAntes = (int) \Illuminate\Support\Facades\Cache::get("tenant:{$this->tenant->slug}:cache_version", 0);

        // Mas de LOTE_CSV (500) para que haya al menos dos INSERT y un resto.
        $filas = '';
        for ($i = 0; $i < 600; $i++) {
            $filas .= "Producto {$i};Marca;;100;;5;Categoria;\"Desc\";\"Socket:AM5\"\n";
        }

        $this->importar(self::PLANTILLA_CABECERA.$filas)->assertOk();

        $productos = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->get();

        $this->assertCount(600, $productos);
        $this->assertCount(600, $productos->pluck('id')->unique(), 'Los uuid del lote no son unicos.');

        $primero = $productos->firstWhere('name', 'Producto 0');
        $this->assertNotNull($primero->created_at);
        $this->assertNotNull($primero->updated_at);
        $this->assertSame(['Socket' => 'AM5'], $primero->specs);
        $this->assertSame('Desc', $primero->description);

        // Todas van a la categoria que ya existia, y no se creo ninguna (FUN-18).
        $this->assertSame(5, \App\Models\Category::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
        $this->assertCount(1, $productos->pluck('category_id')->unique());
        $this->assertNotNull($primero->category_id);

        $versionDespues = (int) \Illuminate\Support\Facades\Cache::get("tenant:{$this->tenant->slug}:cache_version", 0);
        $this->assertGreaterThan($versionAntes, $versionDespues, 'El import no invalido la cache publica.');
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

        $this->assertSame(1, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, Product::withoutTenant()->where('tenant_id', $otraTienda->id)->count());
    }

    // ---------------------------------------------------------------- MOD-12
    //
    // Variantes por CSV. La columna `variante` es la que decide: vacia o
    // ausente, cada fila es su propia ficha (lo de siempre); rellena, la fila se
    // cuelga de la ficha que se llame igual.

    private const CABECERA_VARIANTES = "nombre;marca;variante;sku;precio;precio_oferta;costo;stock;categoria;descripcion;especificaciones\n";

    public function test_las_filas_con_variante_se_agrupan_en_una_sola_ficha(): void
    {
        $csv = self::CABECERA_VARIANTES
            ."SSD Kingston;Kingston;Capacidad: 500 GB;KC-500;220;;;12;Almacenamiento;Un SSD;\"Interfaz: NVMe\"\n"
            ."SSD Kingston;;Capacidad: 1 TB;KC-1T;340;;;5;;;\n"
            ."SSD Kingston;;Capacidad: 2 TB;KC-2T;610;;;0;;;\n";

        $respuesta = $this->importar($csv)->assertOk();

        $this->assertSame(1, $respuesta->json('success_count'));
        $this->assertSame([], $respuesta->json('errors'));

        $this->assertSame(1, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        $this->assertCount(3, $this->variantesDe($producto));
        // La ficha se describe con la PRIMERA fila del grupo.
        $this->assertSame('Kingston', $producto->brand);
        $this->assertSame('Un SSD', $producto->description);
        $this->assertSame(['Interfaz' => 'NVMe'], $producto->specs);
        $this->assertNotNull($producto->category_id);

        // Y el SKU de cada fila es el de SU variante, no el de la ficha.
        $this->assertNull($producto->sku);
        $this->assertSame(
            ['KC-500', 'KC-1T', 'KC-2T'],
            $this->variantesDe($producto)->pluck('sku')->values()->all(),
        );
        $this->assertSame(
            ['Capacidad'],
            collect($this->variantesDe($producto)->first()->options)->pluck('name')->unique()->values()->all(),
        );
    }

    public function test_el_precio_y_el_stock_de_la_ficha_son_el_resumen_de_sus_variantes(): void
    {
        $csv = self::CABECERA_VARIANTES
            ."RAM Corsair;Corsair;Capacidad: 16 GB;;300;;;4;;;\n"
            ."RAM Corsair;;Capacidad: 8 GB;;180;150;;6;;;\n"
            ."RAM Corsair;;Capacidad: 32 GB;;700;;;0;;;\n";

        $this->importar($csv)->assertOk();

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        // La mas barata es la de 8 GB por su OFERTA (150), no por su precio.
        $this->assertSame('180.00', $producto->price);
        $this->assertSame('150.00', $producto->sale_price);
        $this->assertSame(10, $producto->stock);
    }

    public function test_sin_columna_variante_cada_fila_sigue_siendo_un_producto(): void
    {
        // Dos filas con el MISMO nombre y sin columna `variante`: dos fichas,
        // igual que antes de MOD-12.
        $csv = self::PLANTILLA_CABECERA
            ."Cable HDMI;Generico;;20;;30;Cables;;\n"
            ."Cable HDMI;Generico;;25;;10;Cables;;\n";

        $respuesta = $this->importar($csv)->assertOk();

        $this->assertSame(2, $respuesta->json('success_count'));
        $this->assertSame(2, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, ProductVariant::withoutTenant()->count());
    }

    public function test_una_fila_sin_variante_no_se_mezcla_con_un_grupo_del_mismo_nombre(): void
    {
        $csv = self::CABECERA_VARIANTES
            ."Teclado;Logitech;;TEC-1;90;;;30;Perifericos;;\n"
            ."Teclado;Logitech;Color: Negro;TEC-N;95;;;5;Perifericos;;\n"
            ."Teclado;;Color: Blanco;TEC-B;95;;;3;;;\n";

        $respuesta = $this->importar($csv)->assertOk();

        $this->assertSame(2, $respuesta->json('success_count'));

        $sueltoSinVariantes = Product::withoutTenant()
            ->where('tenant_id', $this->tenant->id)
            ->where('sku', 'TEC-1')
            ->first();

        $this->assertNotNull($sueltoSinVariantes);
        $this->assertCount(0, $this->variantesDe($sueltoSinVariantes));
        $this->assertSame(2, ProductVariant::withoutTenant()->count());
    }

    public function test_una_variante_repetida_dentro_del_mismo_producto_se_rechaza(): void
    {
        $csv = self::CABECERA_VARIANTES
            ."Monitor;LG;Tamanio: 27 pulgadas;M-27;900;;;2;;;\n"
            ."Monitor;;tamanio: 27 PULGADAS;M-27B;950;;;1;;;\n";

        $respuesta = $this->importar($csv)->assertOk();

        $this->assertSame(1, $respuesta->json('success_count'));
        $this->assertStringContainsString('mismas opciones', implode(' ', $respuesta->json('errors')));

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();
        $this->assertCount(1, $this->variantesDe($producto));
    }

    public function test_mas_de_tres_opciones_en_una_variante_se_rechazan(): void
    {
        $csv = self::CABECERA_VARIANTES
            ."Silla;Uno;A: 1 | B: 2 | C: 3 | D: 4;;100;;;1;;;\n";

        $respuesta = $this->importar($csv)->assertOk();

        $this->assertSame(0, $respuesta->json('success_count'));
        $this->assertStringContainsString('como mucho 3 opciones', implode(' ', $respuesta->json('errors')));
    }

    public function test_una_variante_sin_nombre_de_eje_se_llama_variante(): void
    {
        $csv = self::CABECERA_VARIANTES
            ."Pasta termica;MX;1 g;PT-1;15;;;20;;;\n";

        $this->importar($csv)->assertOk();

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        $this->assertSame(
            [['name' => 'Variante', 'value' => '1 g']],
            $this->variantesDe($producto)->first()->options,
        );
    }

    public function test_el_tope_del_plan_cuenta_fichas_y_no_filas(): void
    {
        // `pro` es el plan mas estrecho que ademas incluye el import; su tope
        // real son 500 productos, que como archivo de prueba no aporta nada.
        config(['plans.plans.pro.limits.products' => 2]);
        $this->tenant->update(['plan' => 'pro']);
        $tope = 2;

        // Una sola ficha con mas variantes que el tope entero: si el import
        // contara filas, cortaria a la segunda.
        $filas = '';
        for ($i = 1; $i <= $tope + 3; $i++) {
            $filas .= "Kit;Marca;Pieza: {$i};K-{$i};10;;;1;;;\n";
        }

        $respuesta = $this->importar(self::CABECERA_VARIANTES.$filas)->assertOk();

        $this->assertSame(1, $respuesta->json('success_count'));
        $this->assertSame(1, Product::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_un_producto_no_admite_mas_variantes_que_su_maximo(): void
    {
        $filas = '';
        for ($i = 1; $i <= ProductVariant::MAXIMO_POR_PRODUCTO + 2; $i++) {
            $filas .= "Tornillos;Marca;Medida: {$i} mm;T-{$i};2;;;100;;;\n";
        }

        $respuesta = $this->importar(self::CABECERA_VARIANTES.$filas)->assertOk();

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        $this->assertCount(ProductVariant::MAXIMO_POR_PRODUCTO, $this->variantesDe($producto));
        $this->assertStringContainsString('maximo por producto', $this->sinAcentos(implode(' ', $respuesta->json('errors'))));
    }

    public function test_el_costo_de_una_variante_solo_entra_si_lo_importa_un_admin(): void
    {
        $vendedor = new User([
            'name'      => 'Vendedor',
            'email'     => 'vendedor@tienda-a.com',
            'password'  => 'password123',
            'role'      => 'staff',
            'is_active' => true,
        ]);
        $vendedor->tenant_id = $this->tenant->id;
        $vendedor->save();

        $csv = self::CABECERA_VARIANTES."Fuente;EVGA;Potencia: 650 W;F-650;250;;180;7;;;\n";

        // FUN-4: staff no importa. El costo, por tanto, solo puede entrar por un admin.
        $token = $vendedor->createToken('test', ['staff'])->plainTextToken;
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ])->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $csv),
        ])->assertForbidden();

        // Sin esto la peticion siguiente reutiliza el usuario ya resuelto por el
        // guard y el admin entraria como el vendedor.
        $this->app['auth']->forgetGuards();

        $this->importar($csv)->assertOk();

        $producto = Product::withoutTenant()->where('tenant_id', $this->tenant->id)->first();

        $this->assertSame('180.00', $this->variantesDe($producto)->first()->cost);
        // El costo de la ficha es el de la variante mas barata (MOD-6).
        $this->assertSame('180.00', $producto->cost);
    }

    // ---------------------------------------------------------------- FUN-17
    //
    // Lo que ya existe. Antes el import solo sabia crear: subir dos veces el
    // mismo archivo duplicaba el catalogo. Ahora el dueño elige, y por defecto
    // se omite.

    public function test_por_defecto_omite_lo_que_ya_existe_y_crea_lo_que_falta(): void
    {
        $this->existente('Intel Core i7-14700K', ['price' => 300]);

        $respuesta = $this->importar(self::PLANTILLA_CABECERA.self::PLANTILLA_FILA.self::PLANTILLA_FILAS_VARIANTE)->assertOk();

        $respuesta->assertJsonPath('created_count', 1);
        $respuesta->assertJsonPath('skipped_count', 1);
        $respuesta->assertJsonPath('updated_count', 0);
        $respuesta->assertJsonPath('errors', []);
        $this->assertStringContainsString('ya existía', $respuesta->json('message'));

        $productos = $this->productosDeLaTienda();
        $this->assertCount(2, $productos);
        // El que ya estaba, intacto.
        $this->assertSame('300.00', $productos->firstWhere('name', 'Intel Core i7-14700K')->price);
    }

    public function test_subir_dos_veces_el_mismo_archivo_no_cambia_nada_la_segunda(): void
    {
        $archivo = self::PLANTILLA_CABECERA.self::PLANTILLA_FILA.self::PLANTILLA_FILAS_VARIANTE;

        $this->importar($archivo)->assertOk()->assertJsonPath('created_count', 2);
        $this->app['auth']->forgetGuards();
        $this->importar($archivo)->assertOk()->assertJsonPath('created_count', 0)->assertJsonPath('skipped_count', 2);

        $this->assertCount(2, $this->productosDeLaTienda());
        $this->assertSame(2, ProductVariant::withoutTenant()->count());
    }

    public function test_el_producto_se_reconoce_sin_mirar_mayusculas_tildes_ni_espacios(): void
    {
        $this->existente('Tarjeta Gráfica RTX 4060');

        $this->importar("nombre;precio;stock\n  tarjeta  grafica rtx 4060 ;100;5\n")
            ->assertOk()
            ->assertJsonPath('skipped_count', 1);

        $this->assertCount(1, $this->productosDeLaTienda());
    }

    public function test_duplicar_crea_otro_igual_como_antes(): void
    {
        $this->existente('Intel Core i7-14700K');

        $this->importarEnModo(self::PLANTILLA_CABECERA.self::PLANTILLA_FILA, 'duplicar')
            ->assertOk()
            ->assertJsonPath('created_count', 1);

        $this->assertCount(2, $this->productosDeLaTienda()->where('name', 'Intel Core i7-14700K'));
    }

    public function test_un_modo_que_no_existe_se_rechaza(): void
    {
        // "Sumar el stock" se dejo fuera a proposito: subir dos veces el mismo
        // archivo lo duplicaria, que es justo el fallo de FUN-17.
        $this->importarEnModo(self::PLANTILLA_CABECERA.self::PLANTILLA_FILA, 'sumar')
            ->assertStatus(422)
            ->assertJsonValidationErrors('modo');

        $this->assertCount(0, $this->productosDeLaTienda());
    }

    public function test_actualizar_pone_los_datos_del_archivo_y_una_celda_vacia_no_borra(): void
    {
        $procesadores = \App\Models\Category::withoutTenant()->where('name', 'Procesadores')->firstOrFail();

        $this->existente('Ryzen 5 7600', [
            'brand'       => 'AMD',
            'sku'         => 'R5-OLD',
            'price'       => 900,
            'stock'       => 1,
            'description' => 'La descripcion que ya tenia',
            'specs'       => ['Socket' => 'AM5'],
        ]);

        // Marca y descripcion vacias; el resto, con datos nuevos.
        $csv = self::CABECERA_VARIANTES."Ryzen 5 7600;;;R5-NEW;950;899;;12;Procesadores;;\"TDP: 65W\"\n";

        $this->importarEnModo($csv, 'actualizar')
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('errors', []);

        $productos = $this->productosDeLaTienda();
        $this->assertCount(1, $productos);

        $producto = $productos->first();
        $this->assertSame('950.00', $producto->price);
        $this->assertSame('899.00', $producto->sale_price);
        $this->assertSame(12, $producto->stock);
        $this->assertSame('R5-NEW', $producto->sku);
        $this->assertSame($procesadores->id, $producto->category_id);
        $this->assertSame(['TDP' => '65W'], $producto->specs);
        // Lo que venia vacio se quedo como estaba.
        $this->assertSame('AMD', $producto->brand);
        $this->assertSame('La descripcion que ya tenia', $producto->description);
    }

    public function test_actualizar_rechaza_un_precio_que_deja_la_oferta_guardada_por_encima(): void
    {
        $this->existente('Fuente 650W', ['price' => 300, 'sale_price' => 250]);

        // Sin oferta en el archivo, se conserva la de 250: un precio de 200 la
        // dejaria por encima.
        $respuesta = $this->importarEnModo("nombre;precio;stock\nFuente 650W;200;5\n", 'actualizar')->assertOk();

        $respuesta->assertJsonPath('updated_count', 0);
        $this->assertStringContainsString('oferta', implode(' ', $respuesta->json('errors')));
        $this->assertSame('300.00', $this->productosDeLaTienda()->first()->price);
    }

    public function test_actualizar_con_variantes_las_empareja_por_opciones_y_anade_las_nuevas(): void
    {
        $producto = $this->existente('SSD Kingston', ['price' => 220, 'stock' => 12]);

        foreach ([['500 GB', 220, 'KC-500'], ['1 TB', 340, 'KC-1T']] as $orden => [$capacidad, $precio, $sku]) {
            $variante = new ProductVariant([
                'product_id' => $producto->id,
                'options'    => [['name' => 'Capacidad', 'value' => $capacidad]],
                'sku'        => $sku,
                'price'      => $precio,
                'stock'      => 5,
                'sort_order' => $orden,
            ]);
            $variante->tenant_id = $this->tenant->id;
            $variante->save();
        }

        // La de 1 TB cambia de precio (con las opciones escritas distinto), la
        // de 2 TB es nueva y la de 500 GB no se nombra.
        $csv = self::CABECERA_VARIANTES
            ."SSD Kingston;;capacidad: 1 tb;;360;;;7;;;\n"
            ."SSD Kingston;;Capacidad: 2 TB;KC-2T;610;;;2;;;\n";

        $this->importarEnModo($csv, 'actualizar')
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('errors', []);

        $variantes = $this->variantesDe($producto)->keyBy('sku');

        $this->assertCount(3, $variantes);
        $this->assertSame('220.00', $variantes['KC-500']->price, 'La variante que el archivo no nombra no se toca.');
        $this->assertSame('360.00', $variantes['KC-1T']->price);
        $this->assertSame(7, $variantes['KC-1T']->stock);
        $this->assertSame('610.00', $variantes['KC-2T']->price);

        // Y el resumen de la ficha se rehace: la mas barata y el stock sumado.
        $ficha = $this->productosDeLaTienda()->first();
        $this->assertSame('220.00', $ficha->price);
        $this->assertSame(14, $ficha->stock);
    }

    public function test_actualizar_con_una_fila_suelta_un_producto_con_variantes_se_rechaza(): void
    {
        $producto = $this->existente('Monitor LG', ['price' => 900, 'stock' => 3]);

        $variante = new ProductVariant([
            'product_id' => $producto->id,
            'options'    => [['name' => 'Tamanio', 'value' => '27']],
            'price'      => 900,
            'stock'      => 3,
        ]);
        $variante->tenant_id = $this->tenant->id;
        $variante->save();

        $respuesta = $this->importarEnModo("nombre;precio;stock\nMonitor LG;50;99\n", 'actualizar')->assertOk();

        $respuesta->assertJsonPath('updated_count', 0);
        $this->assertStringContainsString('tiene variantes', implode(' ', $respuesta->json('errors')));
        $this->assertSame('900.00', $this->variantesDe($producto)->first()->price);
    }

    public function test_actualizar_no_toca_nada_si_hay_dos_productos_con_ese_nombre(): void
    {
        $this->existente('Cable HDMI', ['price' => 20]);
        $this->existente('cable hdmi', ['price' => 25]);

        $respuesta = $this->importarEnModo("nombre;precio;stock\nCable HDMI;99;9\n", 'actualizar')->assertOk();

        $respuesta->assertJsonPath('updated_count', 0);
        $this->assertStringContainsString('no se sabe cuál actualizar', implode(' ', $respuesta->json('errors')));
        $this->assertEqualsCanonicalizing(['20.00', '25.00'], $this->productosDeLaTienda()->pluck('price')->all());
    }

    // ---------------------------------------------------------------- FUN-19
    //
    // El informe: que diga que paso con cada producto, igual que dice que fila
    // fallo y por que.

    public function test_el_informe_lista_cada_producto_en_el_orden_del_archivo(): void
    {
        $this->existente('Intel Core i7-14700K');

        $respuesta = $this->importar(self::PLANTILLA_CABECERA.self::PLANTILLA_FILA.self::PLANTILLA_FILAS_VARIANTE)->assertOk();

        $this->assertSame([
            ['fila' => 2, 'accion' => 'omitido', 'producto' => 'Intel Core i7-14700K', 'detalle' => null],
            ['fila' => 3, 'accion' => 'creado', 'producto' => 'Kingston NV3', 'detalle' => 'con 2 variantes'],
        ], $respuesta->json('changes'));
    }

    public function test_el_informe_dice_que_cambio_al_actualizar(): void
    {
        $this->categoria('Memorias', 'ram');
        $this->existente('Ryzen 5 7600', [
            'price'       => 900,
            'stock'       => 1,
            'description' => 'La de antes',
            'category_id' => \App\Models\Category::withoutTenant()->where('name', 'Memorias')->value('id'),
        ]);

        $csv = self::CABECERA_VARIANTES."Ryzen 5 7600;;;;950;;;12;Procesadores;La nueva;\n";

        $respuesta = $this->importarEnModo($csv, 'actualizar')->assertOk();

        $cambio = $respuesta->json('changes.0');
        $this->assertSame('actualizado', $cambio['accion']);
        $this->assertSame(
            'precio 900 → 950, stock 1 → 12, categoría Memorias → Procesadores, descripción',
            $cambio['detalle'],
        );
    }

    public function test_actualizar_con_los_mismos_datos_no_cuenta_como_actualizado(): void
    {
        $this->existente('Fuente 650W', ['price' => 300, 'stock' => 5]);

        $respuesta = $this->importarEnModo("nombre;precio;stock\nfuente 650w;300.00;5\n", 'actualizar')->assertOk();

        $respuesta->assertJsonPath('updated_count', 0);
        $respuesta->assertJsonPath('unchanged_count', 1);
        $respuesta->assertJsonPath('changes.0.accion', 'sin_cambios');
        $this->assertStringContainsString('ya estaba al día', $respuesta->json('message'));
    }

    public function test_el_informe_nombra_las_variantes_que_cambian_y_las_nuevas(): void
    {
        $producto = $this->existente('SSD Kingston', ['price' => 220, 'stock' => 5]);

        $variante = new ProductVariant([
            'product_id' => $producto->id,
            'options'    => [['name' => 'Capacidad', 'value' => '1 TB']],
            'price'      => 340,
            'stock'      => 5,
        ]);
        $variante->tenant_id = $this->tenant->id;
        $variante->save();

        $csv = self::CABECERA_VARIANTES
            ."SSD Kingston;;Capacidad: 1 TB;;360;;;7;;;\n"
            ."SSD Kingston;;Capacidad: 2 TB;;610;;;2;;;\n";

        $respuesta = $this->importarEnModo($csv, 'actualizar')->assertOk();

        $this->assertSame(
            'variante Capacidad: 1 TB (precio 340 → 360, stock 5 → 7); variante nueva Capacidad: 2 TB',
            $respuesta->json('changes.0.detalle'),
        );
    }

    public function test_lo_que_cambio_queda_guardado_con_la_linea_de_actividad(): void
    {
        // INF-3: un precio cambiado a mano deja su "de → a" en la actividad; el
        // mismo cambio por CSV solo dejaba una cifra.
        $this->existente('Fuente 650W', ['price' => 300, 'stock' => 5]);

        $this->importarEnModo("nombre;precio;stock\nFuente 650W;280;5\n", 'actualizar')->assertOk();

        $linea = \App\Models\ActivityLog::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('action', \App\Models\ActivityLog::PRODUCTO_IMPORTADOS)
            ->firstOrFail();

        $this->assertSame('precio 300 → 280', $linea->context['cambios'][0]['detalle']);
    }

    public function test_un_producto_en_la_papelera_no_cuenta_como_que_ya_existe(): void
    {
        $this->existente('Intel Core i7-14700K')->delete();

        $this->importar(self::PLANTILLA_CABECERA.self::PLANTILLA_FILA)
            ->assertOk()
            ->assertJsonPath('created_count', 1);
    }

    // ---------------------------------------------------------------- FUN-18
    //
    // Categorias. El import creaba las que no existian, y asi una tienda
    // acababa con "Procesadores" y "Processors": la segunda sin tipo de
    // componente, invisible para el armador.

    public function test_una_categoria_que_no_existe_rechaza_la_fila_y_no_se_crea(): void
    {
        $antes = \App\Models\Category::withoutTenant()->where('tenant_id', $this->tenant->id)->count();

        $respuesta = $this->importar(
            self::PLANTILLA_CABECERA
            .'Intel Core i9;Intel;;600;;3;Processors;;'."\n"
            .'Intel Core i5;Intel;;300;;3;Procesadores;;'."\n"
        )->assertOk();

        $respuesta->assertJsonPath('created_count', 1);
        $this->assertStringContainsString('«Processors» no existe', implode(' ', $respuesta->json('errors')));

        $this->assertSame($antes, \App\Models\Category::withoutTenant()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(['Intel Core i5'], $this->productosDeLaTienda()->pluck('name')->all());
    }

    public function test_la_categoria_se_reconoce_sin_mayusculas_ni_tildes(): void
    {
        $graficas = $this->categoria('Tarjetas gráficas', 'gpu');

        $this->importar(self::PLANTILLA_CABECERA."RTX 4060;NVIDIA;;1200;;2;TARJETAS GRAFICAS;;\n")
            ->assertOk()
            ->assertJsonPath('errors', []);

        $this->assertSame($graficas->id, $this->productosDeLaTienda()->first()->category_id);
    }

    public function test_sin_categoria_el_producto_entra_sin_clasificar_como_siempre(): void
    {
        $this->importar("nombre;precio;stock\nPasta termica;15;20\n")
            ->assertOk()
            ->assertJsonPath('created_count', 1);

        $this->assertNull($this->productosDeLaTienda()->first()->category_id);
    }

    public function test_si_la_primera_fila_de_un_grupo_tiene_una_categoria_que_no_existe_no_entra_ninguna(): void
    {
        // Sin esto, la segunda fila pasaria a describir la ficha y el producto
        // entraria sin categoria, que es el mismo error escondido.
        $csv = self::CABECERA_VARIANTES
            ."SSD Kingston;Kingston;Capacidad: 500 GB;;220;;;12;Storage;;\n"
            ."SSD Kingston;;Capacidad: 1 TB;;340;;;5;;;\n";

        $respuesta = $this->importar($csv)->assertOk();

        $respuesta->assertJsonPath('created_count', 0);
        $this->assertCount(2, $respuesta->json('errors'));
        $this->assertCount(0, $this->productosDeLaTienda());
    }

    /** Para comparar mensajes sin depender de como los escriba el controlador. */
    private function sinAcentos(string $texto): string
    {
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    /**
     * Las variantes de un producto leidas fuera de la peticion.
     *
     * `$producto->variants` a secas sale vacio: terminada la peticion el
     * middleware ya olvido la tienda y el scope de `BelongsToTenant` falla en
     * cerrado (AUD-4).
     *
     * @return \Illuminate\Support\Collection<int, ProductVariant>
     */
    private function variantesDe(Product $producto): \Illuminate\Support\Collection
    {
        return ProductVariant::withoutTenant()
            ->where('product_id', $producto->id)
            ->orderBy('sort_order')
            ->get();
    }
}
