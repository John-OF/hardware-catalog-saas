<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `INF-12`. PHP descarta en silencio los archivos de una petición que pasan de
 * `max_file_uploads`, y un guardado de producto puede llevar la foto principal,
 * la galería y una por variante: el producto se guardaba con menos fotos de las
 * elegidas y sin ningún error.
 *
 * En una prueba PHP no descarta nada —los archivos no pasan por el SAPI—, así
 * que el recorte se simula al revés: el formulario dice haber mandado más de
 * los que llegan, que es exactamente lo que ve el servidor cuando PHP recorta.
 */
class ArchivosRecibidosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $duenio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');

        // Enterprise: la galería sin tope, que es donde el recorte de PHP muerde.
        $this->tienda = Tenant::create([
            'slug' => 'tienda-fotos', 'name' => 'Tienda Fotos', 'plan' => 'enterprise',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->duenio = new User([
            'name' => 'Duenio', 'email' => 'duenio@fotos.test',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->duenio->tenant_id = $this->tienda->id;
        $this->duenio->save();
    }

    public function test_si_llegaron_todas_se_guarda(): void
    {
        $this->comoDuenio()->post('/api/products', [
            ...$this->producto(),
            'image' => $this->foto(),
            'gallery' => [$this->foto(), $this->foto()],
            'archivos_enviados' => 3,
        ])->assertCreated();

        $this->assertSame(1, Product::withoutTenant()->count());
    }

    public function test_si_faltan_fotos_no_se_guarda_nada_y_dice_cuantas_llegaron(): void
    {
        Log::spy();

        $this->comoDuenio()->post('/api/products', [
            ...$this->producto(),
            'image' => $this->foto(),
            'gallery' => [$this->foto(), $this->foto()],
            'archivos_enviados' => 5,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.archivos_enviados.0', 'Solo llegaron 3 de las 5 fotos. Vuelve a elegirlas y guarda de nuevo.');

        // Nada a medias: ni el producto sin sus fotos.
        $this->assertSame(0, Product::withoutTenant()->count());
        Storage::disk('public')->assertDirectoryEmpty('/');

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($mensaje, $contexto) => $contexto['enviados'] === 5 && $contexto['recibidos'] === 3,
        );
    }

    /** Cuando llegan justo `max_file_uploads`, el mensaje nombra el tope y qué hacer. */
    public function test_en_el_tope_de_php_dice_cuantas_admite_el_servidor(): void
    {
        $tope = (int) ini_get('max_file_uploads');

        $this->comoDuenio()->post('/api/products', [
            ...$this->producto(),
            'gallery' => array_map(fn () => $this->foto(), range(1, $tope)),
            'archivos_enviados' => $tope + 5,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.archivos_enviados.0',
                'Solo llegaron '.$tope.' de las '.($tope + 5).' fotos: el servidor admite '.$tope.' por envío. '
                .'Guarda con menos fotos nuevas y añade el resto editando el producto.');
    }

    /** Las fotos de variantes cuentan: son las últimas del formulario, las primeras que PHP tira. */
    public function test_cuenta_las_fotos_de_las_variantes(): void
    {
        $this->comoDuenio()->post('/api/products', [
            'name' => 'Gabinete',
            'variants' => json_encode([
                ['options' => [['name' => 'Color', 'value' => 'Blanco']], 'price' => 90, 'stock' => 2],
                ['options' => [['name' => 'Color', 'value' => 'Negro']], 'price' => 90, 'stock' => 2],
            ]),
            'variant_images' => [0 => $this->foto(), 1 => $this->foto()],
            'archivos_enviados' => 2,
        ])->assertCreated();
    }

    public function test_al_editar_tambien_se_comprueba(): void
    {
        $id = $this->comoDuenio()->post('/api/products', $this->producto())->assertCreated()->json('id');

        $this->comoDuenio()->post("/api/products/{$id}", [
            '_method' => 'PUT',
            ...$this->producto(),
            'gallery' => [$this->foto()],
            'archivos_enviados' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('archivos_enviados');

        $this->assertSame(0, Product::withoutTenant()->findOrFail($id)->images()->count());
    }

    /** Sin el recuento (otro cliente, una versión vieja del panel) no se comprueba nada. */
    public function test_sin_recuento_no_se_comprueba(): void
    {
        $this->comoDuenio()->post('/api/products', [
            ...$this->producto(),
            'gallery' => [$this->foto()],
        ])->assertCreated();
    }

    // ------------------------------------------------------------ apoyo

    private function producto(): array
    {
        return ['name' => 'Placa madre', 'price' => 500, 'stock' => 3];
    }

    private function foto(): UploadedFile
    {
        return UploadedFile::fake()->image('foto.jpg', 60, 60);
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
