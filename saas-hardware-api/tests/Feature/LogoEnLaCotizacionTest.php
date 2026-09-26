<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `FUN-20`. La cotización pintaba el logo con `<img src="{{ logo_url }}">`, y
 * dompdf tiene `enable_remote` apagado: el logo, que siempre es remoto, no salía
 * en el PDF de ninguna tienda. El test que cubría la ruta miraba el
 * `Content-Type`, no el contenido.
 *
 * Aquí se mira el contenido: un PDF con una imagen dentro lleva un objeto
 * `/Subtype /Image`, y sin ella no. Es lo mismo que se usó para comprobar el
 * fallo.
 */
class LogoEnLaCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');

        $this->tenant = Tenant::create([
            'slug' => 'tienda-logo', 'name' => 'Tienda Logo', 'whatsapp_number' => '51999999999',
            'is_active' => true, 'is_published' => true,
        ]);

        $this->admin = new User(['name' => 'Duenio', 'email' => 'duenio@logo.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    public function test_el_logo_subido_sale_en_el_pdf(): void
    {
        $this->panel()->post('/api/tenant', [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 100),
        ])->assertOk();

        $pdf = $this->pdf();

        $this->assertStringContainsString('/Subtype /Image', $pdf, 'El logo subido no sale en la cotización.');
        // Reducido al doble del tamaño con que se imprime (180 px de ancho): el
        // PDF no carga con la foto de 1200 px.
        $this->assertStringContainsString('/Width 360', $pdf);
    }

    /** Control: sin logo, el PDF no lleva imagen y sale igual. */
    public function test_sin_logo_el_pdf_sale_sin_imagen(): void
    {
        $pdf = $this->pdf();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    /**
     * Un logo pegado como URL de otra web no se descarga: sería una petición del
     * servidor a donde diga el dueño. La dirección es la de la metadata de la
     * nube a propósito, que es lo primero que se pediría con un SSRF.
     */
    public function test_un_logo_pegado_de_otra_web_no_se_descarga(): void
    {
        $this->tenant->update(['logo_url' => 'http://169.254.169.254/latest/meta-data/logo.png']);

        $pdf = $this->pdf();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    /**
     * Una URL de nuestro propio disco que apunta al logo de OTRA tienda no se
     * empotra: se leería un archivo ajeno para meterlo en el PDF propio. El
     * mismo archivo, en la carpeta de logo de la tienda, sí sale: la única
     * diferencia es de quién es.
     */
    public function test_no_empotra_un_archivo_de_otra_tienda(): void
    {
        // En una variable: el temporal de `fake()` se borra al soltar el objeto.
        $falsa = UploadedFile::fake()->image('logo.png', 200, 50);
        $imagen = file_get_contents($falsa->getRealPath());

        Storage::disk('public')->put('products/otra-tienda/logo/ajeno.png', $imagen);
        $this->tenant->update(['logo_url' => Storage::disk('public')->url('products/otra-tienda/logo/ajeno.png')]);

        $this->assertStringNotContainsString('/Subtype /Image', $this->pdf(), 'Empotró el logo de otra tienda.');

        Storage::disk('public')->put('products/tienda-logo/logo/propio.png', $imagen);
        $this->tenant->update(['logo_url' => Storage::disk('public')->url('products/tienda-logo/logo/propio.png')]);

        $this->assertStringContainsString('/Subtype /Image', $this->pdf());
    }

    /** Un archivo que ya no está en el disco deja el PDF sin logo, no sin PDF. */
    public function test_si_el_archivo_ya_no_existe_el_pdf_sale_sin_logo(): void
    {
        $this->tenant->update(['logo_url' => Storage::disk('public')->url('products/tienda-logo/logo/borrado.webp')]);

        $pdf = $this->pdf();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    // ------------------------------------------------------------ apoyo

    private function pdf(): string
    {
        $pedido = new Order(['customer_name' => 'Ana', 'customer_phone' => '51988877766', 'status' => 'pending', 'total' => 100]);
        $pedido->tenant_id = $this->tenant->id;
        $pedido->save();

        return $this->panel()->get("/api/orders/{$pedido->id}/pdf")->assertOk()->getContent();
    }

    private function panel(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('t', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tenant->slug,
            'Accept' => 'application/json',
        ]);
    }
}
