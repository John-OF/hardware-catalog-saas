<?php

namespace Tests\Feature;

use App\Support\Copias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Copias de seguridad de la base (`INF-7`).
 *
 * **Estos casos corren contra una base SQLite de verdad, en un archivo temporal**
 * y no contra la de la suite, que vive en memoria y por tanto no se puede
 * copiar. Es a propósito: un test de backups con un doble comprueba que se llama
 * a algo, no que salga una copia, y el fallo típico de esta función es justo ése
 * — que el archivo se crea y está vacío.
 *
 * Lo que se vigila, por orden de lo que dolería:
 *
 * 1. **Que una copia no acabe en un disco público.** Lleva dentro los datos de
 *    todas las tiendas y los correos de todos sus clientes; en el disco de las
 *    imágenes se serviría por HTTP.
 * 2. **Que un volcado malo no se guarde ni cuente como la copia del día.**
 * 3. **Que el comando falle ruidosamente**, porque un cron que no avisa es una
 *    copia que no existe.
 */
class CopiasDeSeguridadTest extends TestCase
{
    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();

        // Una base SQLite en un archivo de verdad, con algo dentro.
        $this->archivo = tempnam(sys_get_temp_dir(), 'copia').'.sqlite';
        touch($this->archivo);

        config([
            'database.connections.copia_test' => [
                'driver' => 'sqlite',
                'database' => $this->archivo,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'database.default' => 'copia_test',
            'backups.disco' => 'copias',
            'backups.retencion_dias' => 30,
        ]);

        DB::purge('copia_test');
        DB::statement('CREATE TABLE tenants (id integer primary key, name text)');
        DB::statement("INSERT INTO tenants (name) VALUES ('Tienda de prueba')");

        Storage::fake('copias');
    }

    protected function tearDown(): void
    {
        DB::purge('copia_test');
        @unlink($this->archivo);

        parent::tearDown();
    }

    // ------------------------------------------------------------ crear

    public function test_crea_una_copia_con_la_base_dentro(): void
    {
        $this->artisan('copias:crear')->assertExitCode(0);

        $copias = Storage::disk('copias')->files(Copias::CARPETA);

        $this->assertCount(1, $copias);

        $contenido = Storage::disk('copias')->get($copias[0]);

        // Una copia de verdad, no un archivo vacío con el nombre correcto.
        $this->assertStringStartsWith('SQLite format 3', $contenido);
        $this->assertStringContainsString('Tienda de prueba', $contenido);
    }

    public function test_el_nombre_lleva_la_fecha_para_que_ordenen_solas(): void
    {
        $this->artisan('copias:crear')->assertExitCode(0);

        $copias = Storage::disk('copias')->files(Copias::CARPETA);

        $this->assertMatchesRegularExpression(
            '#^copias/\d{4}-\d{2}-\d{2}_\d{6}-.+\.sqlite$#',
            $copias[0],
        );
    }

    // --------------------------------------------------------- disco público

    public function test_se_niega_a_escribir_en_un_disco_publico(): void
    {
        // Es el peor fallo posible de esta función: el disco `public` se sirve
        // desde `/storage`, así que la base entera quedaría descargable.
        Storage::fake('public');

        $this->artisan('copias:crear', ['--disco' => 'public'])
            ->expectsOutputToContain('publico')
            ->assertExitCode(1);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_tambien_se_niega_si_el_disco_se_llama_de_otra_forma_pero_es_publico(): void
    {
        // No basta con mirar el nombre: lo que importa es la visibilidad.
        config(['filesystems.disks.subidas' => [
            'driver' => 'local',
            'root' => storage_path('app/subidas'),
            'visibility' => 'public',
        ]]);

        $this->assertTrue(Copias::discoEsPublico('subidas'));

        $this->artisan('copias:crear', ['--disco' => 'subidas'])->assertExitCode(1);
    }

    public function test_los_discos_privados_si_valen(): void
    {
        $this->assertFalse(Copias::discoEsPublico('local'));
        $this->assertFalse(Copias::discoEsPublico('r2'));
    }

    // ------------------------------------------------------- volcado malo

    public function test_un_volcado_vacio_no_se_guarda_y_el_comando_falla(): void
    {
        // El fallo clásico de los backups: el archivo se crea cada noche y está
        // vacío. Si esto se guardara, contaría como la copia del día.
        config(['database.connections.copia_test.database' => $this->archivo.'-no-existe']);
        DB::purge('copia_test');

        $this->artisan('copias:crear')->assertExitCode(1);

        $this->assertSame([], Storage::disk('copias')->allFiles());
    }

    public function test_la_comprobacion_rechaza_un_sql_al_que_le_faltan_tablas(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('products');

        Copias::comprobar("-- solo tenants\nCREATE TABLE tenants (id int);", 'sql');
    }

    public function test_la_comprobacion_acepta_un_sql_completo(): void
    {
        Copias::comprobar(
            'CREATE TABLE tenants (id int); CREATE TABLE products (id int); CREATE TABLE orders (id int);',
            'sql',
        );

        // Sin excepción es que pasó; `expectNotToPerformAssertions` no vale
        // porque lo que se está probando es justo que NO salte.
        $this->assertTrue(true);
    }

    public function test_la_comprobacion_rechaza_un_archivo_que_no_es_una_base(): void
    {
        $this->expectException(\RuntimeException::class);

        Copias::comprobar('esto no es una base de datos', 'sqlite');
    }

    // --------------------------------------------------------------- purga

    public function test_borra_las_copias_caducadas_y_deja_las_recientes(): void
    {
        // Sin purga la carpeta crece sin techo, que es la razón por la que las
        // copias se acaban apagando.
        Storage::disk('copias')->put(Copias::CARPETA.'/vieja.sqlite', 'x');
        Storage::disk('copias')->put(Copias::CARPETA.'/reciente.sqlite', 'x');

        $ruta = Storage::disk('copias')->path(Copias::CARPETA.'/vieja.sqlite');
        touch($ruta, now()->subDays(40)->getTimestamp());

        $this->artisan('copias:crear', ['--retener' => 30])->assertExitCode(0);

        $quedan = Storage::disk('copias')->files(Copias::CARPETA);

        $this->assertNotContains(Copias::CARPETA.'/vieja.sqlite', $quedan);
        $this->assertContains(Copias::CARPETA.'/reciente.sqlite', $quedan);
        // Y la de hoy.
        $this->assertCount(2, $quedan);
    }

    public function test_la_retencion_se_puede_alargar_desde_la_linea_de_comandos(): void
    {
        Storage::disk('copias')->put(Copias::CARPETA.'/vieja.sqlite', 'x');
        touch(
            Storage::disk('copias')->path(Copias::CARPETA.'/vieja.sqlite'),
            now()->subDays(40)->getTimestamp(),
        );

        $this->artisan('copias:crear', ['--retener' => 90])->assertExitCode(0);

        $this->assertContains(
            Copias::CARPETA.'/vieja.sqlite',
            Storage::disk('copias')->files(Copias::CARPETA),
        );
    }

    // ------------------------------------------------------------- motores

    public function test_no_inventa_una_copia_de_un_motor_que_no_sabe_volcar(): void
    {
        // Mejor un error que un archivo con algo que no es la base.
        config([
            'database.connections.rara' => ['driver' => 'pgsql', 'database' => 'x'],
            'database.default' => 'rara',
        ]);
        DB::purge('rara');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pgsql');

        Copias::volcar();
    }
}
