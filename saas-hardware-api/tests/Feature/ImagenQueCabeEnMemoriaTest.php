<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Subidas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `INF-14`. GD decodifica cada foto entera, y lo que pide de memoria depende de
 * sus píxeles, no de lo que pesa el archivo: una de 48 MP pesa 8,6 MB —dentro
 * del tope de 10— y pide 210 MB. Con el `memory_limit` de fábrica (128M) PHP
 * moría a mitad del guardado y el dueño veía un "Server Error".
 *
 * La foto de prueba es un PNG con solo la cabecera: declara 30000 × 30000 px
 * (900 MP) y no tiene píxeles. `getimagesize()` lee las dimensiones de la
 * cabecera sin decodificar nada, que es justo lo que hace la comprobación —y lo
 * que permite rechazarla antes de gastar la memoria—.
 */
class ImagenQueCabeEnMemoriaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $duenio;

    private string|false $memoriaAntes;

    protected function setUp(): void
    {
        parent::setUp();

        // Un límite conocido: en CI suele ser -1 (sin límite) y la comprobación
        // no tendría nada que comparar. 1G da 201 MP.
        $this->memoriaAntes = ini_get('memory_limit');
        ini_set('memory_limit', '1G');

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');

        $this->tienda = Tenant::create([
            'slug' => 'tienda-memoria', 'name' => 'Tienda Memoria', 'plan' => 'enterprise',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->duenio = new User([
            'name' => 'Duenio', 'email' => 'duenio@memoria.test',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->duenio->tenant_id = $this->tienda->id;
        $this->duenio->save();
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', (string) $this->memoriaAntes);

        parent::tearDown();
    }

    /** Las cuentas contra lo medido: 128M no llega a 24 MP, 256M sí pasa de 24 y no llega a 48. */
    public function test_cuantos_megapixeles_caben_en_cada_memory_limit(): void
    {
        $this->assertSame(13, Subidas::megapixelesProcesables('128M'));
        $this->assertSame(40, Subidas::megapixelesProcesables('256M'));
        $this->assertSame(93, Subidas::megapixelesProcesables('512M'));
        $this->assertSame(201, Subidas::megapixelesProcesables('1G'));
        $this->assertSame(93, Subidas::megapixelesProcesables('536870912'));
        $this->assertNull(Subidas::megapixelesProcesables('-1'));
    }

    public function test_una_foto_que_no_cabe_se_rechaza_antes_de_procesarla(): void
    {
        $this->comoDuenio()->post('/api/products', [
            'name' => 'Placa madre', 'price' => 500, 'stock' => 3,
            'image' => $this->pngDe(30000, 30000),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.image.0',
                'El campo imagen mide 30000 × 30000 px (900 MP) y el servidor solo puede procesar fotos de hasta 201 MP. '
                .'Redúcela antes de subirla.');

        $this->assertSame(0, Product::withoutTenant()->count());
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    /** La galería y las variantes llevan la misma regla, con su nombre en el mensaje. */
    public function test_vale_para_la_galeria(): void
    {
        $this->comoDuenio()->post('/api/products', [
            'name' => 'Placa madre', 'price' => 500, 'stock' => 3,
            'gallery' => [$this->foto(), $this->pngDe(30000, 30000)],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn ($errores) => str_starts_with($errores['gallery.1'][0] ?? '', 'El campo foto 2 de la galería mide 30000 × 30000 px'));
    }

    /** El logo y la portada pasan por el mismo proceso que las fotos de producto. */
    public function test_vale_para_el_logo(): void
    {
        $this->comoDuenio()->post('/api/tenant', [
            '_method' => 'PUT',
            'logo' => $this->pngDe(30000, 30000),
        ])->assertUnprocessable()->assertJsonValidationErrors('logo');
    }

    /** Control: una foto normal pasa y se procesa. */
    public function test_una_foto_normal_pasa(): void
    {
        $this->comoDuenio()->post('/api/products', [
            'name' => 'Placa madre', 'price' => 500, 'stock' => 3,
            'image' => $this->foto(),
        ])->assertCreated();
    }

    /** Sin límite de memoria no hay nada que comparar: no se rechaza por esto. */
    public function test_sin_memory_limit_no_se_comprueba(): void
    {
        ini_set('memory_limit', '-1');

        $respuesta = $this->comoDuenio()->post('/api/products', [
            'name' => 'Placa madre', 'price' => 500, 'stock' => 3,
            'image' => $this->pngDe(30000, 30000),
        ]);

        // Pasa la validación; lo que falle después es decodificar un PNG sin
        // píxeles, que no es lo que se prueba aquí.
        $this->assertArrayNotHasKey('image', $respuesta->json('errors') ?? []);
    }

    // ------------------------------------------------------------ apoyo

    /** Un PNG de solo cabecera: firma y bloque IHDR con las dimensiones. */
    private function pngDe(int $ancho, int $alto): UploadedFile
    {
        $ihdr = pack('NNCCCCC', $ancho, $alto, 8, 6, 0, 0, 0);
        $bloque = pack('N', strlen($ihdr)).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        return UploadedFile::fake()->createWithContent('bomba.png', "\x89PNG\r\n\x1a\n".$bloque);
    }

    private function foto(): UploadedFile
    {
        return UploadedFile::fake()->image('foto.jpg', 600, 400);
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
