<?php

namespace Tests\Feature;

use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TEC-10, segunda mitad: una subida que falla tiene que notarse.
 *
 * El disco `r2` esta configurado con `'throw' => false` y `'report' => false`,
 * asi que un `put()` fallido devuelve `false` sin excepcion y sin log. Antes
 * nadie miraba ese valor: se pedia la URL y se devolvia igual, y el producto
 * acababa guardado apuntando a un fichero que nunca se escribio. El fallo
 * aparecia despues, como una imagen rota en el catalogo, sin nada que lo
 * relacionase con la subida.
 */
class ImageStorageTest extends TestCase
{
    public function test_una_subida_que_falla_lanza_en_vez_de_devolver_una_url_falsa(): void
    {
        // Un disco que dice que no a todo, que es lo que hace R2 con las
        // credenciales mal puestas: false, sin excepcion.
        Storage::shouldReceive('disk')->andReturn($discoQueFalla = \Mockery::mock());
        $discoQueFalla->shouldReceive('put')->andReturn(false);
        $discoQueFalla->shouldNotReceive('url');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No se pudo guardar la imagen/');

        (new ImageService)->uploadProductImage(
            UploadedFile::fake()->image('gpu.jpg'),
            'tienda-demo'
        );
    }

    public function test_una_subida_correcta_devuelve_las_dos_urls(): void
    {
        Storage::fake('public');

        $urls = (new ImageService)->uploadProductImage(
            UploadedFile::fake()->image('gpu.jpg', 1600, 1600),
            'tienda-demo'
        );

        $this->assertArrayHasKey('image_url', $urls);
        $this->assertArrayHasKey('thumbnail_url', $urls);

        // Las dos rutas existen de verdad en el disco, no solo en la respuesta.
        foreach (['image_url', 'thumbnail_url'] as $clave) {
            $ruta = ltrim(parse_url($urls[$clave], PHP_URL_PATH), '/');
            $ruta = str_starts_with($ruta, 'storage/') ? substr($ruta, 8) : $ruta;
            Storage::disk('public')->assertExists($ruta);
        }
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
