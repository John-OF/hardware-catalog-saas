<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `TEC-15`. Las imágenes subidas se guardaban como la URL que da `Storage::url()`,
 * con el dominio del almacén escrito en cada fila: cambiar de bucket, de CDN o
 * de disco las rompía todas a la vez. Ahora se guarda la ruta dentro del disco
 * y la URL se arma al leer.
 *
 * Lo que más se vigila aquí no es la conversión sino lo que puede romper:
 * `borrarSiNadieLasUsa()` decide si un archivo sigue en uso comparando lo que
 * hay en la base, y con filas de antes (URL completa) y de después (ruta)
 * conviviendo, comparar una sola forma daría "nadie la usa" con la foto en uso.
 */
class ImagenesComoRutaTest extends TestCase
{
    use RefreshDatabase;

    private const CDN_VIEJO = 'https://cdn-viejo.test/storage';

    private const CDN_NUEVO = 'https://cdn-nuevo.test/storage';

    private Tenant $tienda;

    private User $duenio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public', ['url' => self::CDN_VIEJO]);

        $this->tienda = Tenant::create([
            'slug' => 'tienda-rutas', 'name' => 'Tienda Rutas', 'plan' => 'enterprise',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->duenio = new User([
            'name' => 'Duenio', 'email' => 'duenio@rutas.test',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->duenio->tenant_id = $this->tienda->id;
        $this->duenio->save();
    }

    public function test_una_foto_subida_se_guarda_como_ruta_y_se_lee_como_url(): void
    {
        $id = $this->productoConFoto();

        $crudo = DB::table('products')->where('id', $id)->first();
        $this->assertMatchesRegularExpression('#^products/tienda-rutas/[0-9a-f-]+\.webp$#', $crudo->image_url);
        $this->assertMatchesRegularExpression('#^products/tienda-rutas/[0-9a-f-]+_thumb\.webp$#', $crudo->thumbnail_url);

        $this->comoDuenio()->getJson("/api/products/{$id}")
            ->assertOk()
            ->assertJsonPath('image_url', self::CDN_VIEJO.'/'.$crudo->image_url);
    }

    /** Lo que TEC-15 promete: cambiar el dominio del almacén no rompe ninguna foto. */
    public function test_cambiar_el_dominio_del_disco_no_rompe_las_fotos(): void
    {
        $id = $this->productoConFoto();
        $ruta = DB::table('products')->where('id', $id)->value('image_url');

        Storage::fake('public', ['url' => self::CDN_NUEVO]);

        $this->comoDuenio()->getJson("/api/products/{$id}")
            ->assertOk()
            ->assertJsonPath('image_url', self::CDN_NUEVO.'/'.$ruta);
    }

    /** Lo de antes de migrar, y lo que no es del disco, se lee y se guarda igual. */
    public function test_una_url_que_no_es_del_disco_se_queda_como_esta(): void
    {
        $this->tienda->update(['logo_url' => 'https://otra-web.test/logo.png']);
        $this->assertSame('https://otra-web.test/logo.png', DB::table('tenants')->where('id', $this->tienda->id)->value('logo_url'));
        $this->assertSame('https://otra-web.test/logo.png', $this->tienda->fresh()->logo_url);

        // Una fila de antes, con la URL completa de un dominio que ya no es el del disco.
        $id = $this->productoConFoto();
        DB::table('products')->where('id', $id)->update(['image_url' => 'https://dominio-anterior.test/storage/products/x.webp']);
        $this->assertSame('https://dominio-anterior.test/storage/products/x.webp', Product::withoutTenant()->find($id)->image_url);
    }

    /**
     * El que puede borrar fotos en uso: dos productos comparten un archivo (un
     * duplicado), uno guardado como URL completa —de antes de migrar— y el otro
     * como ruta. Que uno suelte la foto no puede borrar el archivo del otro.
     *
     * Quien llama a `borrarSiNadieLasUsa()` ya ha quitado su referencia (cambió
     * la foto, vació la papelera): por eso cada paso suelta primero y pide después.
     */
    public function test_con_filas_de_antes_y_de_despues_no_borra_una_foto_en_uso(): void
    {
        $nuevo = $this->productoConFoto();
        $ruta = DB::table('products')->where('id', $nuevo)->value('image_url');
        $url = self::CDN_VIEJO.'/'.$ruta;

        $viejo = $this->productoConFoto();

        // El de antes (URL completa) la suelta; el nuevo (ruta) la sigue usando.
        DB::table('products')->where('id', $viejo)->update(['image_url' => null]);
        app(ImageService::class)->borrarSiNadieLasUsa([$url]);
        Storage::disk('public')->assertExists($ruta);

        // Al revés: el nuevo (ruta) la suelta; el de antes (URL completa) la usa.
        DB::table('products')->where('id', $viejo)->update(['image_url' => $url]);
        DB::table('products')->where('id', $nuevo)->update(['image_url' => null]);
        app(ImageService::class)->borrarSiNadieLasUsa([$url]);
        Storage::disk('public')->assertExists($ruta);

        // Sin nadie que la use, sí se borra.
        DB::table('products')->where('id', $viejo)->update(['image_url' => null]);
        app(ImageService::class)->borrarSiNadieLasUsa([$url]);
        Storage::disk('public')->assertMissing($ruta);
    }

    public function test_el_logo_y_la_portada_tambien(): void
    {
        $this->comoDuenio()->post('/api/tenant', [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 100),
            'banner' => UploadedFile::fake()->image('portada.jpg', 1200, 400),
        ])->assertOk();

        $crudo = DB::table('tenants')->where('id', $this->tienda->id)->first();
        $this->assertStringStartsWith('products/tienda-rutas/logo/', $crudo->logo_url);
        $this->assertStringStartsWith('products/tienda-rutas/banner/', json_decode($crudo->theme, true)['banner_url']);

        $tienda = $this->tienda->fresh();
        $this->assertSame(self::CDN_VIEJO.'/'.$crudo->logo_url, $tienda->logo_url);
        $this->assertStringStartsWith(self::CDN_VIEJO.'/products/tienda-rutas/banner/', $tienda->theme['banner_url']);
    }

    /** La migración convierte lo del disco, deja lo demás, y se deshace. */
    public function test_la_migracion_convierte_lo_de_antes_y_se_deshace(): void
    {
        $id = $this->productoConFoto();
        $ruta = DB::table('products')->where('id', $id)->value('image_url');
        DB::table('products')->where('id', $id)->update(['image_url' => self::CDN_VIEJO.'/'.$ruta]);
        DB::table('tenants')->where('id', $this->tienda->id)->update([
            'logo_url' => 'https://otra-web.test/logo.png',
            'theme' => json_encode(['preset' => 'x', 'banner_url' => self::CDN_VIEJO.'/products/tienda-rutas/banner/p.webp']),
        ]);

        $migracion = require database_path('migrations/2026_09_26_100000_guardar_imagenes_como_ruta_del_disco.php');

        $migracion->up();
        $this->assertSame($ruta, DB::table('products')->where('id', $id)->value('image_url'));
        $tienda = DB::table('tenants')->where('id', $this->tienda->id)->first();
        $this->assertSame('https://otra-web.test/logo.png', $tienda->logo_url);
        $this->assertSame(['preset' => 'x', 'banner_url' => 'products/tienda-rutas/banner/p.webp'], json_decode($tienda->theme, true));

        $migracion->down();
        $this->assertSame(self::CDN_VIEJO.'/'.$ruta, DB::table('products')->where('id', $id)->value('image_url'));
        $this->assertSame(self::CDN_VIEJO.'/products/tienda-rutas/banner/p.webp',
            json_decode(DB::table('tenants')->where('id', $this->tienda->id)->value('theme'), true)['banner_url']);
    }

    // ------------------------------------------------------------ apoyo

    private function productoConFoto(): string
    {
        return $this->comoDuenio()->post('/api/products', [
            'name' => 'Placa '.uniqid(), 'price' => 500, 'stock' => 3,
            'image' => UploadedFile::fake()->image('foto.jpg', 600, 400),
        ])->assertCreated()->json('id');
    }

    private function comoDuenio(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->duenio->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }
}
