<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Las specs con nombre fijo por tipo de componente (FUN-8, pasos 13.2 y 13.3).
 *
 * Hasta aqui las specs eran texto libre y el armador las buscaba por
 * aproximacion; con un "Socket AM5" en la placa y un "AM5" en el procesador
 * gritaba "incompatible" sobre un armado correcto. Ahora cada tipo PREGUNTA sus
 * campos (`config/component_specs.php`) y lo que se guarda tiene una sola forma.
 *
 * Lo que se fija aqui:
 *  1. La definicion es coherente y se sirve sin tienda (13.2).
 *  2. Guardar normaliza lo que una persona escribiria y rechaza lo que no
 *     reconoce, nombrando el campo; que falte un campo NO impide guardar.
 *  3. El import CSV aplica las mismas reglas que el formulario.
 *  4. La vista para buscadores pinta etiqueta y unidad, no la clave interna.
 */
class SpecsFijasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-specs',
            'name'            => 'Tienda Specs',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
            'is_published'    => true,
            'plan'            => 'enterprise',
        ]);

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-specs.test',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    private function categoria(string $nombre, string $tipo): Category
    {
        $categoria = new Category(['name' => $nombre, 'component_type' => $tipo, 'is_active' => true]);
        $categoria->tenant_id = $this->tenant->id;
        $categoria->save();

        return $categoria;
    }

    private function cabeceras(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->admin->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant'      => $this->tenant->slug,
            'Accept'        => 'application/json',
        ];
    }

    /** Como lo manda el panel: multipart, con las specs como texto JSON. */
    private function crear(array $specs, ?Category $categoria): TestResponse
    {
        return $this->withHeaders($this->cabeceras())->post('/api/products', [
            'name'        => 'Producto '.uniqid(),
            'price'       => '100',
            'stock'       => '5',
            'category_id' => $categoria?->id ?? '',
            'specs'       => json_encode($specs),
        ]);
    }

    private function editar(Product $producto, array $datos): TestResponse
    {
        return $this->withHeaders($this->cabeceras())->post("/api/products/{$producto->id}", [
            '_method' => 'PUT',
            ...$datos,
        ]);
    }

    private function importar(string $csv, string $modo = 'omitir'): TestResponse
    {
        return $this->withHeaders($this->cabeceras())->post('/api/products/import', [
            'file' => UploadedFile::fake()->createWithContent('productos.csv', $csv),
            'modo' => $modo,
        ]);
    }

    private function existente(array $atributos): Product
    {
        $producto = new Product($atributos + ['price' => 100, 'stock' => 1, 'is_active' => true, 'status' => 'published']);
        $producto->tenant_id = $this->tenant->id;
        $producto->save();

        return $producto;
    }

    private function specsDe(string $nombre): ?array
    {
        return Product::withoutTenant()->where('tenant_id', $this->tenant->id)->where('name', $nombre)->first()?->specs;
    }

    // ------------------------------------------------------------ la definicion

    public function test_la_definicion_se_sirve_sin_tienda_ni_sesion(): void
    {
        $respuesta = $this->getJson('/api/public/component-specs')->assertOk();

        $socket = collect($respuesta->json('tipos.cpu'))->firstWhere('clave', 'socket');

        $this->assertSame('Socket', $socket['etiqueta']);
        $this->assertSame('lista', $socket['tipo']);
        $this->assertContains('AM5', $socket['opciones']);
        $this->assertTrue($socket['armador']);
    }

    /** Un tipo de la config que no es paso del armador no lo pediria nadie. */
    public function test_la_definicion_cubre_exactamente_los_pasos_del_armador(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (ComponentType $t) => $t->value, ComponentType::deArmador()),
            array_keys(config('component_specs.tipos')),
        );
    }

    public function test_cada_campo_tiene_un_tipo_conocido_y_su_lista_existe(): void
    {
        $listas = config('component_specs.listas');

        foreach (config('component_specs.tipos') as $tipo => $campos) {
            foreach ($campos as $clave => $campo) {
                $donde = "{$tipo}.{$clave}";

                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $clave, "{$donde}: la clave es el nombre guardado, en minusculas y sin espacios.");
                $this->assertContains($campo['tipo'], ['lista', 'varios', 'numero', 'si_no', 'texto'], "{$donde}: tipo desconocido.");
                $this->assertNotEmpty($campo['etiqueta'], "{$donde}: sin etiqueta.");

                if (in_array($campo['tipo'], ['lista', 'varios'], true)) {
                    $this->assertArrayHasKey($campo['opciones'] ?? '', $listas, "{$donde}: nombra una lista que no existe.");
                }
            }
        }
    }

    /**
     * La ficha publica pinta una spec sin saber el tipo del producto; por eso
     * una clave repetida entre tipos tiene que decir lo mismo en todos.
     */
    public function test_una_clave_repetida_entre_tipos_se_pinta_igual_en_todos(): void
    {
        $vistas = [];

        foreach (config('component_specs.tipos') as $tipo => $campos) {
            foreach ($campos as $clave => $campo) {
                $forma = [$campo['etiqueta'], $campo['tipo'], $campo['unidad'] ?? null];

                if (isset($vistas[$clave])) {
                    $this->assertSame($vistas[$clave], $forma, "«{$clave}» se pinta distinto en {$tipo}.");
                }

                $vistas[$clave] = $forma;
            }
        }
    }

    // ------------------------------------------------------------ el formulario

    public function test_guardar_normaliza_lo_que_escribiria_una_persona(): void
    {
        $cpu = $this->categoria('Procesadores', 'cpu');

        $id = $this->crear([
            'socket'              => ' am5 ',
            'tdp'                 => '105 W',
            'graficos_integrados' => 'Sí',
            'nucleos'             => '06',
            'Frecuencia'          => '4.7 GHz',
        ], $cpu)->assertCreated()->json('id');

        $this->assertSame([
            'socket'              => 'AM5',
            'tdp'                 => '105',
            'graficos_integrados' => 'si',
            'nucleos'             => '6',
            'Frecuencia'          => '4.7 GHz',
        ], Product::withoutTenant()->find($id)->specs);
    }

    /** El falso rojo del armador de hoy: "Socket AM5" no es un socket de la lista. */
    public function test_un_valor_fuera_de_la_lista_se_rechaza_nombrando_el_campo(): void
    {
        $cpu = $this->categoria('Procesadores', 'cpu');

        $respuesta = $this->crear(['socket' => 'Socket AM5'], $cpu)->assertStatus(422);

        $mensaje = $respuesta->json('errors')['specs.socket'][0];
        $this->assertStringContainsString('«Socket»', $mensaje);
        $this->assertStringContainsString('AM5', $mensaje);
        $this->assertStringContainsString('Llegó «Socket AM5»', $mensaje);
    }

    public function test_un_numero_que_no_es_numero_se_rechaza_con_su_unidad(): void
    {
        $gpu = $this->categoria('Tarjetas', 'gpu');

        $respuesta = $this->crear(['largo' => 'unos 30 cm'], $gpu)->assertStatus(422);

        $this->assertStringContainsString('número entero, en mm', $respuesta->json('errors')['specs.largo'][0]);
    }

    /** Lo que decidio 13.1: que falte nunca impide guardar; el armador lo dira. */
    public function test_que_falte_un_campo_fijo_no_impide_guardar(): void
    {
        $cpu = $this->categoria('Procesadores', 'cpu');

        $id = $this->crear(['Frecuencia' => '4.7 GHz'], $cpu)->assertCreated()->json('id');

        $this->assertSame(['Frecuencia' => '4.7 GHz'], Product::withoutTenant()->find($id)->specs);
    }

    /** Un campo fijo vacio se quita, como hace el formulario con una spec libre vacia. */
    public function test_un_campo_fijo_vacio_no_se_guarda(): void
    {
        $cpu = $this->categoria('Procesadores', 'cpu');

        $id = $this->crear(['socket' => 'AM5', 'tdp' => ''], $cpu)->assertCreated()->json('id');

        $this->assertSame(['socket' => 'AM5'], Product::withoutTenant()->find($id)->specs);
    }

    /** `socket` no es campo de una GPU: ahi es una spec libre como cualquier otra. */
    public function test_la_clave_de_otro_tipo_es_una_spec_libre(): void
    {
        $gpu = $this->categoria('Tarjetas', 'gpu');

        $id = $this->crear(['socket' => 'lo que sea'], $gpu)->assertCreated()->json('id');

        $this->assertSame(['socket' => 'lo que sea'], Product::withoutTenant()->find($id)->specs);
    }

    public function test_sin_categoria_no_hay_campos_fijos(): void
    {
        $id = $this->crear(['socket' => 'lo que sea'], null)->assertCreated()->json('id');

        $this->assertSame(['socket' => 'lo que sea'], Product::withoutTenant()->find($id)->specs);
    }

    public function test_varios_se_guarda_sin_repetidos_y_en_el_orden_de_la_lista(): void
    {
        $refrigeracion = $this->categoria('Disipadores', 'cooling');

        $id = $this->crear(['sockets' => 'lga1700, AM4, am4'], $refrigeracion)->assertCreated()->json('id');

        $this->assertSame('AM4, LGA1700', Product::withoutTenant()->find($id)->specs['sockets']);
    }

    /** Una sola opcion que no se reconoce rechaza el campo: no se quita en silencio. */
    public function test_varios_con_una_opcion_desconocida_se_rechaza(): void
    {
        $refrigeracion = $this->categoria('Disipadores', 'cooling');

        $this->crear(['sockets' => 'AM4, LGA9999'], $refrigeracion)
            ->assertStatus(422)
            ->assertJsonValidationErrors('specs.sockets');
    }

    /** Editar sin mandar la categoria valida con la que ya tiene el producto. */
    public function test_al_editar_sin_categoria_se_valida_con_la_que_ya_tiene(): void
    {
        $cpu = $this->categoria('Procesadores', 'cpu');
        $producto = $this->existente(['name' => 'Ryzen', 'category_id' => $cpu->id]);

        $this->editar($producto, ['specs' => json_encode(['socket' => 'Socket AM5'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('specs.socket');

        $this->editar($producto, ['specs' => json_encode(['socket' => 'am5'])])->assertOk();
        $this->assertSame(['socket' => 'AM5'], $producto->fresh()->specs);
    }

    /** Pasar un producto a "Procesadores" en el mismo guardado valida su socket. */
    public function test_al_cambiar_de_categoria_se_valida_con_la_nueva(): void
    {
        $cpu = $this->categoria('Procesadores', 'cpu');
        $producto = $this->existente(['name' => 'Suelto']);

        $this->editar($producto, ['category_id' => $cpu->id, 'specs' => json_encode(['socket' => 'Socket AM5'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('specs.socket');
    }

    // ------------------------------------------------------------------ el CSV

    public function test_el_import_normaliza_y_rechaza_con_las_mismas_reglas(): void
    {
        $this->categoria('Procesadores', 'cpu');

        $csv = "nombre;precio;stock;categoria;especificaciones\n"
            ."Ryzen 5 7600X;250;3;Procesadores;\"socket:am5|tdp:105 W|Frecuencia:4.7 GHz\"\n"
            ."Inventado 9000;100;1;Procesadores;\"socket:Socket 9000\"\n";

        $respuesta = $this->importar($csv)->assertOk();

        $this->assertSame(['socket' => 'AM5', 'tdp' => '105', 'Frecuencia' => '4.7 GHz'], $this->specsDe('Ryzen 5 7600X'));
        $this->assertNull($this->specsDe('Inventado 9000'), 'La fila con un socket que no vale no se tenía que importar.');

        $error = collect($respuesta->json('errors'))->first(fn ($e) => str_contains($e, 'Fila 3'));
        $this->assertNotNull($error, 'La fila rechazada no se reporta.');
        $this->assertStringContainsString('«Socket»', $error);
    }

    /** Si la primera fila de un grupo se rechaza, el resto no describe la ficha. */
    public function test_el_resto_de_un_grupo_rechazado_por_una_spec_dice_por_que(): void
    {
        $this->categoria('Memorias', 'ram');

        $csv = "nombre;variante;precio;stock;categoria;especificaciones\n"
            ."Fury;Capacidad: 16 GB;50;3;Memorias;\"tipo_memoria:DDR9\"\n"
            ."Fury;Capacidad: 32 GB;90;3;;\n";

        $errores = $this->importar($csv)->assertOk()->json('errors');

        $this->assertNull($this->specsDe('Fury'));
        $this->assertTrue(
            collect($errores)->contains(fn ($e) => str_contains($e, 'Fila 3') && str_contains($e, 'una especificación que no vale')),
            'La segunda fila del grupo no dice por qué se rechazó.',
        );
    }

    /**
     * Sin categoria en el archivo, al actualizar se valida con la que ya tiene
     * el producto: es con la que se va a guardar.
     */
    public function test_actualizar_sin_categoria_valida_con_la_del_producto(): void
    {
        $cpu = $this->categoria('Procesadores', 'cpu');
        $this->existente(['name' => 'Ryzen', 'category_id' => $cpu->id, 'specs' => ['socket' => 'AM4']]);

        $csv = "nombre;precio;stock;categoria;especificaciones\n"
            ."Ryzen;100;1;;\"socket:Socket AM5\"\n";

        $errores = $this->importar($csv, 'actualizar')->assertOk()->json('errors');

        $this->assertSame(['socket' => 'AM4'], $this->specsDe('Ryzen'));
        $this->assertTrue(collect($errores)->contains(fn ($e) => str_contains($e, '«Socket»')));
    }

    // ------------------------------------------------------- lo que ve un buscador

    public function test_la_vista_para_buscadores_pinta_etiqueta_y_unidad(): void
    {
        $cpu = $this->categoria('Procesadores', 'cpu');
        $producto = $this->existente([
            'name'        => 'Ryzen 5 7600X',
            'category_id' => $cpu->id,
            'specs'       => ['Frecuencia' => '4.7 GHz', 'tdp' => '105', 'graficos_integrados' => 'si', 'socket' => 'AM5'],
        ]);

        $html = $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])
            ->get("/tienda-specs/product/{$producto->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<dt>TDP / potencia base</dt>', $html);
        $this->assertStringContainsString('<dd>105 W</dd>', $html);
        $this->assertStringContainsString('<dd>Sí</dd>', $html);
        $this->assertStringNotContainsString('<dt>tdp</dt>', $html);

        // Las fijas primero, en el orden de la definicion; las libres despues.
        $this->assertLessThan(strpos($html, '<dt>TDP / potencia base</dt>'), strpos($html, '<dt>Socket</dt>'));
        $this->assertLessThan(strpos($html, '<dt>Frecuencia</dt>'), strpos($html, '<dt>Gráficos integrados</dt>'));
    }
}
