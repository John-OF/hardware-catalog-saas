<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La vista previa del import CSV (FUN-29).
 *
 * El import se aplicaba y el informe llegaba después: un archivo mal hecho ya
 * había creado o cambiado el catálogo cuando el dueño leía lo que pasó. La
 * vista previa corre el import entero —las mismas reglas, el mismo informe— y lo
 * deshace. Lo que fija este fichero es que **no deje rastro**: ni productos, ni
 * cambios, ni bitácora, ni avisos de «ya llegó».
 */
class VistaPreviaDelImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tienda = Tenant::create([
            'slug' => 'tienda-previa', 'name' => 'Tienda Previa',
            'whatsapp_number' => '51999999999', 'is_active' => true, 'plan' => 'enterprise',
        ]);

        $this->admin = new User(['name' => 'Dueña', 'email' => 'duenia@previa.test', 'password' => 'secret1234', 'role' => 'admin', 'is_active' => true]);
        $this->admin->tenant_id = $this->tienda->id;
        $this->admin->save();
    }

    private function importar(string $csv, string $modo = 'omitir', bool $vistaPrevia = true): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ])->post('/api/products/import', array_filter([
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $csv),
            'modo' => $modo,
            'vista_previa' => $vistaPrevia ? '1' : null,
        ]));
    }

    private function producto(array $atributos): Product
    {
        $producto = new Product($atributos + ['is_active' => true, 'status' => 'published']);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    public function test_la_vista_previa_dice_lo_que_crearia_y_no_crea_nada(): void
    {
        $respuesta = $this->importar("nombre;precio;stock\nMouse;50;3\nTeclado;80;2\n")->assertOk();

        $respuesta->assertJsonPath('preview', true)
            ->assertJsonPath('created_count', 2)
            ->assertJsonPath('changes.0.accion', 'creado')
            ->assertJsonPath('message', 'Vista previa: se crearían 2 productos. Todavía no se guardó nada.');

        $this->assertSame(0, Product::withoutTenant()->count());
        $this->assertSame(0, ActivityLog::query()->where('action', ActivityLog::PRODUCTO_IMPORTADOS)->count());
    }

    public function test_en_modo_actualizar_dice_que_cambiaria_y_no_cambia_nada(): void
    {
        $mouse = $this->producto(['name' => 'Mouse', 'price' => 50, 'stock' => 3]);

        $this->importar("nombre;precio;stock\nMouse;65;3\n", 'actualizar')
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('changes.0.detalle', 'precio 50 → 65');

        $this->assertSame('50.00', $mouse->fresh()->price);
    }

    public function test_una_reposicion_en_la_vista_previa_no_avisa_a_nadie(): void
    {
        // Con la cola de producción y sin fakes: el fake de notificaciones anota
        // el aviso al dispararse, sin pasar por la cola ni esperar al commit.
        config(['queue.default' => 'database']);

        $agotado = $this->producto(['name' => 'Mouse', 'price' => 50, 'stock' => 0]);
        $espera = new StockNotification(['product_id' => $agotado->id, 'customer_name' => 'Ana', 'customer_contact' => 'ana@correo.test']);
        $espera->tenant_id = $this->tienda->id;
        $espera->save();

        $this->importar("nombre;precio;stock\nMouse;50;5\n", 'actualizar')->assertOk()->assertJsonPath('updated_count', 1);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNull($espera->fresh()->notified_at);
        $this->assertSame(0, (int) $agotado->fresh()->stock);
    }

    public function test_el_aviso_de_ya_llego_espera_a_que_se_guarde_la_reposicion(): void
    {
        // Lo que hace que la vista previa no avise con ninguna cola: el aviso se
        // encola solo si la transacción que repuso el stock se confirma.
        $producto = $this->producto(['name' => 'Mouse', 'price' => 50, 'stock' => 1]);

        $this->assertTrue((new BackInStockNotification($producto, 'Ana'))->afterCommit);
    }

    public function test_devuelve_las_filas_rechazadas_como_llegaron_para_corregirlas(): void
    {
        $respuesta = $this->importar("Nombre;Precio;Stock\nMouse;50;3\nTeclado;caro;2\n;10;1\n")->assertOk();

        $respuesta->assertJsonPath('header', ['Nombre', 'Precio', 'Stock']);
        $filas = $respuesta->json('rejected_rows');

        $this->assertSame([3, 4], array_column($filas, 'fila'));
        $this->assertSame(['Teclado', 'caro', '2'], $filas[0]['celdas']);
        $this->assertStringContainsString("El precio 'caro' no es válido", $filas[0]['error']);
    }

    public function test_despues_de_la_vista_previa_el_import_de_verdad_hace_lo_mismo(): void
    {
        $csv = "nombre;precio;stock\nMouse;50;3\nTeclado;80;2\n";

        $previa = $this->importar($csv)->json();
        $real = $this->importar($csv, 'omitir', false)->assertOk()->json();

        $this->assertFalse($real['preview']);
        $this->assertSame($previa['changes'], $real['changes']);
        $this->assertSame(2, Product::withoutTenant()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLog::PRODUCTO_IMPORTADOS)->count());
    }
}
