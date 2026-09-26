<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `UI-15`. `APP_LOCALE` era `en` y no había traducciones, así que una foto de más
 * rebotaba en el panel con "The image field must not be greater than 10240
 * kilobytes.": ni en el idioma de la interfaz ni en la unidad que el dueño tiene
 * en la cabeza. Ahora la aplicación está en español fijo (`config/app.php`), con
 * todas las reglas traducidas y los topes de archivo en MB.
 */
class MensajesEnEspanolTest extends TestCase
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
            'slug' => 'tienda-es', 'name' => 'Tienda ES', 'whatsapp_number' => '51999999999',
            'is_active' => true, 'is_published' => true, 'plan' => 'enterprise',
        ]);

        $this->admin = new User(['name' => 'Duenio', 'email' => 'duenio@es.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    // ------------------------------------------------------ la traducción

    /**
     * Si Laravel trae una regla que aquí no está, esa regla responde en inglés
     * sin que nada lo avise: el `fallback_locale` es `en`. Este test es el aviso,
     * también al actualizar Laravel.
     */
    public function test_la_traduccion_tiene_todas_las_reglas_de_laravel(): void
    {
        $deLaravel = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $nuestras = require lang_path('es/validation.php');

        $claves = fn (array $mensajes) => array_keys(Arr::dot(Arr::except($mensajes, ['custom', 'attributes'])));

        $this->assertSame(
            [],
            array_values(array_diff($claves($deLaravel), $claves($nuestras))),
            'Estas reglas de Laravel no tienen mensaje en lang/es/validation.php.',
        );
    }

    public function test_la_aplicacion_esta_en_espanol_aunque_el_entorno_diga_otra_cosa(): void
    {
        // Todos los .env creados antes de UI-15 traen APP_LOCALE=en.
        $this->assertSame('es', config('app.locale'));
        $this->assertSame('El campo nombre es obligatorio.', __('validation.required', ['attribute' => 'nombre']));
    }

    // ---------------------------------------------- lo que ve el panel

    /**
     * Con varios errores, `message` es el primero más un "(and 2 more errors)"
     * que también estaba en inglés. Y los campos salen con su nombre, no con el
     * técnico: "categoría", no "category id".
     */
    public function test_un_producto_mal_rellenado_responde_en_espanol(): void
    {
        $respuesta = $this->panel()->postJson('/api/products', ['price' => 'abc'])->assertStatus(422);

        $respuesta->assertJsonPath('message', 'El campo nombre es obligatorio. (y 2 errores más)');
        $respuesta->assertJsonPath('errors.name.0', 'El campo nombre es obligatorio.');
        $respuesta->assertJsonPath('errors.price.0', 'El campo precio debe ser un número.');
        $respuesta->assertJsonPath('errors.stock.0', 'El campo stock es obligatorio si no hay variantes.');
    }

    public function test_con_un_solo_error_no_anade_la_cuenta(): void
    {
        $this->panel()->postJson('/api/products', ['name' => 'X', 'price' => 10, 'stock' => 1, 'category_id' => 'no-es-uuid'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El campo categoría debe ser un UUID válido.');
    }

    // ------------------------------------------------ los topes en MB

    public function test_una_foto_de_mas_dice_el_tope_en_mb(): void
    {
        $this->panel()->post('/api/products', [
            'name' => 'RTX', 'price' => 10, 'stock' => 1,
            'image' => UploadedFile::fake()->image('foto.jpg')->size(10241),
        ])->assertStatus(422)->assertJsonPath('errors.image.0', 'El campo imagen no puede pesar más de 10 MB.');
    }

    /** En una lista, el mensaje dice cuál: el número que el dueño ve, contando desde 1. */
    public function test_la_foto_de_la_galeria_que_sobra_se_nombra_por_su_posicion(): void
    {
        $this->panel()->post('/api/products', [
            'name' => 'RTX', 'price' => 10, 'stock' => 1,
            'gallery' => [
                UploadedFile::fake()->image('bien.jpg')->size(100),
                UploadedFile::fake()->image('grande.jpg')->size(20000),
            ],
        ])->assertStatus(422)->assertJsonPath('errors', [
            'gallery.1' => ['El campo foto 2 de la galería no puede pesar más de 10 MB.'],
        ]);
    }

    public function test_la_foto_de_una_variante_se_nombra_por_su_fila(): void
    {
        $this->panel()->post('/api/products', [
            'name' => 'Gabinete',
            'variants' => json_encode([
                ['options' => [['name' => 'Color', 'value' => 'Negro']], 'price' => 90, 'stock' => 3],
                ['options' => [['name' => 'Color', 'value' => 'Blanco']], 'price' => 95, 'stock' => 2],
            ]),
            'variant_images' => [1 => UploadedFile::fake()->image('blanco.jpg')->size(10241)],
        ])->assertStatus(422)->assertJsonPath('errors', [
            'variant_images.1' => ['El campo foto de la variante 2 no puede pesar más de 10 MB.'],
        ]);
    }

    public function test_el_logo_y_el_favicon_dicen_su_propio_tope(): void
    {
        $this->panel()->post('/api/tenant', [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png')->size(2049),
            'favicon' => UploadedFile::fake()->create('favicon.png', 513, 'image/png'),
        ])->assertStatus(422)
            ->assertJsonPath('errors.logo.0', 'El campo logo no puede pesar más de 2 MB.')
            // Por debajo de 1 MB, en KB: "0,5 MB" se entiende peor.
            ->assertJsonPath('errors.favicon.0', 'El campo favicon no puede pesar más de 512 KB.');
    }

    public function test_el_csv_de_mas_dice_su_tope(): void
    {
        $this->panel()->post('/api/products/import', [
            'file' => UploadedFile::fake()->create('catalogo.csv', 4097, 'text/csv'),
        ])->assertStatus(422)->assertJsonPath('errors.file.0', 'El campo archivo no puede pesar más de 4 MB.');
    }

    /**
     * Cuando PHP corta la subida por su propio tope (`upload_max_filesize`,
     * INF-12), el archivo llega marcado como no subido y Laravel no mira ni su
     * tamaño: responde con `uploaded`. El mensaje dice la causa probable, porque
     * el archivo puede estar dentro del tope de `config/subidas.php` y no entrar.
     */
    public function test_un_archivo_que_php_corto_dice_que_no_se_pudo_subir(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'ui15');
        file_put_contents($ruta, 'x');
        $cortado = new UploadedFile($ruta, 'foto.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->panel()->post('/api/products', ['name' => 'RTX', 'price' => 10, 'stock' => 1, 'image' => $cortado])
            ->assertStatus(422)
            ->assertJsonPath('errors.image.0', 'El campo imagen no se pudo subir: puede que pese más de lo que admite el servidor.');
    }

    /** El reemplazo de `:tamano` no puede romper el `:max` del resto de mensajes. */
    public function test_el_max_de_un_texto_sigue_diciendo_caracteres(): void
    {
        $this->panel()->postJson('/api/products', ['name' => str_repeat('x', 301), 'price' => 10, 'stock' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'El campo nombre no puede tener más de 300 caracteres.');
    }

    // ------------------------------------ los 404 y 405 de Laravel

    /**
     * Guardar algo que otra pestaña acaba de borrar. Antes: "No query results
     * for model [App\Models\Product] ...", en inglés y con el nombre de la clase.
     */
    public function test_un_registro_que_no_existe_responde_en_espanol_y_sin_la_clase(): void
    {
        $respuesta = $this->panel()->putJson('/api/products/01a0dada-0000-7000-8000-000000000000', ['name' => 'X'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No se encontró: puede que se haya borrado.');

        $this->assertStringNotContainsString('App\\Models', $respuesta->getContent());
    }

    public function test_una_ruta_que_no_existe_responde_en_espanol(): void
    {
        $this->panel()->getJson('/api/no-existe')
            ->assertNotFound()
            ->assertJsonPath('message', 'Esa dirección no existe en la API.');
    }

    public function test_un_metodo_no_admitido_responde_en_espanol(): void
    {
        $this->panel()->deleteJson('/api/dashboard/stats')
            ->assertStatus(405)
            ->assertJsonPath('message', 'Esa dirección de la API no admite esta operación.');
    }

    /** Control: un 404 con texto propio sale tal cual. */
    public function test_un_404_con_texto_propio_no_se_toca(): void
    {
        $this->getJson('/api/public/tienda-que-no-existe')
            ->assertNotFound()
            ->assertJsonPath('message', 'Tienda no encontrada o inactiva.');
    }

    // ------------------------------------------------------ los correos

    /**
     * La plantilla de correo de Laravel pone su propia despedida si la
     * notificación no trae una, y siempre un texto bajo el botón y un pie: los
     * tres en inglés hasta UI-15. Este correo es de los que no traen despedida.
     */
    public function test_el_correo_no_lleva_los_textos_de_la_plantilla_en_ingles(): void
    {
        $pedido = new Order(['customer_name' => 'Ana', 'customer_phone' => '51988877766', 'status' => 'pending', 'total' => 100]);
        $pedido->tenant_id = $this->tenant->id;
        $pedido->save();

        $html = (string) (new NewOrderNotification($pedido->fresh()->load('items')))->toMail($this->admin)->render();

        $this->assertStringContainsString('Saludos,', $html);
        $this->assertStringContainsString('copia y pega esta dirección en tu navegador', $html);
        $this->assertStringContainsString('Todos los derechos reservados.', $html);

        foreach (['Regards', 'trouble clicking', 'All rights reserved', 'Hello!'] as $ingles) {
            $this->assertStringNotContainsString($ingles, $html);
        }
    }

    // ------------------------------------------------------------ apoyo

    private function panel(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('t', ['admin'])->plainTextToken,
            'X-Tenant'      => $this->tenant->slug,
            'Accept'        => 'application/json',
        ]);
    }
}
