<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fija TEC-14: un archivo de foto no se borra mientras otra fila lo use.
 *
 * Duplicar un producto copia las URL de sus fotos, no los archivos. Antes, borrar
 * el original —o la copia, o cambiarle la foto a uno— borraba los archivos que el
 * otro seguía mostrando. Estos casos trabajan con archivos de verdad en un disco
 * falso y miran si siguen ahí.
 */
class ImagenesCompartidasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $duenio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');

        $this->tienda = Tenant::create([
            'slug' => 'tienda-a', 'name' => 'Tienda A',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->duenio = new User([
            'name' => 'Duenio', 'email' => 'duenio@tienda-a.com',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->duenio->tenant_id = $this->tienda->id;
        $this->duenio->save();
    }

    private function comoDuenio(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->duenio->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }

    private function foto(string $nombre = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, 600, 600);
    }

    /** Ruta en el disco a partir de la URL que guarda la fila. */
    private function ruta(string $url): string
    {
        return substr(ltrim((string) parse_url($url, PHP_URL_PATH), '/'), strlen('storage/'));
    }

    private function assertExiste(?string $url): void
    {
        $this->assertNotNull($url);
        Storage::disk('public')->assertExists($this->ruta($url));
    }

    private function assertNoExiste(?string $url): void
    {
        $this->assertNotNull($url);
        Storage::disk('public')->assertMissing($this->ruta($url));
    }

    /** Un producto con foto principal, una foto de galería y una variante con foto, y su copia. */
    private function productoConCopia(): array
    {
        $respuesta = $this->comoDuenio()->post('/api/products', [
            'name' => 'Gabinete',
            'image' => $this->foto('principal.jpg'),
            'gallery' => [$this->foto('galeria.jpg')],
            'variants' => json_encode([
                ['options' => [['name' => 'Color', 'value' => 'Blanco']], 'price' => 90, 'stock' => 2],
            ]),
            'variant_images' => [0 => $this->foto('blanco.jpg')],
        ])->assertCreated();

        $original = Product::withoutTenant()->findOrFail($respuesta->json('id'));
        $copiaId = $this->comoDuenio()->postJson("/api/products/{$original->id}/duplicate")->assertCreated()->json('id');

        return [$original, Product::withoutTenant()->findOrFail($copiaId)];
    }

    /** @return array<int, string|null> principal, miniatura, galería y variante de un producto */
    private function urlsDe(Product $producto): array
    {
        $galeria = ProductImage::where('product_id', $producto->id)->first();
        $variante = ProductVariant::withoutTenant()->where('product_id', $producto->id)->first();

        return [$producto->image_url, $producto->thumbnail_url, $galeria?->image_url, $variante?->image_url];
    }

    public function test_borrar_el_original_no_rompe_las_fotos_de_la_copia(): void
    {
        [$original, $copia] = $this->productoConCopia();
        $urls = $this->urlsDe($copia);

        // La copia comparte las cuatro URL: es justo el caso del fallo.
        $this->assertSame($this->urlsDe($original), $urls);

        $this->comoDuenio()->deleteJson("/api/products/{$original->id}")->assertNoContent();

        foreach ($urls as $url) {
            $this->assertExiste($url);
        }
    }

    public function test_borrar_el_ultimo_que_las_usa_si_borra_los_archivos(): void
    {
        [$original, $copia] = $this->productoConCopia();
        $urls = $this->urlsDe($copia);

        $this->comoDuenio()->deleteJson("/api/products/{$original->id}")->assertNoContent();
        $this->comoDuenio()->deleteJson("/api/products/{$copia->id}")->assertNoContent();

        // Sin nadie que las use, no quedan archivos huérfanos.
        foreach ($urls as $url) {
            $this->assertNoExiste($url);
        }
    }

    public function test_borrar_en_lote_el_original_y_la_copia_borra_los_archivos(): void
    {
        [$original, $copia] = $this->productoConCopia();
        $urls = $this->urlsDe($copia);

        $this->comoDuenio()->postJson('/api/products/bulk', [
            'product_ids' => [$original->id, $copia->id],
            'bulk_action' => 'delete',
        ])->assertOk();

        foreach ($urls as $url) {
            $this->assertNoExiste($url);
        }
    }

    public function test_borrar_en_lote_solo_el_original_conserva_las_fotos_de_la_copia(): void
    {
        [$original, $copia] = $this->productoConCopia();
        $urls = $this->urlsDe($copia);

        $this->comoDuenio()->postJson('/api/products/bulk', [
            'product_ids' => [$original->id],
            'bulk_action' => 'delete',
        ])->assertOk();

        foreach ($urls as $url) {
            $this->assertExiste($url);
        }
    }

    public function test_cambiar_la_foto_principal_de_la_copia_no_borra_la_del_original(): void
    {
        [$original, $copia] = $this->productoConCopia();
        [$principal, $miniatura] = $this->urlsDe($original);

        $this->comoDuenio()->post("/api/products/{$copia->id}", [
            '_method' => 'PUT',
            'image' => $this->foto('nueva.jpg'),
        ])->assertOk();

        $this->assertNotSame($principal, $copia->fresh()->image_url);
        $this->assertExiste($principal);
        $this->assertExiste($miniatura);
        $this->assertExiste($copia->fresh()->image_url);
    }

    public function test_quitar_una_foto_de_galeria_o_de_variante_en_la_copia_no_la_borra_del_original(): void
    {
        [$original, $copia] = $this->productoConCopia();
        [, , $galeria, $fotoVariante] = $this->urlsDe($original);

        $imagenDeLaCopia = ProductImage::where('product_id', $copia->id)->first();
        $varianteDeLaCopia = ProductVariant::withoutTenant()->where('product_id', $copia->id)->first();

        $this->comoDuenio()->post("/api/products/{$copia->id}", [
            '_method' => 'PUT',
            'deleted_image_ids' => json_encode([$imagenDeLaCopia->id]),
            'variants' => json_encode([[
                'id' => $varianteDeLaCopia->id,
                'options' => [['name' => 'Color', 'value' => 'Blanco']],
                'price' => 90,
                'stock' => 2,
                'remove_image' => true,
            ]]),
        ])->assertOk();

        $this->assertNull($varianteDeLaCopia->fresh()->image_url);
        $this->assertSame(0, ProductImage::where('product_id', $copia->id)->count());
        $this->assertExiste($galeria);
        $this->assertExiste($fotoVariante);
    }

    public function test_un_producto_que_no_comparte_nada_sigue_borrando_sus_archivos(): void
    {
        $respuesta = $this->comoDuenio()->post('/api/products', [
            'name' => 'Solo',
            'price' => 10,
            'stock' => 1,
            'image' => $this->foto(),
        ])->assertCreated();

        $producto = Product::withoutTenant()->findOrFail($respuesta->json('id'));

        $this->comoDuenio()->post("/api/products/{$producto->id}", [
            '_method' => 'PUT',
            'image' => $this->foto('otra.jpg'),
        ])->assertOk();

        // Reemplazar la foto borra la vieja: nadie más la usaba.
        $this->assertNoExiste($producto->image_url);
        $this->assertNoExiste($producto->thumbnail_url);
    }
}
