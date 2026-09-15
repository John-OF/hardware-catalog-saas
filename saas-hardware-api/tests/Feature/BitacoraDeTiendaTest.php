<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\StockNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bitácora del panel de tienda (INF-3): quién cambió qué, y que solo lo vea quien debe.
 *
 * Lo que fija, por orden de lo que costaría romperlo:
 *
 * 1. **Cada ruta del panel que escribe está decidida**: o anota, o figura con su
 *    motivo en la lista de las que no. Una ruta nueva que no esté en ninguna de
 *    las dos pone este test en rojo (`test_toda_ruta_del_panel_que_escribe_esta_decidida`).
 * 2. **Aislamiento**: una tienda no ve la actividad de otra, ni lo que anotó el
 *    operador; y la bitácora del operador no se llena con la de las tiendas.
 * 3. Lo que dice cada línea: quién, y de qué a qué.
 * 4. Anotar nunca tumba la acción.
 */
class BitacoraDeTiendaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rutas del panel que escriben y NO anotan, con el porqué. Todas las demás
     * tienen que anotar algo, y los tests de abajo lo comprueban.
     */
    private const SIN_ANOTAR = [
        'POST api/auth/logout'         => 'cerrar sesión no cambia la tienda',
        'POST api/auth/email/resend'   => 'reenviar la verificación no cambia la tienda',
        'POST api/products/reorder'    => 'se guarda en cada arrastre: llenaría la bitácora de movimientos sin nada que auditar',
        'POST api/categories/reorder'  => 'mismo motivo que reordenar productos',
    ];

    /** Las que sí anotan: la ruta y la acción que deja. */
    private const ANOTAN = [
        'POST api/products'                            => ActivityLog::PRODUCTO_CREADO,
        'PUT api/products/{product}'                   => ActivityLog::PRODUCTO_EDITADO,
        'POST api/products/{product}/duplicate'        => ActivityLog::PRODUCTO_DUPLICADO,
        'DELETE api/products/{product}'                => ActivityLog::PRODUCTO_BORRADO,
        'POST api/products/import'                     => ActivityLog::PRODUCTO_IMPORTADOS,
        'POST api/products/bulk'                       => ActivityLog::PRODUCTO_LOTE,
        'POST api/orders'                              => ActivityLog::PEDIDO_MOSTRADOR,
        'PUT api/orders/{order}'                       => ActivityLog::PEDIDO_ESTADO,
        'DELETE api/orders/{order}'                    => ActivityLog::PEDIDO_BORRADO,
        'POST api/categories'                          => ActivityLog::CATEGORIA_CREADA,
        'PUT api/categories/{category}'                => ActivityLog::CATEGORIA_EDITADA,
        'DELETE api/categories/{category}'             => ActivityLog::CATEGORIA_BORRADA,
        'POST api/pages'                               => ActivityLog::PAGINA_CREADA,
        'PUT api/pages/{page}'                         => ActivityLog::PAGINA_EDITADA,
        'DELETE api/pages/{page}'                      => ActivityLog::PAGINA_BORRADA,
        'PUT api/reviews/{review}'                     => ActivityLog::RESENA_MODERADA,
        'DELETE api/reviews/{review}'                  => ActivityLog::RESENA_BORRADA,
        'PUT api/stock-notifications/{stock_notification}'    => ActivityLog::ESPERA_MARCADA,
        'DELETE api/stock-notifications/{stock_notification}' => ActivityLog::ESPERA_BORRADA,
        'POST api/users'                               => ActivityLog::EQUIPO_INVITADO,
        'PUT api/users/{user}'                         => ActivityLog::EQUIPO_EDITADO,
        'DELETE api/users/{user}'                      => ActivityLog::EQUIPO_BORRADO,
        'POST api/users/{user}/resend-invitation'      => ActivityLog::EQUIPO_REINVITADO,
        'PUT api/tenant'                               => ActivityLog::CONFIGURACION_EDITADA,
        'POST api/tenant/custom-domain/verify'         => ActivityLog::CONFIGURACION_DOMINIO,
    ];

    private Tenant $tienda;
    private User $admin;
    private User $staff;
    private Product $producto;
    private string $guardOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->guardOriginal = (string) config('auth.defaults.guard');

        $this->tienda = $this->crearTienda('tienda-bitacora', 'Tienda Bitácora');
        $this->admin  = $this->crearUsuario($this->tienda, 'duenia@bitacora.test', 'admin', 'Dueña');
        $this->staff  = $this->crearUsuario($this->tienda, 'vendedor@bitacora.test', 'staff', 'Vendedor');

        $this->producto = $this->crearProducto($this->tienda, 'Ryzen 7 7800X3D', 1500, 10);
    }

    // ------------------------------------------------------- cobertura de rutas

    public function test_toda_ruta_del_panel_que_escribe_esta_decidida(): void
    {
        $encontradas = [];

        foreach (Route::getRoutes() as $ruta) {
            if (! in_array('panel', $ruta->gatherMiddleware(), true)) {
                continue;
            }

            foreach (array_diff($ruta->methods(), ['GET', 'HEAD', 'PATCH']) as $metodo) {
                $encontradas[] = "{$metodo} {$ruta->uri()}";
            }
        }

        $decididas = array_merge(array_keys(self::ANOTAN), array_keys(self::SIN_ANOTAR));

        $this->assertSame(
            [],
            array_values(array_diff($encontradas, $decididas)),
            'Ruta del panel que escribe sin decidir si anota en la bitácora: añádela a ANOTAN (y pruébala) o a SIN_ANOTAR con su motivo.',
        );
        $this->assertSame([], array_values(array_diff($decididas, $encontradas)), 'Hay rutas en las listas que ya no existen.');
    }

    public function test_cada_ruta_que_anota_deja_su_linea(): void
    {
        $this->instance(DomainVerifier::class, new class extends DomainVerifier {
            public function tieneRegistroTxt(string $host, string $valorEsperado): bool
            {
                return true;
            }
        });

        $categoria = $this->crearCategoria('Procesadores');
        $pagina = $this->crearPagina('Garantía');
        $resena = $this->crearResena();
        $espera = $this->crearEspera();
        $companiero = $this->crearUsuario($this->tienda, 'otro@bitacora.test', 'staff', 'Otro');

        $pasos = [
            'POST api/products' => fn () => $this->como($this->admin)->postJson('/api/products', ['name' => 'RTX 4070', 'price' => 2800, 'stock' => 3]),
            'PUT api/products/{product}' => fn () => $this->como($this->admin)->putJson("/api/products/{$this->producto->id}", ['name' => 'Ryzen 7 7800X3D', 'price' => 1400, 'stock' => 10]),
            'POST api/products/{product}/duplicate' => fn () => $this->como($this->admin)->postJson("/api/products/{$this->producto->id}/duplicate"),
            'POST api/products/import' => fn () => $this->como($this->admin)->post('/api/products/import', [
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('p.csv', "nombre,precio,stock\nMouse,50,5\n"),
            ], ['Accept' => 'application/json']),
            'POST api/products/bulk' => fn () => $this->como($this->admin)->postJson('/api/products/bulk', ['product_ids' => [$this->producto->id], 'bulk_action' => 'deactivate']),
            'POST api/orders' => fn () => $this->como($this->admin)->postJson('/api/orders', [
                'customer_name' => 'Cliente', 'status' => 'pending', 'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
            ]),
            'PUT api/orders/{order}' => fn () => $this->como($this->admin)->putJson('/api/orders/'.$this->ultimoPedidoId(), ['status' => 'attended']),
            'DELETE api/orders/{order}' => fn () => $this->como($this->admin)->deleteJson('/api/orders/'.$this->ultimoPedidoId()),
            'POST api/categories' => fn () => $this->como($this->admin)->postJson('/api/categories', ['name' => 'Tarjetas gráficas']),
            'PUT api/categories/{category}' => fn () => $this->como($this->admin)->putJson("/api/categories/{$categoria->id}", ['name' => 'CPU']),
            'DELETE api/categories/{category}' => fn () => $this->como($this->admin)->deleteJson("/api/categories/{$categoria->id}"),
            'POST api/pages' => fn () => $this->como($this->admin)->postJson('/api/pages', ['title' => 'Envíos', 'slug' => 'envios']),
            'PUT api/pages/{page}' => fn () => $this->como($this->admin)->putJson("/api/pages/{$pagina->id}", ['title' => 'Garantía y devoluciones']),
            'DELETE api/pages/{page}' => fn () => $this->como($this->admin)->deleteJson("/api/pages/{$pagina->id}"),
            'PUT api/reviews/{review}' => fn () => $this->como($this->admin)->putJson("/api/reviews/{$resena->id}", ['is_approved' => true]),
            'DELETE api/reviews/{review}' => fn () => $this->como($this->admin)->deleteJson("/api/reviews/{$resena->id}"),
            'PUT api/stock-notifications/{stock_notification}' => fn () => $this->como($this->admin)->putJson("/api/stock-notifications/{$espera->id}", ['notified' => true]),
            'DELETE api/stock-notifications/{stock_notification}' => fn () => $this->como($this->admin)->deleteJson("/api/stock-notifications/{$espera->id}"),
            'POST api/users' => fn () => $this->como($this->admin)->postJson('/api/users', ['name' => 'Nueva', 'email' => 'nueva@bitacora.test']),
            'PUT api/users/{user}' => fn () => $this->como($this->admin)->putJson("/api/users/{$companiero->id}", ['role' => 'admin']),
            'POST api/users/{user}/resend-invitation' => fn () => $this->como($this->admin)->postJson("/api/users/{$companiero->id}/resend-invitation"),
            'DELETE api/users/{user}' => fn () => $this->como($this->admin)->deleteJson("/api/users/{$companiero->id}"),
            'PUT api/tenant' => fn () => $this->como($this->admin)->putJson('/api/tenant', ['name' => 'Tienda Renombrada', 'custom_domain' => 'tienda.example.com']),
            'POST api/tenant/custom-domain/verify' => fn () => $this->como($this->admin)->postJson('/api/tenant/custom-domain/verify'),
            'DELETE api/products/{product}' => fn () => $this->como($this->admin)->deleteJson("/api/products/{$this->producto->id}"),
        ];

        $this->assertEqualsCanonicalizing(array_keys(self::ANOTAN), array_keys($pasos), 'Cada ruta de ANOTAN necesita su paso aquí.');

        // Sin topes: el dominio propio, el import y un cuarto usuario son de plan.
        $this->tienda->forceFill(['plan' => 'enterprise', 'trial_ends_at' => null])->save();

        foreach ($pasos as $ruta => $paso) {
            $antes = ActivityLog::count();

            $respuesta = $paso();
            $this->assertTrue($respuesta->isSuccessful(), "{$ruta} respondió {$respuesta->status()}: {$respuesta->getContent()}");

            $linea = ActivityLog::latest('created_at')->latest('id')->first();
            $this->assertSame($antes + 1, ActivityLog::count(), "{$ruta} tenía que dejar exactamente una línea.");
            $this->assertSame(self::ANOTAN[$ruta], $linea->action, $ruta);
            $this->assertSame(ActivityLog::ORIGEN_TIENDA, $linea->origen, $ruta);
            $this->assertSame($this->tienda->id, $linea->tenant_id, $ruta);
            $this->assertSame($this->admin->email, $linea->actor_email, $ruta);
        }
    }

    // ---------------------------------------------------- lo que dice la línea

    public function test_editar_un_producto_dice_quien_fue_y_de_que_a_que(): void
    {
        $this->como($this->staff)->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Ryzen 7 7800X3D', 'price' => 1450, 'stock' => 8,
        ])->assertOk();

        $linea = $this->ultimaLinea();

        $this->assertSame(ActivityLog::PRODUCTO_EDITADO, $linea->action);
        $this->assertSame('vendedor@bitacora.test', $linea->actor_email);
        $this->assertSame('staff', $linea->actor_role);
        $this->assertSame('Editó «Ryzen 7 7800X3D»: precio 1500 → 1450, stock 10 → 8.', $linea->description);
        $this->assertSame(['precio' => ['1500.00', '1450.00'], 'stock' => [10, 8]], $linea->context['cambios']);
    }

    public function test_guardar_un_producto_sin_tocar_nada_no_anota(): void
    {
        $this->como($this->admin)->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Ryzen 7 7800X3D', 'price' => '1500', 'stock' => 10,
        ])->assertOk();

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_con_variantes_anota_la_variante_y_no_el_resumen_de_la_ficha(): void
    {
        $id = $this->como($this->admin)->postJson('/api/products', [
            'name' => 'Kingston Fury',
            'variants' => [
                ['options' => [['name' => 'Capacidad', 'value' => '16 GB']], 'price' => 60, 'stock' => 5],
                ['options' => [['name' => 'Capacidad', 'value' => '32 GB']], 'price' => 120, 'stock' => 3],
            ],
        ])->assertCreated()->json('id');

        // `withoutTenant()`: fuera de una petición no hay tienda y el scope falla en cerrado (AUD-4).
        $variantes = ProductVariant::withoutTenant()->where('product_id', $id)->get()->keyBy('nombre');

        $this->como($this->admin)->putJson("/api/products/{$id}", [
            'name' => 'Kingston Fury',
            'variants' => [
                ['id' => $variantes['16 GB']->id, 'options' => [['name' => 'Capacidad', 'value' => '16 GB']], 'price' => 55, 'stock' => 5],
                ['options' => [['name' => 'Capacidad', 'value' => '64 GB']], 'price' => 220, 'stock' => 1],
            ],
        ])->assertOk();

        $descripcion = $this->ultimaLinea()->description;

        $this->assertStringContainsString('precio de 16 GB 60 → 55', $descripcion);
        $this->assertStringContainsString('añadió la variante 64 GB', $descripcion);
        $this->assertStringContainsString('quitó la variante 32 GB', $descripcion);
        // El precio "desde" de la ficha también cambió (60 → 55), pero es un resumen.
        $this->assertStringNotContainsString(', precio 60', $descripcion);
        $this->assertStringNotContainsString('stock 8', $descripcion);
    }

    public function test_el_pedido_y_el_lote_cuentan_lo_que_hicieron(): void
    {
        $categoria = $this->crearCategoria('Procesadores');
        $this->producto->update(['category_id' => $categoria->id]);

        $pedido = $this->como($this->staff)->postJson('/api/orders', [
            'customer_name' => 'Ana', 'status' => 'pending',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertCreated()->json();

        $this->assertSame("Registró la venta de mostrador #{$pedido['number']} a nombre de Ana (pendiente).", $this->ultimaLinea()->description);

        $this->como($this->staff)->putJson("/api/orders/{$pedido['id']}", ['status' => 'attended'])->assertOk();
        $this->assertSame("Pasó el pedido #{$pedido['number']} de pendiente a atendido.", $this->ultimaLinea()->description);

        // Volver a mandar el mismo estado no cambia nada y no anota.
        $lineas = ActivityLog::count();
        $this->como($this->staff)->putJson("/api/orders/{$pedido['id']}", ['status' => 'attended'])->assertOk();
        $this->assertSame($lineas, ActivityLog::count());

        $this->como($this->admin)->postJson('/api/products/bulk', [
            'category_id' => $categoria->id, 'bulk_action' => 'adjust_price', 'price_adjustment' => -10,
        ])->assertOk();

        $this->assertSame('Ajustó los precios un -10% en 1 productos de la categoría «Procesadores».', $this->ultimaLinea()->description);
    }

    public function test_configuracion_nombra_la_apariencia_sin_volcar_el_tema(): void
    {
        $this->como($this->admin)->putJson('/api/tenant', [
            'whatsapp_number' => '51911111111',
            'theme' => ['hero_title' => 'Lo mejor en hardware'],
        ])->assertOk();

        $this->assertSame(
            'Cambió la configuración de la tienda: WhatsApp 51999999999 → 51911111111, apariencia.',
            $this->ultimaLinea()->description,
        );
    }

    // ------------------------------------------------------------ el listado

    public function test_solo_un_admin_ve_la_actividad(): void
    {
        $this->como($this->staff)->getJson('/api/activity')->assertForbidden();
        $this->como($this->admin)->getJson('/api/activity')->assertOk();
    }

    public function test_cada_tienda_ve_solo_lo_suyo_y_nunca_lo_del_operador(): void
    {
        $otra = $this->crearTienda('otra-tienda', 'Otra Tienda');
        $adminOtra = $this->crearUsuario($otra, 'duenio@otra.test', 'admin', 'Dueño otra');
        $productoOtra = $this->crearProducto($otra, 'Producto de la otra', 100, 1);

        $this->como($this->staff)->putJson("/api/products/{$this->producto->id}", ['name' => 'Ryzen 7 7800X3D', 'price' => 1450, 'stock' => 10])->assertOk();
        $this->como($adminOtra, $otra)->putJson("/api/products/{$productoOtra->id}", ['name' => 'Producto de la otra', 'price' => 90, 'stock' => 1])->assertOk();

        // Lo que el operador anotó sobre esta tienda (INF-2) no es de la tienda.
        ActivityLog::registrar(ActivityLog::TIENDA_SOPORTE, 'Entró como soporte.', $this->tienda, null, [], null);

        $respuesta = $this->como($this->admin)->getJson('/api/activity')->assertOk();

        $this->assertSame(1, $respuesta->json('total'));
        $this->assertSame(ActivityLog::PRODUCTO_EDITADO, $respuesta->json('data.0.action'));
        $this->assertSame('Vendedor', $respuesta->json('data.0.actor.name'));
        $this->assertArrayNotHasKey('ip', $respuesta->json('data.0'));
    }

    public function test_filtra_por_area_y_por_persona(): void
    {
        $this->como($this->staff)->putJson("/api/products/{$this->producto->id}", ['name' => 'Ryzen 7 7800X3D', 'price' => 1450, 'stock' => 10])->assertOk();
        $this->como($this->admin)->postJson('/api/categories', ['name' => 'Monitores'])->assertCreated();

        $this->como($this->admin)->getJson('/api/activity?area=categoria')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.action', ActivityLog::CATEGORIA_CREADA);

        $this->como($this->admin)->getJson('/api/activity?actor=vendedor@bitacora.test')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.action', ActivityLog::PRODUCTO_EDITADO);

        $this->como($this->admin)->getJson('/api/activity?area=inventada')->assertStatus(422);
    }

    public function test_lo_que_hizo_alguien_sigue_ahi_cuando_se_le_quita_del_equipo(): void
    {
        $this->como($this->staff)->putJson("/api/products/{$this->producto->id}", ['name' => 'Ryzen 7 7800X3D', 'price' => 1450, 'stock' => 10])->assertOk();
        $this->como($this->admin)->deleteJson("/api/users/{$this->staff->id}")->assertNoContent();

        $this->como($this->admin)->getJson('/api/activity?actor=vendedor@bitacora.test')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.actor', null)
            ->assertJsonPath('data.0.actor_email', 'vendedor@bitacora.test');
    }

    public function test_la_bitacora_del_operador_no_se_llena_con_la_de_las_tiendas(): void
    {
        $this->como($this->staff)->putJson("/api/products/{$this->producto->id}", ['name' => 'Ryzen 7 7800X3D', 'price' => 1450, 'stock' => 10])->assertOk();
        ActivityLog::registrar(ActivityLog::TIENDA_PLAN, 'Cambió el plan.', $this->tienda, null, [], null);

        $operador = new User(['name' => 'Operador', 'email' => 'op@plataforma.test', 'password' => 'secret1234', 'role' => 'superadmin', 'is_active' => true]);
        $operador->save();

        $this->desdeCero();
        $token = $operador->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/platform/logs')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.action', ActivityLog::TIENDA_PLAN);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/platform/tenants/{$this->tienda->id}")
            ->assertOk()->assertJsonCount(1, 'bitacora');
    }

    // -------------------------------------------------------- si anotar falla

    public function test_si_la_bitacora_falla_el_cambio_se_guarda_igual(): void
    {
        Schema::drop('activity_logs');

        $this->como($this->staff)->putJson("/api/products/{$this->producto->id}", [
            'name' => 'Ryzen 7 7800X3D', 'price' => 1450, 'stock' => 10,
        ])->assertOk();

        $this->assertEquals(1450, $this->producto->fresh()->price);
    }

    // ------------------------------------------------------------- utilidades

    private function como(User $usuario, ?Tenant $tienda = null): self
    {
        $this->desdeCero();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant'      => ($tienda ?? $this->tienda)->slug,
        ]);
    }

    /** Ver `TeamUsersTest::desdeCero()`: el guard recuerda al usuario de la petición anterior. */
    private function desdeCero(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse($this->guardOriginal);
    }

    private function ultimaLinea(): ActivityLog
    {
        return ActivityLog::latest('created_at')->latest('id')->firstOrFail();
    }

    private function ultimoPedidoId(): string
    {
        return \App\Models\Order::withoutTenant()->where('tenant_id', $this->tienda->id)->latest('created_at')->value('id');
    }

    private function crearTienda(string $slug, string $nombre): Tenant
    {
        return Tenant::create([
            'slug' => $slug, 'name' => $nombre, 'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);
    }

    private function crearUsuario(Tenant $tienda, string $email, string $rol, string $nombre): User
    {
        $usuario = new User(['name' => $nombre, 'email' => $email, 'password' => 'secret1234', 'role' => $rol, 'is_active' => true]);
        $usuario->tenant_id = $tienda->id;
        $usuario->save();

        return $usuario;
    }

    private function crearProducto(Tenant $tienda, string $nombre, float $precio, int $stock): Product
    {
        $producto = new Product(['name' => $nombre, 'price' => $precio, 'stock' => $stock, 'is_active' => true, 'status' => 'published']);
        $producto->tenant_id = $tienda->id;
        $producto->save();

        return $producto;
    }

    private function crearCategoria(string $nombre): Category
    {
        $categoria = new Category(['name' => $nombre]);
        $categoria->tenant_id = $this->tienda->id;
        $categoria->save();

        return $categoria;
    }

    private function crearPagina(string $titulo): Page
    {
        $pagina = new Page(['title' => $titulo, 'slug' => 'garantia', 'content' => '<p>Un año.</p>', 'is_active' => true]);
        $pagina->tenant_id = $this->tienda->id;
        $pagina->save();

        return $pagina;
    }

    private function crearResena(): Review
    {
        // Oculta para que aprobarla sea un cambio: nacen aprobadas por defecto.
        $resena = new Review(['product_id' => $this->producto->id, 'customer_name' => 'Luis', 'rating' => 5, 'comment' => 'Buenísimo', 'is_approved' => false]);
        $resena->tenant_id = $this->tienda->id;
        $resena->save();

        return $resena;
    }

    private function crearEspera(): StockNotification
    {
        $espera = new StockNotification(['product_id' => $this->producto->id, 'customer_name' => 'Marta', 'customer_contact' => '51988888888']);
        $espera->tenant_id = $this->tienda->id;
        $espera->save();

        return $espera;
    }
}
