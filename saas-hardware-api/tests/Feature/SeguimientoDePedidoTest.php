<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrderPlacedNotification;
use App\Support\Comprobantes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El pedido visto por quien lo hizo, sin cuenta, y su comprobante de pago (MOD-26).
 *
 * Lo que fija este fichero, por lo que costaría romperlo:
 *
 * 1. **El enlace es la única llave**: sin el token no se ve nada, el de otra
 *    tienda da 404, y lo que se ve no trae ni el teléfono ni el correo.
 * 2. **El comprobante es un archivo de un desconocido con datos bancarios**: va
 *    al disco privado, solo lo lee el panel de su tienda, se valida por su
 *    contenido y caduca.
 * 3. **El pago va aparte del estado del pedido**: confirmarlo no descuenta stock
 *    ni cambia el estado.
 */
class SeguimientoDePedidoTest extends TestCase
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
        Storage::fake('local');
        config(['app.frontend_url' => 'https://gondofy.test']);

        $this->tienda = Tenant::create([
            'slug' => 'tienda-seguimiento', 'name' => 'Tienda Seguimiento',
            'whatsapp_number' => '51999999999', 'is_active' => true, 'is_published' => true,
        ]);

        $this->admin = new User(['name' => 'Dueña', 'email' => 'duenia@seguimiento.test', 'password' => 'secret1234', 'role' => 'admin', 'is_active' => true]);
        $this->admin->tenant_id = $this->tienda->id;
        $this->admin->save();

        $this->producto = new Product(['name' => 'Mouse', 'price' => 50, 'stock' => 10, 'is_active' => true, 'status' => 'published']);
        $this->producto->tenant_id = $this->tienda->id;
        $this->producto->save();
    }

    // --- El enlace ------------------------------------------------------------

    public function test_cada_pedido_nace_con_su_enlace_y_el_comprador_lo_recibe(): void
    {
        $respuesta = $this->comprar();

        $token = $respuesta->json('tracking_token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);

        // En el correo de confirmación, el botón lleva a su pedido.
        Notification::assertSentTo(new AnonymousNotifiable, OrderPlacedNotification::class, function (OrderPlacedNotification $aviso, $canales, $destino) use ($token) {
            return $aviso->toMail($destino)->actionUrl === "https://gondofy.test/tienda-seguimiento/pedido/{$token}";
        });
    }

    public function test_con_el_enlace_se_ve_el_pedido_sin_sus_datos_de_contacto(): void
    {
        $token = $this->comprar()->json('tracking_token');

        $respuesta = $this->getJson("/api/public/tienda-seguimiento/pedidos/{$token}")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('items.0.product_name', 'Mouse')
            ->assertJsonPath('total', '50.00')
            ->assertJsonPath('payment.status', null)
            ->assertJsonPath('payment.can_upload', true);

        $cuerpo = $respuesta->getContent();
        $this->assertStringNotContainsString('987654321', $cuerpo);
        $this->assertStringNotContainsString('ana@correo.test', $cuerpo);
        $this->assertStringNotContainsString('Déjenlo en portería', $cuerpo);
    }

    public function test_un_enlace_que_no_existe_o_de_otra_tienda_da_404(): void
    {
        $token = $this->comprar()->json('tracking_token');

        $otra = Tenant::create(['slug' => 'otra-tienda', 'name' => 'Otra', 'whatsapp_number' => '51988888888', 'is_active' => true, 'is_published' => true]);

        $this->getJson("/api/public/{$otra->slug}/pedidos/{$token}")->assertNotFound();
        $this->getJson('/api/public/tienda-seguimiento/pedidos/'.str_repeat('a', 40))->assertNotFound();
        $this->getJson('/api/public/tienda-seguimiento/pedidos/corto')->assertNotFound();
    }

    // --- El comprobante -------------------------------------------------------

    public function test_mandar_el_comprobante_lo_guarda_en_privado_y_deja_el_pago_en_revision(): void
    {
        $token = $this->comprar()->json('tracking_token');

        $this->mandar($token, UploadedFile::fake()->image('captura.png'))
            ->assertOk()
            ->assertJsonPath('payment.status', Order::PAGO_EN_REVISION);

        $pedido = Order::withoutTenant()->firstOrFail();
        $this->assertStringStartsWith("comprobantes/{$this->tienda->id}/", $pedido->payment_proof_path);
        Storage::disk('local')->assertExists($pedido->payment_proof_path);
        // La ruta no sale en ninguna respuesta.
        $this->assertArrayNotHasKey('payment_proof_path', $pedido->toArray());
        $this->assertTrue($pedido->tiene_comprobante);
    }

    public function test_un_pdf_vale_y_lo_que_no_es_imagen_ni_pdf_no(): void
    {
        $token = $this->comprar()->json('tracking_token');

        $this->mandar($token, $this->archivoDeVerdad('transferencia.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"))
            ->assertOk();

        // Un HTML llamado .pdf: lo que decide es el contenido, no el nombre. Con
        // un archivo de verdad, porque los falsos de Laravel dicen ser lo que su
        // nombre diga.
        $this->mandar($token, $this->archivoDeVerdad('truco.pdf', '<html><body><script>alert(1)</script></body></html>'))
            ->assertStatus(422);
    }

    public function test_mandar_otro_reemplaza_al_anterior_y_lo_borra(): void
    {
        $token = $this->comprar()->json('tracking_token');

        $this->mandar($token, UploadedFile::fake()->image('borrosa.png'))->assertOk();
        $primero = Order::withoutTenant()->firstOrFail()->payment_proof_path;

        $this->mandar($token, UploadedFile::fake()->image('nitida.png'))->assertOk();

        Storage::disk('local')->assertMissing($primero);
        Storage::disk('local')->assertExists(Order::withoutTenant()->firstOrFail()->payment_proof_path);
    }

    public function test_no_se_manda_con_el_pedido_cancelado_ni_con_el_pago_confirmado(): void
    {
        $token = $this->comprar()->json('tracking_token');
        $pedido = Order::withoutTenant()->firstOrFail();

        $pedido->forceFill(['payment_status' => Order::PAGO_CONFIRMADO])->save();
        $this->mandar($token, UploadedFile::fake()->image('c.png'))->assertStatus(422);

        $pedido->forceFill(['payment_status' => null, 'status' => 'cancelled'])->save();
        $this->mandar($token, UploadedFile::fake()->image('c.png'))->assertStatus(422);
        $this->getJson("/api/public/tienda-seguimiento/pedidos/{$token}")->assertJsonPath('payment.can_upload', false);
    }

    // --- El panel -------------------------------------------------------------

    public function test_el_panel_ve_el_comprobante_y_otra_tienda_no(): void
    {
        $token = $this->comprar()->json('tracking_token');
        $this->mandar($token, UploadedFile::fake()->image('captura.png'));
        $pedido = Order::withoutTenant()->firstOrFail();

        $respuesta = $this->comoAdmin()->get("/api/orders/{$pedido->id}/comprobante")->assertOk();
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        $this->assertSame('sandbox', $respuesta->headers->get('Content-Security-Policy'));

        $otra = Tenant::create(['slug' => 'otra-tienda', 'name' => 'Otra', 'whatsapp_number' => '51988888888', 'is_active' => true]);
        $ajeno = new User(['name' => 'Ajeno', 'email' => 'ajeno@otra.test', 'password' => 'secret1234', 'role' => 'admin', 'is_active' => true]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$ajeno->createToken('t')->plainTextToken, 'X-Tenant' => 'otra-tienda'])
            ->get("/api/orders/{$pedido->id}/comprobante")
            ->assertNotFound();
    }

    public function test_confirmar_el_pago_no_toca_el_estado_ni_el_stock_y_queda_escrito(): void
    {
        $token = $this->comprar()->json('tracking_token');
        $this->mandar($token, UploadedFile::fake()->image('captura.png'));
        $pedido = Order::withoutTenant()->firstOrFail();

        $this->comoAdmin()->postJson("/api/orders/{$pedido->id}/pago", ['decision' => 'confirmar'])
            ->assertOk()
            ->assertJsonPath('payment_status', Order::PAGO_CONFIRMADO)
            ->assertJsonPath('status', 'pending');

        $this->assertSame(10, (int) $this->producto->fresh()->stock);
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLog::PEDIDO_PAGO)->count());
        $this->getJson("/api/public/tienda-seguimiento/pedidos/{$token}")
            ->assertJsonPath('payment.status', Order::PAGO_CONFIRMADO)
            ->assertJsonPath('payment.can_upload', false);
    }

    public function test_rechazar_pide_motivo_lo_ve_el_comprador_y_puede_mandar_otro(): void
    {
        $token = $this->comprar()->json('tracking_token');
        $pedido = Order::withoutTenant()->firstOrFail();

        // Sin comprobante en revisión no hay nada que rechazar.
        $this->comoAdmin()->postJson("/api/orders/{$pedido->id}/pago", ['decision' => 'rechazar', 'motivo' => 'x'])->assertStatus(422);

        $this->mandar($token, UploadedFile::fake()->image('captura.png'));

        $this->comoAdmin()->postJson("/api/orders/{$pedido->id}/pago", ['decision' => 'rechazar'])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->comoAdmin()->postJson("/api/orders/{$pedido->id}/pago", ['decision' => 'rechazar', 'motivo' => 'El monto no coincide'])
            ->assertOk();

        $this->getJson("/api/public/tienda-seguimiento/pedidos/{$token}")
            ->assertJsonPath('payment.status', Order::PAGO_RECHAZADO)
            ->assertJsonPath('payment.rejection_reason', 'El monto no coincide')
            ->assertJsonPath('payment.can_upload', true);

        $this->mandar($token, UploadedFile::fake()->image('otra.png'))->assertJsonPath('payment.status', Order::PAGO_EN_REVISION);
    }

    public function test_el_listado_filtra_los_que_esperan_revision(): void
    {
        $token = $this->comprar()->json('tracking_token');
        $this->comprar();
        $this->mandar($token, UploadedFile::fake()->image('captura.png'));

        $this->comoAdmin()->getJson('/api/orders?payment_status=en_revision')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tiene_comprobante', true);
    }

    // --- Caducidad ------------------------------------------------------------

    public function test_purgar_borra_lo_revisado_hace_tiempo_lo_viejo_y_lo_que_no_tiene_pedido(): void
    {
        $revisado = $this->conComprobante(['payment_status' => Order::PAGO_CONFIRMADO, 'payment_reviewed_at' => now()->subDays(Comprobantes::DIAS_TRAS_REVISAR + 1)]);
        $reciente = $this->conComprobante(['payment_status' => Order::PAGO_CONFIRMADO, 'payment_reviewed_at' => now()->subDay()]);
        $olvidado = $this->conComprobante(['payment_status' => Order::PAGO_EN_REVISION, 'payment_proof_uploaded_at' => now()->subDays(Comprobantes::DIAS_MAXIMOS + 1)]);

        Storage::disk('local')->put('comprobantes/huerfano.png', 'x');
        touch(Storage::disk('local')->path('comprobantes/huerfano.png'), now()->subDays(2)->getTimestamp());

        $this->artisan('comprobantes:purgar')->assertSuccessful();

        Storage::disk('local')->assertMissing($revisado);
        Storage::disk('local')->assertMissing($olvidado);
        Storage::disk('local')->assertMissing('comprobantes/huerfano.png');
        Storage::disk('local')->assertExists($reciente);
        // El pedido se queda con su estado de pago: solo se va el archivo.
        $this->assertSame(Order::PAGO_CONFIRMADO, Order::withoutTenant()->where('payment_proof_path', null)->value('payment_status'));
    }

    // --- ayudantes ------------------------------------------------------------

    private function comprar(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/public/tienda-seguimiento/orders', [
            'customer_name' => 'Ana',
            'customer_phone' => '+51987654321',
            'customer_email' => 'ana@correo.test',
            'customer_note' => 'Déjenlo en portería',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    private function mandar(string $token, UploadedFile $archivo): \Illuminate\Testing\TestResponse
    {
        return $this->post("/api/public/tienda-seguimiento/pedidos/{$token}/comprobante", ['file' => $archivo], ['Accept' => 'application/json']);
    }

    private function archivoDeVerdad(string $nombre, string $contenido): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'comprobante');
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    private function conComprobante(array $pago): string
    {
        $pedido = Order::withoutTenant()->findOrFail($this->comprar()->json('id'));
        $ruta = "comprobantes/{$this->tienda->id}/".uniqid().'.png';
        Storage::disk('local')->put($ruta, 'x');
        $pedido->forceFill($pago + ['payment_proof_path' => $ruta, 'payment_proof_uploaded_at' => now()])->save();

        return $ruta;
    }

    private function comoAdmin(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('test')->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }
}
