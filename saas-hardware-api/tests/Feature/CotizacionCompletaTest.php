<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImageService;
use App\Support\Cotizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Una cotización que se pueda mandar (FUN-26).
 *
 * La de `MOD-2` no decía hasta cuándo valía ni quién la emitía, que es lo
 * primero que pregunta quien compara presupuestos. Se comprueba el texto
 * renderizando la vista con los mismos datos que usa el PDF (`Cotizacion`): el
 * PDF va comprimido y no se puede buscar en él.
 */
class CotizacionCompletaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $admin;

    private Order $pedido;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Carbon::setTestNow('2026-11-05 15:00:00');

        $this->tienda = Tenant::create([
            'slug' => 'tienda-cotiza', 'name' => 'Tienda Cotiza', 'whatsapp_number' => '51999999999',
            'is_active' => true, 'timezone' => 'America/Lima',
        ]);

        $this->admin = new User(['name' => 'Ana Dueña', 'email' => 'ana@cotiza.test', 'password' => 'secret1234', 'role' => 'admin', 'is_active' => true]);
        $this->admin->tenant_id = $this->tienda->id;
        $this->admin->save();

        $this->pedido = new Order(['customer_name' => 'Cliente', 'status' => 'pending', 'total' => 100]);
        $this->pedido->tenant_id = $this->tienda->id;
        $this->pedido->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function html(?User $quien = null): string
    {
        return view('cotizacion', Cotizacion::datos($this->pedido->fresh(), $this->tienda->fresh(), $quien ?? $this->admin, app(ImageService::class)))->render();
    }

    public function test_dice_quien_la_emite_y_hasta_cuando_vale(): void
    {
        $this->tienda->update(['quote_validity_days' => 15]);

        $html = $this->html();

        $this->assertStringContainsString('Emitida el 05/11/2026 por Ana Dueña', $html);
        $this->assertStringContainsString('Válida hasta el 20/11/2026', $html);
    }

    public function test_sin_dias_de_validez_no_promete_ninguna_fecha(): void
    {
        $this->assertStringNotContainsString('Válida hasta', $this->html());
    }

    public function test_lleva_los_datos_fiscales_del_pie_las_condiciones_y_como_pagar(): void
    {
        $this->tienda->update([
            'theme' => ['footer_tax_id' => 'RUC 20123456789', 'footer_address' => 'Av. Siempre Viva 742, Lima'],
            'quote_terms' => "50% de anticipo.\nEntrega en 3 días.\n<script>alert(1)</script>",
            'payment_methods' => [
                ['name' => 'Yape', 'details' => '987654321 · Ana', 'enabled' => true],
                ['name' => 'Plin', 'details' => '999888777', 'enabled' => false],
            ],
        ]);

        $html = $this->html();

        $this->assertStringContainsString('RUC 20123456789', $html);
        $this->assertStringContainsString('Av. Siempre Viva 742, Lima', $html);
        $this->assertStringContainsString('Yape · 987654321 · Ana', $html);
        $this->assertStringNotContainsString('Plin', $html);
        $this->assertStringContainsString('50% de anticipo.<br />', $html);
        // Lo escribe la tienda: se imprime como texto, nunca como HTML.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        // Y sigue sin ser un comprobante fiscal (MOD-14).
        $this->assertStringContainsString('No es un comprobante de pago electrónico', $html);
    }

    public function test_configuracion_guarda_la_validez_y_las_condiciones_y_lo_anota(): void
    {
        $this->comoAdmin()->putJson('/api/tenant', ['quote_validity_days' => 0])->assertStatus(422)->assertJsonValidationErrors('quote_validity_days');

        $this->comoAdmin()->putJson('/api/tenant', ['quote_validity_days' => 10, 'quote_terms' => 'Garantía de 12 meses.'])->assertOk();

        $this->assertSame(10, $this->tienda->fresh()->quote_validity_days);
        $linea = ActivityLog::query()->where('action', ActivityLog::CONFIGURACION_EDITADA)->latest('id')->firstOrFail();
        $this->assertStringContainsString('días de validez de la cotización vacío → 10', $linea->description);
        $this->assertStringContainsString('condiciones de la cotización', $linea->description);

        // Vacío la quita: la cotización deja de prometer una fecha.
        $this->comoAdmin()->putJson('/api/tenant', ['quote_validity_days' => null])->assertOk();
        $this->assertNull($this->tienda->fresh()->quote_validity_days);
    }

    public function test_el_pdf_se_sigue_descargando(): void
    {
        $this->tienda->update(['quote_validity_days' => 7, 'quote_terms' => 'Entrega inmediata.']);

        $respuesta = $this->comoAdmin()->get("/api/orders/{$this->pedido->id}/pdf")->assertOk();

        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());
    }

    private function comoAdmin(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }
}
