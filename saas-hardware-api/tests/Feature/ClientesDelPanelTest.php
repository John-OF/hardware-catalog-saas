<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Los clientes de la tienda en el panel (MOD-10).
 *
 * Lo que fija:
 *
 * 1. **El equipo no es clientela.** Admin y staff viven en la misma tabla que los
 *    clientes; si el filtro por rol se cae, el dueño se ve a sí mismo en su lista
 *    de compradores.
 * 2. **Una tienda no ve los clientes de otra.** Es el aislamiento de siempre, y
 *    aquí importa el doble porque `User` es la excepción al fallo en cerrado.
 * 3. **Lo gastado es lo cobrado.** Un pedido cancelado no es dinero que entró:
 *    si contara, el "total gastado" no cuadraría con la caja.
 */
class ClientesDelPanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $staff;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tenant = Tenant::create([
            'slug' => 'tienda-clientes',
            'name' => 'Tienda Clientes',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
        ]);

        $this->admin = $this->usuario($this->tenant, 'admin', 'duenia@clientes.test');
        $this->staff = $this->usuario($this->tenant, 'staff', 'vendedor@clientes.test');
        $this->cliente = $this->usuario($this->tenant, 'customer', 'ana@correo.test', 'Ana Compradora');
    }

    public function test_lista_los_clientes_con_lo_que_han_comprado(): void
    {
        $this->pedidoDe($this->cliente, total: 500);
        $this->pedidoDe($this->cliente, total: 300);

        $this->comoAdmin()->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ana Compradora')
            ->assertJsonPath('data.0.email', 'ana@correo.test')
            ->assertJsonPath('data.0.pedidos_count', 2)
            ->assertJsonPath('data.0.total_gastado', 800);
    }

    public function test_el_equipo_no_aparece_como_clientela(): void
    {
        $respuesta = $this->comoAdmin()->getJson('/api/customers')->assertOk();

        $correos = collect($respuesta->json('data'))->pluck('email');

        $this->assertTrue($correos->contains('ana@correo.test'));
        $this->assertFalse($correos->contains('duenia@clientes.test'));
        $this->assertFalse($correos->contains('vendedor@clientes.test'));
    }

    public function test_un_pedido_cancelado_no_cuenta_como_gastado(): void
    {
        $this->pedidoDe($this->cliente, total: 500);
        $this->pedidoDe($this->cliente, total: 900, estado: 'cancelled');

        $this->comoAdmin()->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.pedidos_count', 1)
            ->assertJsonPath('data.0.total_gastado', 500);
    }

    public function test_un_cliente_sin_compras_sale_con_todo_a_cero(): void
    {
        $this->comoAdmin()->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.pedidos_count', 0)
            ->assertJsonPath('data.0.total_gastado', 0)
            ->assertJsonPath('data.0.ultima_compra', null);
    }

    public function test_no_se_ven_los_clientes_de_otra_tienda(): void
    {
        $otraTienda = Tenant::create([
            'slug' => 'tienda-ajena', 'name' => 'Tienda Ajena',
            'whatsapp_number' => '51988888888', 'is_active' => true, 'is_published' => true,
        ]);

        $ajeno = $this->usuario($otraTienda, 'customer', 'ajeno@correo.test', 'Cliente Ajeno');

        $respuesta = $this->comoAdmin()->getJson('/api/customers')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));

        $this->app['auth']->forgetGuards();
        $this->comoAdmin()->getJson("/api/customers/{$ajeno->id}")->assertNotFound();
    }

    public function test_se_pueden_buscar_por_nombre_o_correo(): void
    {
        $this->usuario($this->tenant, 'customer', 'beto@correo.test', 'Beto Comprador');

        $respuesta = $this->comoAdmin()->getJson('/api/customers?search=beto')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('Beto Comprador', $respuesta->json('data.0.name'));
    }

    public function test_se_pueden_ordenar_por_lo_que_gastaron(): void
    {
        $beto = $this->usuario($this->tenant, 'customer', 'beto@correo.test', 'Beto Comprador');

        $this->pedidoDe($this->cliente, total: 100);
        $this->pedidoDe($beto, total: 2000);

        $this->comoAdmin()->getJson('/api/customers?sort=gasto')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beto Comprador');
    }

    public function test_la_ficha_trae_el_historial_de_compras(): void
    {
        $pedido = $this->pedidoDe($this->cliente, total: 500);

        $this->comoAdmin()->getJson("/api/customers/{$this->cliente->id}")
            ->assertOk()
            ->assertJsonPath('customer.name', 'Ana Compradora')
            ->assertJsonPath('customer.pedidos_count', 1)
            ->assertJsonPath('orders.0.number', $pedido->number)
            ->assertJsonPath('orders.0.total', '500.00');
    }

    public function test_la_ficha_de_alguien_del_equipo_no_existe(): void
    {
        $this->comoAdmin()->getJson("/api/customers/{$this->staff->id}")->assertNotFound();
    }

    public function test_staff_tambien_puede_verlos(): void
    {
        $this->comoStaff()->getJson('/api/customers')->assertOk();
    }

    public function test_sin_sesion_no_se_ve_la_clientela(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
    }

    public function test_no_se_devuelve_la_contrasenia_del_cliente(): void
    {
        $respuesta = $this->comoAdmin()->getJson('/api/customers')->assertOk();

        $this->assertStringNotContainsString('password', $respuesta->getContent());
    }

    // ------------------------------------------------------------ ayudantes

    private function pedidoDe(User $cliente, float $total, string $estado = 'attended'): Order
    {
        $pedido = new Order([
            'tenant_id' => $this->tenant->id,
            'user_id' => $cliente->id,
            'customer_name' => $cliente->name,
            'customer_phone' => '987654321',
            'status' => $estado,
            'total' => $total,
        ]);
        $pedido->tenant_id = $this->tenant->id;
        $pedido->save();

        return $pedido;
    }

    private function usuario(Tenant $tienda, string $rol, string $correo, ?string $nombre = null): User
    {
        $usuario = new User([
            'name' => $nombre ?? ucfirst($rol), 'email' => $correo, 'password' => 'secret1234',
            'role' => $rol, 'is_active' => true,
        ]);
        $usuario->tenant_id = $tienda->id;
        $usuario->save();

        return $usuario;
    }

    private function comoAdmin(): self
    {
        return $this->conSesionDe($this->admin);
    }

    private function comoStaff(): self
    {
        return $this->conSesionDe($this->staff);
    }

    private function conSesionDe(User $usuario): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant' => $this->tenant->slug,
        ]);
    }
}
