<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * El rol limitado del panel, `staff` (segunda mitad de FUN-4).
 *
 * Staff lleva el día a día —pedidos, productos, reseñas, lista de espera— y el
 * admin decide cómo es la tienda: configuración, categorías, páginas, plan y
 * equipo. Dentro de lo que staff toca, lo que borra o cambia el catálogo de
 * golpe también es de admin. El reparto está en `routes/api.php`.
 *
 * Se prueba contra la API y no contra la interfaz a propósito: el panel esconde
 * los botones, pero quien tenga un token de staff puede llamar a cualquier ruta
 * a mano, así que la barrera que importa es esta.
 */
class StaffRoleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;
    private User $staff;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tienda = Tenant::create([
            'slug'            => 'tienda-staff',
            'name'            => 'Tienda Staff',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->crearUsuario('duenio@staff.test', 'admin');
        $this->staff = $this->crearUsuario('vendedor@staff.test', 'staff');

        $this->producto = new Product([
            'name' => 'Ryzen 7 7800X3D', 'price' => 1500, 'stock' => 10,
            'is_active' => true, 'status' => 'published',
        ]);
        $this->producto->tenant_id = $this->tienda->id;
        $this->producto->save();
    }

    // ------------------------------------------------------ lo que SÍ puede

    public function test_staff_entra_al_panel_con_su_contrasenia(): void
    {
        $this->postJson('/api/auth/login', [
            'email'    => 'vendedor@staff.test',
            'password' => 'secret1234',
        ])->assertOk()
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonPath('tenant.slug', 'tienda-staff');
    }

    public function test_staff_lleva_productos_pedidos_y_resenas(): void
    {
        $this->comoStaff()->getJson('/api/products')->assertOk();
        $this->comoStaff()->getJson('/api/dashboard/stats')->assertOk();
        $this->comoStaff()->getJson('/api/orders')->assertOk();
        $this->comoStaff()->getJson('/api/reviews')->assertOk();
        $this->comoStaff()->getJson('/api/stock-notifications')->assertOk();

        // Lee la tienda y el plan: el panel necesita la moneda, el slug y los topes.
        $this->comoStaff()->getJson('/api/tenant')->assertOk();
        $this->comoStaff()->getJson('/api/plan')->assertOk();

        // Lee las categorías, que es lo que pide el formulario de producto.
        $this->comoStaff()->getJson('/api/categories')->assertOk();
    }

    public function test_staff_crea_y_edita_productos(): void
    {
        $this->comoStaff()->postJson('/api/products', [
            'name' => 'RTX 4070', 'price' => 2800, 'stock' => 3,
        ])->assertCreated();

        $this->comoStaff()->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Ryzen 7 7800X3D', 'price' => 1450, 'stock' => 8,
        ])->assertOk();

        $this->assertEquals(1450, $this->producto->fresh()->price);
        $this->assertSame(8, $this->producto->fresh()->stock);
    }

    public function test_staff_registra_ventas_de_mostrador(): void
    {
        $this->comoStaff()->postJson('/api/orders', [
            'customer_name' => 'Cliente de mostrador',
            'status'        => 'attended',
            'items'         => [['product_id' => $this->producto->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertSame(8, $this->producto->fresh()->stock);
    }

    // ---------------------------------------------------- lo que NO puede

    public function test_staff_no_borra_ni_toca_el_catalogo_de_golpe(): void
    {
        $this->comoStaff()->deleteJson("/api/products/{$this->producto->id}")->assertForbidden();
        $this->comoStaff()->postJson('/api/products/bulk', [
            'ids' => [$this->producto->id], 'action' => 'delete',
        ])->assertForbidden();
        $this->comoStaff()->postJson('/api/products/import', [])->assertForbidden();

        $this->assertNotNull($this->producto->fresh());
    }

    public function test_staff_no_borra_pedidos(): void
    {
        $pedido = $this->comoStaff()->postJson('/api/orders', [
            'customer_name' => 'Cliente de mostrador',
            'status'        => 'attended',
            'items'         => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');

        // Borrar un pedido atendido devuelve su stock: es rehacer la contabilidad.
        $this->comoStaff()->deleteJson("/api/orders/{$pedido}")->assertForbidden();

        $this->assertSame(9, $this->producto->fresh()->stock);
    }

    public function test_staff_no_cambia_como_es_la_tienda(): void
    {
        $this->comoStaff()->putJson('/api/tenant', ['name' => 'Otra'])->assertForbidden();
        $this->assertSame('Tienda Staff', $this->tienda->fresh()->name);

        // Categorías: gobiernan el armador desde FUN-8.
        $this->comoStaff()->postJson('/api/categories', ['name' => 'Nueva'])->assertForbidden();

        $categoria = new Category(['name' => 'Procesadores']);
        $categoria->tenant_id = $this->tienda->id;
        $categoria->save();

        $this->comoStaff()->putJson("/api/categories/{$categoria->id}", ['name' => 'CPU'])->assertForbidden();
        $this->comoStaff()->deleteJson("/api/categories/{$categoria->id}")->assertForbidden();
        $this->comoStaff()->postJson('/api/categories/reorder', [])->assertForbidden();

        $this->comoStaff()->getJson('/api/pages')->assertForbidden();
    }

    public function test_staff_no_ve_ni_toca_el_equipo(): void
    {
        $this->comoStaff()->getJson('/api/users')->assertForbidden();
        $this->comoStaff()->postJson('/api/users', [
            'name' => 'Cómplice', 'email' => 'complice@staff.test', 'role' => 'admin',
        ])->assertForbidden();

        // Ni subirse a sí mismo a admin.
        $this->comoStaff()->putJson("/api/users/{$this->staff->id}", ['role' => 'admin'])->assertForbidden();

        $this->assertSame('staff', $this->staff->fresh()->role);
        $this->assertNull(User::where('email', 'complice@staff.test')->first());
    }

    /**
     * El rol se lee del usuario en cada petición, no de las abilities del token:
     * un token emitido cuando era admin no le deja seguir actuando como tal.
     */
    public function test_el_rol_manda_sobre_el_token(): void
    {
        $token = $this->staff->createToken('viejo', ['admin'])->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tienda->slug,
        ])->getJson('/api/users')->assertForbidden();
    }

    /**
     * La puerta del panel tiene que cerrar ANTES de resolver el {producto} de la
     * URL. Si no, un cliente de la tienda —cuyo token sí pasa el middleware de
     * tienda— recibía 404 para un id inexistente y 403 para uno real: el propio
     * error le decía qué productos y pedidos hay.
     */
    public function test_un_cliente_recibe_403_exista_o_no_el_recurso(): void
    {
        $cliente = $this->crearUsuario('comprador@staff.test', 'customer');
        $token = $cliente->createToken('test', ['customer'])->plainTextToken;

        $comoCliente = fn () => $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tienda->slug,
        ]);

        $comoCliente()->getJson("/api/products/{$this->producto->id}")->assertForbidden();
        $comoCliente()->getJson('/api/products/00000000-0000-0000-0000-000000000000')->assertForbidden();
        $comoCliente()->getJson('/api/orders/00000000-0000-0000-0000-000000000000')->assertForbidden();
    }

    public function test_staff_desactivado_no_entra(): void
    {
        $this->staff->update(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email'    => 'vendedor@staff.test',
            'password' => 'secret1234',
        ])->assertStatus(422);
    }

    // -------------------------------------------- correo y contraseña (FUN-14)

    public function test_staff_puede_recuperar_su_contrasenia(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'vendedor@staff.test'])->assertOk();

        Notification::assertSentTo($this->staff, ResetPasswordNotification::class);
    }

    /**
     * El registro rechaza un correo que ya es del panel de otra tienda, sea admin
     * o staff: el login no sabría a qué cuenta mandarlo.
     */
    public function test_no_se_puede_abrir_una_tienda_con_el_correo_de_un_staff(): void
    {
        $this->postJson('/api/auth/register', [
            'store_name'            => 'Mi Tienda Propia',
            'slug'                  => 'mi-tienda-propia',
            'whatsapp'              => '51977777777',
            'name'                  => 'Vendedor',
            'email'                 => 'vendedor@staff.test',
            'password'              => 'ClaveSegura#2026',
            'password_confirmation' => 'ClaveSegura#2026',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertNull(Tenant::where('slug', 'mi-tienda-propia')->first());
    }

    /**
     * Un dueño que recupera la contraseña antes de abrir el correo de
     * verificación ha demostrado lo mismo. Si solo se marcara el correo, su
     * tienda se quedaría cerrada sin aviso y sin forma de abrirla.
     */
    public function test_el_reset_de_un_admin_verifica_y_publica(): void
    {
        $this->tienda->update(['is_published' => false]);
        $duenio = User::where('email', 'duenio@staff.test')->firstOrFail();

        $token = Password::broker()->createToken($duenio);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'duenio@staff.test',
            'password'              => 'ClaveNueva#2026',
            'password_confirmation' => 'ClaveNueva#2026',
        ])->assertOk();

        $this->assertTrue($duenio->fresh()->hasVerifiedEmail());
        $this->assertTrue($this->tienda->fresh()->is_published);
    }

    // ------------------------------------------------------------ auxiliares

    private function comoStaff(): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->staff->createToken('test', ['staff'])->plainTextToken,
            'X-Tenant'      => $this->tienda->slug,
        ]);
    }

    private function crearUsuario(string $email, string $rol): User
    {
        $usuario = new User([
            'name'      => 'Usuario '.$email,
            'email'     => $email,
            'password'  => 'secret1234',
            'role'      => $rol,
            'is_active' => true,
        ]);
        $usuario->tenant_id = $this->tienda->id;
        $usuario->save();

        return $usuario;
    }
}
