<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\TeamRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Directorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * El directorio de clientes de la tienda (MOD-24).
 *
 * *Clientes* solo enseñaba las cuentas registradas y el checkout no pide
 * cuenta: una tienda que vende por WhatsApp veía la lista casi vacía. Lo que
 * fija este fichero es **quién se junta con quién** —lo delicado: juntar de más
 * mezcla a dos personas, juntar de menos parte a una en dos—, que lo que la
 * tienda escribe (notas y etiquetas) sea suyo y no salga de su panel, y el CSV
 * de ida y vuelta.
 */
class DirectorioDeClientesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $admin;

    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tienda = Tenant::create([
            'slug' => 'tienda-directorio', 'name' => 'Tienda Directorio',
            'whatsapp_number' => '51999999999', 'is_active' => true, 'is_published' => true,
            'plan' => 'enterprise',
        ]);

        $this->admin = $this->usuario('admin', 'duenia@directorio.test', 'Dueña');

        $this->producto = new Product(['name' => 'Mouse', 'price' => 50, 'stock' => 100, 'is_active' => true, 'status' => 'published']);
        $this->producto->tenant_id = $this->tienda->id;
        $this->producto->save();
    }

    // --- Quién se junta con quién ---------------------------------------------

    public function test_un_pedido_sin_cuenta_aparece_en_clientes(): void
    {
        $this->comprar('Ana Invitada', '+51 987 654 321');

        $this->comoAdmin()->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ana Invitada')
            ->assertJsonPath('data.0.phone', '+51 987 654 321')
            ->assertJsonPath('data.0.tiene_cuenta', false)
            ->assertJsonPath('data.0.pedidos_count', 1)
            ->assertJsonPath('data.0.total_gastado', 50);
    }

    public function test_el_mismo_telefono_escrito_distinto_es_la_misma_persona(): void
    {
        $this->comprar('Ana', '+51 987 654 321');
        // Venta de mostrador, sin prefijo y con guiones.
        $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => 'Ana P.', 'customer_phone' => '987-654-321', 'status' => 'attended',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 2]],
        ])->assertCreated();

        $lista = $this->comoAdmin()->getJson('/api/customers')->assertOk()->json('data');

        $this->assertCount(1, $lista);
        $this->assertSame(2, $lista[0]['pedidos_count']);
        // El nombre se queda el primero.
        $this->assertSame('Ana', $lista[0]['name']);
    }

    public function test_con_otro_telefono_pero_el_mismo_correo_tambien(): void
    {
        $this->comprar('Ana', '987654321', 'ana@correo.test');
        $this->comprar('Ana', '912345678', 'ANA@correo.test ');

        $this->assertSame(1, Customer::withoutTenant()->count());
    }

    public function test_quien_compro_como_invitado_y_despues_abre_cuenta_se_junta(): void
    {
        $this->comprar('Ana', '987654321', 'ana@correo.test');

        $this->postJson("/api/public/{$this->tienda->slug}/auth/register", [
            'name' => 'Ana Pérez', 'email' => 'ana@correo.test', 'password' => 'Secreta-1234',
            'password_confirmation' => 'Secreta-1234',
        ])->assertCreated();

        $lista = $this->comoAdmin()->getJson('/api/customers')->assertOk()->json('data');

        $this->assertCount(1, $lista);
        $this->assertTrue($lista[0]['tiene_cuenta']);
        $this->assertSame(1, $lista[0]['pedidos_count']);
    }

    public function test_dos_cuentas_con_el_mismo_telefono_no_se_juntan(): void
    {
        $this->usuario('customer', 'uno@correo.test', 'Uno', '987654321');
        $this->usuario('customer', 'dos@correo.test', 'Dos', '987654321');

        $this->assertSame(2, Customer::withoutTenant()->count());
    }

    public function test_una_venta_de_mostrador_sin_telefono_ni_correo_no_crea_a_nadie(): void
    {
        $id = $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente de mostrador', 'status' => 'attended',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');

        $this->assertSame(0, Customer::withoutTenant()->count());
        $this->assertNull(Order::withoutTenant()->find($id)->customer_id);
    }

    public function test_un_pedido_cancelado_o_en_la_papelera_no_cuenta(): void
    {
        $this->comprar('Ana', '987654321');
        $this->comprar('Ana', '987654321');
        $this->comprar('Ana', '987654321');

        $pedidos = Order::withoutTenant()->orderBy('created_at')->get();
        $pedidos[0]->forceFill(['status' => 'cancelled'])->save();
        $pedidos[1]->delete();

        $this->comoAdmin()->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.pedidos_count', 1)
            ->assertJsonPath('data.0.total_gastado', 50);
    }

    public function test_reconstruir_enlaza_lo_que_ya_habia_sin_duplicar(): void
    {
        $this->comprar('Ana', '987654321');
        $this->comprar('Beto', '912345678');
        $this->usuario('customer', 'carla@correo.test', 'Carla');

        // Como antes de MOD-24: pedidos sin persona y el directorio vacío.
        DB::table('orders')->update(['customer_id' => null]);
        DB::table('customers')->delete();

        Directorio::reconstruir($this->tienda->id);
        // Y otra vez, con el comando que lo repara: repetirlo no duplica.
        $this->artisan('clientes:reconstruir', ['slug' => $this->tienda->slug])->assertSuccessful();

        $this->assertSame(3, Customer::withoutTenant()->count());
        $this->assertSame(0, Order::withoutTenant()->whereNull('customer_id')->count());
    }

    // --- Lo que escribe la tienda ---------------------------------------------

    public function test_notas_y_etiquetas_se_guardan_limpias_y_la_bitacora_no_copia_la_nota(): void
    {
        $persona = $this->persona('Ana', '987654321');

        $this->comoAdmin()->putJson("/api/customers/{$persona->id}", [
            'notes' => '  Paga siempre por Yape, no fiarle  ',
            'tags' => [' mayorista ', 'Mayorista', 'frecuente', ''],
        ])->assertOk()
            ->assertJsonPath('customer.notes', 'Paga siempre por Yape, no fiarle')
            ->assertJsonPath('customer.tags', ['mayorista', 'frecuente']);

        $linea = ActivityLog::query()->where('action', ActivityLog::CLIENTE_EDITADO)->latest('id')->firstOrFail();
        $this->assertStringContainsString('las notas', $linea->description);
        $this->assertStringNotContainsString('fiarle', $linea->description);
    }

    public function test_se_filtra_por_etiqueta_y_por_cuenta_y_se_listan_las_etiquetas(): void
    {
        $this->persona('Ana', '987654321')->forceFill(['tags' => ['mayorista']])->save();
        $this->persona('Beto', '912345678')->forceFill(['tags' => ['frecuente', 'Mayorista']])->save();
        $this->usuario('customer', 'carla@correo.test', 'Carla');

        $nombres = fn (string $filtro) => collect($this->comoAdmin()->getJson("/api/customers?{$filtro}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Ana'], $nombres('etiqueta=mayorista'));
        $this->assertSame(['Carla'], $nombres('cuenta=con'));
        $this->assertSame(['Ana', 'Beto'], $nombres('cuenta=sin'));
        $this->comoAdmin()->getJson('/api/customers/etiquetas')->assertOk()->assertExactJson(['frecuente', 'mayorista']);
    }

    public function test_sin_el_permiso_de_gestionar_no_se_escriben_notas(): void
    {
        $persona = $this->persona('Ana', '987654321');
        // «Colaborador» ve los clientes, pero no los gestiona ni los exporta.
        $vendedor = $this->usuario('staff', 'vendedor@directorio.test', 'Vendedor');

        $this->como($vendedor)->getJson('/api/customers')->assertOk();
        $this->como($vendedor)->putJson("/api/customers/{$persona->id}", ['notes' => 'x'])->assertForbidden();
        $this->como($vendedor)->get('/api/customers/export')->assertForbidden();
        $this->assertContains('clientes.ver', TeamRole::withoutTenant()->where('tenant_id', $this->tienda->id)->value('permissions'));
    }

    public function test_otra_tienda_no_ve_ni_toca_a_las_personas_de_esta(): void
    {
        $persona = $this->persona('Ana', '987654321');

        $otra = Tenant::create(['slug' => 'otra', 'name' => 'Otra', 'whatsapp_number' => '51988888888', 'is_active' => true]);
        $ajeno = new User(['name' => 'Ajeno', 'email' => 'ajeno@otra.test', 'password' => 'secret1234', 'role' => 'admin', 'is_active' => true]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        $this->app['auth']->forgetGuards();
        $cabeceras = ['Authorization' => 'Bearer '.$ajeno->createToken('t')->plainTextToken, 'X-Tenant' => 'otra'];

        $this->withHeaders($cabeceras)->getJson("/api/customers/{$persona->id}")->assertNotFound();
        $this->withHeaders($cabeceras)->putJson("/api/customers/{$persona->id}", ['notes' => 'x'])->assertNotFound();
        $this->assertSame([], $this->withHeaders($cabeceras)->getJson('/api/customers')->json('data'));
    }

    // --- CSV ------------------------------------------------------------------

    public function test_exportar_y_volver_a_importar_no_cambia_nada(): void
    {
        $this->comprar('Ana', '+51 987 654 321', 'ana@correo.test');
        $this->persona('Beto', '912345678')->forceFill(['tags' => ['mayorista', 'frecuente'], 'notes' => "Línea uno\nLínea dos"])->save();

        $csv = $this->comoAdmin()->get('/api/customers/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('nombre;telefono;correo;etiquetas;notas;cuenta;pedidos;total_gastado;ultima_compra', $csv);
        $this->assertStringContainsString('mayorista|frecuente', $csv);

        $omitir = $this->importar($csv)->assertOk();
        $this->assertSame([0, 2], [$omitir->json('created_count'), $omitir->json('skipped_count')]);

        $actualizar = $this->importar($csv, 'actualizar')->assertOk();
        $this->assertSame([0, 2], [$actualizar->json('updated_count'), $actualizar->json('unchanged_count')]);
        $this->assertSame("Línea uno\nLínea dos", Customer::withoutTenant()->where('name', 'Beto')->value('notes'));
        $this->assertSame(2, Customer::withoutTenant()->count());
    }

    public function test_importar_trae_a_los_que_no_estan_y_rechaza_la_fila_sin_contacto(): void
    {
        $csv = "nombre;telefono;correo;etiquetas;notas\n"
            ."Carla;+593 99 123 4567;;mayorista|frecuente;Le gusta el WhatsApp\n"
            ."Sin datos;;;;\n"
            ."Diego;;diego@correo.test;;\n";

        $respuesta = $this->importar($csv)->assertOk();

        $this->assertSame(2, $respuesta->json('created_count'));
        $this->assertStringContainsString('Fila 3', $respuesta->json('errors.0'));
        $carla = Customer::withoutTenant()->where('name', 'Carla')->firstOrFail();
        $this->assertSame(['mayorista', 'frecuente'], $carla->tags);
        $this->assertSame('Le gusta el WhatsApp', $carla->notes);
    }

    public function test_actualizar_con_celdas_vacias_no_borra_nada(): void
    {
        $persona = $this->persona('Ana', '987654321');
        $persona->forceFill(['notes' => 'Nota vieja', 'tags' => ['vip']])->save();

        $this->importar("nombre;telefono;etiquetas;notas\n;987654321;;\n", 'actualizar')->assertOk();

        $persona->refresh();
        $this->assertSame(['Ana', 'Nota vieja', ['vip']], [$persona->name, $persona->notes, $persona->tags]);
    }

    public function test_sin_el_csv_en_el_plan_no_se_importa(): void
    {
        $this->tienda->update(['plan' => 'basic']);

        $this->importar("nombre;telefono\nAna;987654321\n")->assertStatus(422);
        $this->assertSame(0, Customer::withoutTenant()->count());
    }

    // --- ayudantes ------------------------------------------------------------

    private function comprar(string $nombre, string $telefono, ?string $correo = null): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/public/{$this->tienda->slug}/orders", array_filter([
            'customer_name' => $nombre,
            'customer_phone' => $telefono,
            'customer_email' => $correo,
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ]))->assertCreated();
    }

    private function persona(string $nombre, string $telefono): Customer
    {
        return Directorio::dePersona($this->tienda->id, null, $nombre, $telefono, null);
    }

    private function importar(string $csv, string $modo = 'omitir'): \Illuminate\Testing\TestResponse
    {
        return $this->comoAdmin()->post('/api/customers/import', [
            'file' => UploadedFile::fake()->createWithContent('clientes.csv', $csv),
            'modo' => $modo,
        ], ['Accept' => 'application/json']);
    }

    private function usuario(string $rol, string $correo, string $nombre, ?string $telefono = null): User
    {
        $usuario = new User([
            'name' => $nombre, 'email' => $correo, 'phone' => $telefono, 'password' => 'secret1234',
            'role' => $rol, 'is_active' => true,
        ]);
        $usuario->tenant_id = $this->tienda->id;
        $usuario->save();

        return $usuario;
    }

    private function comoAdmin(): self
    {
        return $this->como($this->admin);
    }

    private function como(User $usuario): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }
}
