<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Stock opcional (MOD-22): un producto que no lleva la cuenta de sus unidades.
 *
 * Servicios, encargos y productos digitales no tienen stock, y hasta ahora había
 * que inventarse un 999. Con `track_stock = false` se pide siempre, no hay lista
 * de espera, atender no descuenta y no sale en el stock bajo. Lo que este
 * fichero fija son los sitios que leían `stock` por su cuenta, uno por uno: cada
 * uno es una forma de que un servicio salga «Agotado» o de que vender lo deje en
 * negativo.
 */
class StockOpcionalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $duenio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tienda = Tenant::create([
            'slug' => 'tienda-servicios', 'name' => 'Tienda Servicios',
            'whatsapp_number' => '51999999999', 'is_active' => true,
            // El import CSV es una función del plan (SAAS-3).
            'plan' => 'enterprise',
        ]);

        $this->duenio = new User([
            'name' => 'Duenio', 'email' => 'duenio@servicios.com',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->duenio->tenant_id = $this->tienda->id;
        $this->duenio->save();
    }

    private function comoDuenio(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->duenio->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }

    private function producto(array $atributos = []): Product
    {
        $producto = new Product($atributos + [
            'name' => 'Instalación de Windows', 'price' => 40, 'stock' => 0,
            'track_stock' => false, 'status' => 'published', 'is_active' => true,
        ]);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    private function variante(Product $producto, string $valor, int $stock): ProductVariant
    {
        $variante = new ProductVariant([
            'product_id' => $producto->id,
            'options' => [['name' => 'Tamaño', 'value' => $valor]],
            'price' => 30, 'stock' => $stock,
        ]);
        $variante->tenant_id = $this->tienda->id;
        $variante->save();

        return $variante;
    }

    // --- Formulario del panel -------------------------------------------------

    public function test_sin_control_de_stock_se_crea_sin_poner_el_stock(): void
    {
        // FormData: '0' y no false, y el stock vacío (llega como null).
        $respuesta = $this->comoDuenio()->post('/api/products', [
            'name' => 'Torta por encargo',
            'price' => '55',
            'stock' => '',
            'track_stock' => '0',
            'status' => 'published',
        ])->assertCreated();

        $respuesta->assertJsonPath('track_stock', false);
        $producto = Product::withoutTenant()->findOrFail($respuesta->json('id'));
        $this->assertFalse($producto->controlaStock());
        $this->assertSame(0, (int) $producto->stock);
    }

    public function test_con_control_de_stock_el_stock_sigue_siendo_obligatorio(): void
    {
        $this->comoDuenio()->post('/api/products', [
            'name' => 'RTX 4070', 'price' => '2800', 'stock' => '', 'track_stock' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('stock');

        // Sin el campo, como todo formulario de antes: lleva la cuenta.
        $this->comoDuenio()->post('/api/products', [
            'name' => 'RTX 4070', 'price' => '2800',
        ])->assertStatus(422)->assertJsonValidationErrors('stock');
    }

    public function test_editar_sin_mandar_el_stock_no_lo_toca(): void
    {
        $producto = $this->producto(['stock' => 7, 'track_stock' => true]);

        $this->comoDuenio()->put("/api/products/{$producto->id}", [
            'name' => $producto->name, 'price' => '40', 'track_stock' => '0',
        ])->assertOk()->assertJsonPath('track_stock', false);

        // Se guarda el que había: si se vuelve a encender, el dueño lo encuentra.
        $this->assertSame(7, (int) $producto->fresh()->stock);

        // Y ya apagado, editarlo sin stock no lo exige.
        $this->comoDuenio()->put("/api/products/{$producto->id}", [
            'name' => 'Instalación y limpieza', 'price' => '45',
        ])->assertOk();
    }

    public function test_las_variantes_sin_control_de_stock_no_piden_el_suyo(): void
    {
        $respuesta = $this->comoDuenio()->postJson('/api/products', [
            'name' => 'Polo estampado',
            'track_stock' => '0',
            'variants' => [
                ['options' => [['name' => 'Talla', 'value' => 'M']], 'price' => 30, 'stock' => null],
                ['options' => [['name' => 'Talla', 'value' => 'L']], 'price' => 32],
            ],
        ])->assertCreated();

        $variantes = ProductVariant::withoutTenant()->where('product_id', $respuesta->json('id'))->get();
        $this->assertCount(2, $variantes);
        $this->assertSame([0, 0], $variantes->pluck('stock')->map(fn ($s) => (int) $s)->all());

        // Con control, sí.
        $this->comoDuenio()->postJson('/api/products', [
            'name' => 'Memoria',
            'variants' => [['options' => [['name' => 'Capacidad', 'value' => '16 GB']], 'price' => 30]],
        ])->assertStatus(422)->assertJsonValidationErrors('variants.0.stock');
    }

    public function test_editar_una_variante_sin_stock_conserva_el_que_tenia(): void
    {
        $producto = $this->producto();
        $variante = $this->variante($producto, 'M', 4);

        $this->comoDuenio()->putJson("/api/products/{$producto->id}", [
            'name' => $producto->name,
            'variants' => [['id' => $variante->id, 'options' => [['name' => 'Tamaño', 'value' => 'M']], 'price' => 35, 'stock' => null]],
        ])->assertOk();

        $this->assertSame(4, (int) $variante->fresh()->stock);
        $this->assertSame('35.00', $variante->fresh()->price);
    }

    public function test_apagar_el_control_de_stock_queda_en_la_bitacora(): void
    {
        $producto = $this->producto(['track_stock' => true, 'stock' => 3]);

        $this->comoDuenio()->put("/api/products/{$producto->id}", [
            'name' => $producto->name, 'price' => '40', 'track_stock' => '0',
        ])->assertOk();

        $linea = ActivityLog::query()->where('action', ActivityLog::PRODUCTO_EDITADO)->latest('id')->firstOrFail();
        $this->assertStringContainsString('control de stock sí → no', $linea->description);
    }

    // --- Vender ---------------------------------------------------------------

    public function test_atender_un_pedido_no_descuenta_lo_que_no_lleva_la_cuenta(): void
    {
        $servicio = $this->producto(['stock' => 0]);
        $polo = $this->producto(['name' => 'Polo a medida', 'price' => 30]);
        $talla = $this->variante($polo, 'M', 0);
        // Uno que sí lleva la cuenta, en el mismo pedido, para ver que ese sí baja.
        $memoria = $this->producto(['name' => 'Memoria', 'price' => 50, 'stock' => 5, 'track_stock' => true]);

        $id = $this->comoDuenio()->postJson('/api/orders', [
            'customer_name' => 'Cliente',
            'status' => 'attended',
            'items' => [
                ['product_id' => $servicio->id, 'quantity' => 3],
                ['product_id' => $polo->id, 'variant_id' => $talla->id, 'quantity' => 2],
                ['product_id' => $memoria->id, 'quantity' => 1],
            ],
        ])->assertCreated()->json('id');

        $this->assertSame(0, (int) $servicio->fresh()->stock);
        $this->assertSame(0, (int) $talla->fresh()->stock);
        $this->assertSame(4, (int) $memoria->fresh()->stock);

        // Y cancelarlo tampoco suma nada a lo que no lleva la cuenta.
        $this->comoDuenio()->putJson("/api/orders/{$id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(0, (int) $servicio->fresh()->stock);
        $this->assertSame(0, (int) $talla->fresh()->stock);
        $this->assertSame(5, (int) $memoria->fresh()->stock);
    }

    public function test_el_checkout_publico_acepta_lo_que_no_lleva_la_cuenta_aunque_su_numero_sea_0(): void
    {
        $servicio = $this->producto(['stock' => 0]);

        $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Comprador',
            'customer_phone' => '987654321',
            'items' => [['product_id' => $servicio->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertSame(1, Order::withoutTenant()->where('tenant_id', $this->tienda->id)->count());
    }

    // --- Catálogo público -----------------------------------------------------

    public function test_solo_en_stock_incluye_lo_que_no_lleva_la_cuenta(): void
    {
        $this->producto(['name' => 'Servicio técnico', 'stock' => 0]);
        $this->producto(['name' => 'RTX agotada', 'stock' => 0, 'track_stock' => true]);
        $this->producto(['name' => 'RTX con stock', 'stock' => 2, 'track_stock' => true]);

        $nombres = collect($this->getJson("/api/public/{$this->tienda->slug}/products?in_stock=1")
            ->assertOk()
            ->json('data'))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['RTX con stock', 'Servicio técnico'], $nombres);
    }

    public function test_la_ficha_publica_dice_que_se_puede_pedir(): void
    {
        $servicio = $this->producto(['stock' => 0]);

        $this->getJson("/api/public/{$this->tienda->slug}/products/{$servicio->id}")
            ->assertOk()
            ->assertJsonPath('track_stock', false);
    }

    public function test_no_hay_lista_de_espera_para_lo_que_no_se_agota(): void
    {
        $servicio = $this->producto(['stock' => 0]);

        $this->postJson("/api/public/{$this->tienda->slug}/products/{$servicio->id}/notify-me", [
            'customer_name' => 'Comprador',
            'customer_contact' => 'comprador@ejemplo.com',
        ])->assertStatus(422);

        $this->assertSame(0, StockNotification::withoutTenant()->count());
    }

    public function test_apagar_el_control_avisa_a_quien_esperaba_la_ficha_y_sus_variantes(): void
    {
        $agotado = $this->producto(['name' => 'Polo', 'stock' => 0, 'track_stock' => true]);
        $talla = $this->variante($agotado, 'M', 0);

        foreach ([['a@ejemplo.com', null], ['b@ejemplo.com', $talla->id], ['987654321', $talla->id]] as [$contacto, $varianteId]) {
            $espera = new StockNotification([
                'product_id' => $agotado->id, 'variant_id' => $varianteId,
                'customer_name' => 'Cliente', 'customer_contact' => $contacto,
            ]);
            $espera->tenant_id = $this->tienda->id;
            $espera->save();
        }

        $agotado->update(['track_stock' => false]);

        // Los dos correos, y el teléfono sigue pendiente para el WhatsApp del dueño.
        Notification::assertSentTimes(BackInStockNotification::class, 2);
        $pendientes = StockNotification::withoutTenant()->whereNull('notified_at')->pluck('customer_contact')->all();
        $this->assertSame(['987654321'], $pendientes);

        // El correo no dice que «quedan pocas»: no hay unidades que contar.
        Notification::assertSentTo(
            new AnonymousNotifiable,
            BackInStockNotification::class,
            function (BackInStockNotification $aviso, array $canales, AnonymousNotifiable $destino) {
                $lineas = implode(' ', $aviso->toMail($destino)->introLines);

                return ! str_contains($lineas, 'Quedan pocas');
            },
        );
    }

    // --- Reportes, SEO --------------------------------------------------------

    public function test_el_stock_bajo_no_lista_lo_que_no_lleva_la_cuenta(): void
    {
        $this->producto(['name' => 'Servicio', 'stock' => 0]);
        $conVariantes = $this->producto(['name' => 'Polo', 'stock' => 0]);
        $this->variante($conVariantes, 'M', 0);
        $this->producto(['name' => 'RTX', 'stock' => 1, 'track_stock' => true]);

        $nombres = collect($this->comoDuenio()->getJson('/api/reports')->assertOk()->json('stock_bajo'))
            ->pluck('nombre')
            ->all();

        $this->assertSame(['RTX'], $nombres);
    }

    // --- CSV ------------------------------------------------------------------

    private function importar(string $csv, string $modo = 'omitir'): \Illuminate\Testing\TestResponse
    {
        return $this->comoDuenio()->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $csv),
            'modo' => $modo,
        ]);
    }

    public function test_exportar_escribe_la_columna_y_reimportar_la_conserva(): void
    {
        $this->producto(['name' => 'Servicio técnico']);
        $this->producto(['name' => 'RTX', 'stock' => 3, 'track_stock' => true]);

        $csv = $this->comoDuenio()->get('/api/products/export')->assertOk()->streamedContent();
        $lineas = array_map(fn ($l) => str_getcsv($l, ';'), array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv))));

        $cabecera = $lineas[0];
        $columna = array_search('controla_stock', $cabecera, true);
        $this->assertNotFalse($columna);

        $porNombre = collect(array_slice($lineas, 1))->mapWithKeys(fn ($fila) => [$fila[0] => $fila[$columna]]);
        $this->assertSame('no', $porNombre['Servicio técnico']);
        $this->assertSame('si', $porNombre['RTX']);

        // Borrar y reimportar el mismo archivo deja cada uno como estaba.
        Product::withoutTenant()->where('tenant_id', $this->tienda->id)->forceDelete();
        $this->importar($csv)->assertOk()->assertJsonPath('created_count', 2);

        $this->assertFalse(Product::withoutTenant()->where('name', 'Servicio técnico')->firstOrFail()->controlaStock());
        $this->assertTrue(Product::withoutTenant()->where('name', 'RTX')->firstOrFail()->controlaStock());
    }

    public function test_el_import_crea_sin_control_de_stock_con_el_stock_vacio(): void
    {
        $csv = "nombre;precio;stock;controla_stock\n"
            ."Torta;55;;no\n"
            ."Vela;3;;\n";

        $respuesta = $this->importar($csv)->assertOk();

        $this->assertSame(1, $respuesta->json('created_count'));
        $this->assertStringContainsString("Fila 3: El stock '' no es válido", implode(' ', $respuesta->json('errors')));

        $torta = Product::withoutTenant()->where('name', 'Torta')->firstOrFail();
        $this->assertFalse($torta->controlaStock());
        $this->assertSame(0, (int) $torta->stock);
    }

    public function test_el_import_con_variantes_sin_control_no_pide_el_stock_de_ninguna(): void
    {
        // Solo la primera fila del grupo describe la ficha (MOD-12).
        $csv = "nombre;variante;precio;stock;controla_stock\n"
            ."Polo;Talla: M;30;;no\n"
            ."Polo;Talla: L;32;;\n";

        $this->importar($csv)->assertOk()->assertJsonPath('created_count', 1)->assertJsonPath('errors', []);

        $polo = Product::withoutTenant()->where('name', 'Polo')->firstOrFail();
        $this->assertFalse($polo->controlaStock());
        $this->assertSame(2, ProductVariant::withoutTenant()->where('product_id', $polo->id)->count());
    }

    public function test_el_import_en_modo_actualizar_cambia_el_control_y_lo_cuenta(): void
    {
        $existente = $this->producto(['name' => 'Servicio', 'stock' => 2, 'track_stock' => true]);

        $respuesta = $this->importar("nombre;precio;stock;controla_stock\nServicio;40;;no\n", 'actualizar')->assertOk();

        $this->assertFalse($existente->fresh()->controlaStock());
        // Vacío no borra: el número se queda.
        $this->assertSame(2, (int) $existente->fresh()->stock);
        $this->assertSame('control de stock si → no', $respuesta->json('changes.0.detalle'));
    }

    public function test_un_valor_que_no_se_entiende_rechaza_la_fila(): void
    {
        $respuesta = $this->importar("nombre;precio;stock;controla_stock\nTorta;55;1;quizas\n")->assertOk();

        $this->assertSame(0, $respuesta->json('created_count'));
        $this->assertStringContainsString("controla_stock 'quizas' no es válido", $respuesta->json('errors.0'));
    }
}
