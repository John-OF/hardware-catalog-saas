<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Métodos de pago visibles y envío (MOD-3, MOD-1).
 *
 * Dos funciones chicas y sin nada en común salvo que las pidió el mismo
 * cambio, así que van en el mismo fichero.
 *
 * **`MOD-3` no es una pasarela.** (Desde `MOD-23` los métodos son una lista
 * que escribe la tienda; lo propio de la lista está en `MetodosDePagoTest`.) No hay ningún cobro ni ningún API externa:
 * es solo lo que la tienda le enseña al comprador (Yape, cuenta bancaria,
 * efectivo) para que sepa cómo pagarle. El checkout sigue cerrándose por
 * WhatsApp. Por eso lo único que fija esta parte es que **un método apagado
 * no se vea desde fuera** — puede tener datos a medio llenar de un intento
 * anterior, y eso no es asunto del comprador.
 *
 * **`MOD-1` sí mueve dinero**, así que lo que importa es que el costo de
 * envío **lo decide el servidor, nunca el navegador**: un comprador no puede
 * pedir "delivery" a costo 0 escribiendo el JSON a mano, ni cobrar envío en
 * una tienda que no lo ofrece.
 */
class PaymentAndDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->tenant = Tenant::create([
            'slug' => 'tienda-envios',
            'name' => 'Tienda Envíos',
            'whatsapp_number' => '51999999999',
            'is_active' => true,
            'is_published' => true,
        ]);

        $this->admin = new User([
            'name' => 'Dueña', 'email' => 'duenia@envios.test', 'password' => 'secret1234',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();

        $this->producto = new Product([
            'name' => 'Mouse Gamer', 'price' => 100, 'stock' => 20,
            'is_active' => true, 'status' => 'published',
        ]);
        $this->producto->tenant_id = $this->tenant->id;
        $this->producto->save();
    }

    // ------------------------------------------- MOD-3 / MOD-23: guardar

    /**
     * Desde MOD-23 son una lista que escribe la tienda, no cuatro fijos. Los
     * casos de la lista en sí (QR, ids, el formato de antes) están en
     * `MetodosDePagoTest`; aquí queda lo que ya fijaba MOD-3.
     */
    public function test_guarda_la_lista_de_metodos_con_sus_datos_y_en_su_orden(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', [
            'payment_methods' => [
                ['name' => 'Deuna', 'details' => '0991234567 · Ana Dueña', 'enabled' => true],
                ['name' => 'Transferencia Banco Pichincha', 'details' => 'Ahorros 2201234567', 'instructions' => 'Manda la captura', 'enabled' => false],
                ['name' => 'Efectivo contra entrega'],
            ],
        ])->assertOk();

        $guardado = $this->tenant->fresh()->payment_methods;

        $this->assertSame(['Deuna', 'Transferencia Banco Pichincha', 'Efectivo contra entrega'], array_column($guardado, 'name'));
        $this->assertSame('0991234567 · Ana Dueña', $guardado[0]['details']);
        $this->assertFalse($guardado[1]['enabled']);
        $this->assertSame('Manda la captura', $guardado[1]['instructions']);
        // Sin decir nada, encendido: es lo que se acaba de escribir para enseñarlo.
        $this->assertTrue($guardado[2]['enabled']);
    }

    public function test_un_metodo_sin_nombre_se_rechaza(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', [
            'payment_methods' => [['name' => '', 'details' => '987654321']],
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_methods.0.name']);
    }

    /**
     * El navegador nunca manda `true`/`false`: `FormData` solo tiene texto, y
     * el frontend manda '1'/'0' (`api/tenant.ts`). `putJson` no lo habría
     * cazado —manda JSON de verdad, con booleanos de verdad— así que aquí se
     * manda tal cual llega de un formulario, con `post()` y no `postJson()`.
     *
     * Sin convertirlo, "0" se guardaba literal en el JSON, y en el navegador el
     * string "0" es verdadero: un método apagado volvía a aparecer marcado la
     * próxima vez que se abría Configuración.
     */
    public function test_un_metodo_apagado_por_formulario_se_guarda_como_booleano_de_verdad_y_no_como_texto(): void
    {
        $this->comoAdmin()->post('/api/tenant', [
            '_method' => 'PUT',
            'payment_methods' => [
                ['name' => 'Yape', 'enabled' => '1', 'details' => '987654321'],
                ['name' => 'Plin', 'enabled' => '0'],
            ],
        ])->assertOk();

        $guardado = $this->tenant->fresh()->payment_methods;

        $this->assertSame(true, $guardado[0]['enabled']);
        $this->assertSame(false, $guardado[1]['enabled']);
    }

    public function test_guardar_otra_cosa_no_toca_los_metodos(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', [
            'payment_methods' => [['name' => 'Yape', 'details' => '987654321']],
        ])->assertOk();

        $this->comoAdmin()->putJson('/api/tenant', ['delivery_enabled' => true])->assertOk();

        $this->assertSame(['Yape'], array_column($this->tenant->fresh()->payment_methods, 'name'));
    }

    // ------------------------------------------------------- MOD-1: guardar

    public function test_guarda_el_envio_activado_y_su_costo(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', [
            'delivery_enabled' => true,
            'delivery_cost' => 12.50,
        ])->assertOk();

        $fresco = $this->tenant->fresh();
        $this->assertTrue($fresco->delivery_enabled);
        $this->assertSame('12.50', (string) $fresco->delivery_cost);
    }

    public function test_rechaza_un_costo_de_envio_negativo(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', [
            'delivery_enabled' => true, 'delivery_cost' => -5,
        ])->assertStatus(422)->assertJsonValidationErrors(['delivery_cost']);
    }

    // --------------------------------------------------- lo que ve el comprador

    public function test_el_catalogo_publico_solo_enseña_los_metodos_encendidos(): void
    {
        $this->tenant->update([
            'payment_methods' => [
                ['name' => 'Yape', 'details' => '987654321 · Ana', 'enabled' => true],
                // Apagado, pero con datos de un intento anterior: no deben salir.
                ['name' => 'Plin', 'details' => '999888777 · Ana', 'enabled' => false],
            ],
        ]);

        $respuesta = $this->getJson("/api/public/{$this->tenant->slug}")->assertOk();

        $this->assertSame(['Yape'], array_column($respuesta->json('payment_methods'), 'name'));
        $respuesta->assertJsonPath('payment_methods.0.details', '987654321 · Ana');
    }

    public function test_resolve_domain_tambien_filtra_los_metodos_apagados(): void
    {
        $this->tenant->update([
            'custom_domain' => 'tienda-envios.example.com',
            'payment_methods' => [
                ['name' => 'Efectivo contra entrega', 'enabled' => true],
                ['name' => 'Yape', 'enabled' => false],
            ],
        ]);
        // Fuera de $fillable a propósito (FUN-6): solo se escribe con forceFill.
        $this->tenant->forceFill(['custom_domain_verified_at' => now()])->save();

        $respuesta = $this->getJson('/api/public/resolve-domain?domain=tienda-envios.example.com')->assertOk();

        $this->assertSame(['Efectivo contra entrega'], array_column($respuesta->json('payment_methods'), 'name'));
    }

    // ------------------------------------------------- MOD-1: el pedido cobra

    public function test_pedido_con_delivery_suma_el_costo_de_la_tienda(): void
    {
        $this->tenant->update(['delivery_enabled' => true, 'delivery_cost' => 15]);

        $respuesta = $this->pedir('delivery')->assertCreated();

        $this->assertSame(115.0, (float) $respuesta->json('total'));
        $this->assertDatabaseHas('orders', [
            'id' => $respuesta->json('id'), 'delivery_method' => 'delivery', 'delivery_cost' => 15.00,
        ]);
    }

    public function test_pedido_con_recojo_no_suma_nada(): void
    {
        $this->tenant->update(['delivery_enabled' => true, 'delivery_cost' => 15]);

        $respuesta = $this->pedir('pickup')->assertCreated();

        $this->assertSame(100.0, (float) $respuesta->json('total'));
        $this->assertDatabaseHas('orders', [
            'id' => $respuesta->json('id'), 'delivery_method' => 'pickup', 'delivery_cost' => 0.00,
        ]);
    }

    public function test_no_se_puede_pedir_delivery_gratis_forzando_el_metodo_sin_que_la_tienda_lo_ofrezca(): void
    {
        // La tienda NUNCA activó el envío, pero delivery_cost quedó con un
        // valor de una prueba anterior (columna con default 0, aquí a mano
        // con un valor para que el test sea exigente).
        $this->tenant->update(['delivery_enabled' => false, 'delivery_cost' => 999]);

        $respuesta = $this->pedir('delivery')->assertCreated();

        $this->assertSame(100.0, (float) $respuesta->json('total'));
        $this->assertDatabaseHas('orders', [
            'id' => $respuesta->json('id'), 'delivery_method' => null, 'delivery_cost' => 0.00,
        ]);
    }

    public function test_sin_envio_activado_pedir_recojo_se_acepta_y_no_cobra(): void
    {
        $this->tenant->update(['delivery_enabled' => false]);

        $respuesta = $this->pedir('pickup')->assertCreated();

        $this->assertSame(100.0, (float) $respuesta->json('total'));
        $this->assertDatabaseHas('orders', ['id' => $respuesta->json('id'), 'delivery_method' => 'pickup']);
    }

    public function test_con_envio_activado_no_elegir_nada_cae_a_recojo_y_no_cobra(): void
    {
        // El panel siempre manda `delivery_method` cuando hay envío activado
        // (el radio nace en "recojo"); esto es lo que pasa si algo lo omite
        // igual: el valor mas seguro, no un pedido sin decidir.
        $this->tenant->update(['delivery_enabled' => true, 'delivery_cost' => 15]);

        $respuesta = $this->pedir(null)->assertCreated();

        $this->assertSame(100.0, (float) $respuesta->json('total'));
        $this->assertDatabaseHas('orders', ['id' => $respuesta->json('id'), 'delivery_method' => 'pickup']);
    }

    public function test_bajar_el_costo_de_envio_despues_no_toca_los_pedidos_ya_hechos(): void
    {
        $this->tenant->update(['delivery_enabled' => true, 'delivery_cost' => 15]);
        $pedido = $this->pedir('delivery')->assertCreated();

        $this->tenant->update(['delivery_cost' => 25]);

        $this->assertDatabaseHas('orders', ['id' => $pedido->json('id'), 'delivery_cost' => 15.00]);
    }

    public function test_la_venta_de_mostrador_no_lleva_metodo_de_entrega(): void
    {
        $this->tenant->update(['delivery_enabled' => true, 'delivery_cost' => 15]);

        $respuesta = $this->comoAdmin()->postJson('/api/orders', [
            'customer_name' => 'Cliente de mostrador',
            'status' => 'pending',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
            // Si alguien lo manda igual (un cliente HTTP viejo, o a mano):
            // no es una ruta validada para esto y no debe colarse.
            'delivery_method' => 'delivery',
        ])->assertCreated();

        $this->assertSame(100.0, (float) $respuesta->json('total'));
        $this->assertDatabaseHas('orders', ['id' => $respuesta->json('id'), 'delivery_method' => null]);
    }

    public function test_un_metodo_de_entrega_invalido_se_rechaza(): void
    {
        $this->pedir('teletransporte')->assertStatus(422);
    }

    // ---------------------------------------------------------------- INF-3

    public function test_activar_el_envio_queda_anotado_en_la_actividad(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', [
            'delivery_enabled' => true, 'delivery_cost' => 10,
        ])->assertOk();

        $linea = ActivityLog::where('origen', 'tienda')->latest()->first();
        $this->assertStringContainsString('envío a domicilio no → sí', $linea->description);
        $this->assertStringContainsString('costo de envío 0 → 10', $linea->description);
    }

    public function test_cambiar_metodos_de_pago_se_nombra_sin_volcar_los_datos(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', [
            'payment_methods' => [['name' => 'Yape', 'details' => '987654321']],
        ])->assertOk();

        $linea = ActivityLog::where('origen', 'tienda')->latest()->first();
        $this->assertStringContainsString('métodos de pago', $linea->description);
        $this->assertStringNotContainsString('987654321', $linea->description);
    }

    // ------------------------------------------------------------- utilidades

    private function pedir(?string $metodoDeEntrega)
    {
        $payload = [
            'customer_name' => 'Comprador',
            'customer_phone' => '999888777',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ];

        if ($metodoDeEntrega !== null) {
            $payload['delivery_method'] = $metodoDeEntrega;
        }

        return $this->postJson("/api/public/{$this->tenant->slug}/orders", $payload);
    }

    private function comoAdmin(): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tenant->slug,
        ]);
    }
}
