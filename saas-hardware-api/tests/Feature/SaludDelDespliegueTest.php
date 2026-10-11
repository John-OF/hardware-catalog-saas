<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HuellaDelCodigo;
use App\Support\Salud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * `salud:comprobar` (`INF-19`): lo que el despliegue necesita y que, si falta,
 * no da ningún error.
 *
 * Cada comprobación se prueba por los dos lados —lo que la hace pasar y lo que
 * la hace fallar—, porque una comprobación que nunca falla no comprueba nada.
 *
 * **La de la cola corre el worker de verdad** (`queue:work` dentro del proceso,
 * como `QueuedMailWithRealQueueTest`) y no solo la cola `sync` del phpunit.xml:
 * con `sync`, el trabajo de prueba se ejecuta en el mismo proceso que pregunta y
 * no hay nada que distinguir. Es el worker real el que se salta un trabajo sin
 * tienda (`AUD-11`) y el que dispara `WorkerStarting`, de donde sale la huella.
 */
class SaludDelDespliegueTest extends TestCase
{
    use RefreshDatabase;

    private ?string $carpetaDeHuella = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['backups.disco' => 'copias']);
        Storage::fake('copias');
    }

    protected function tearDown(): void
    {
        if ($this->carpetaDeHuella !== null) {
            File::deleteDirectory(base_path($this->carpetaDeHuella));
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------ migraciones

    public function test_con_todas_las_migraciones_aplicadas_pasa(): void
    {
        [$estado] = Salud::migraciones();

        $this->assertSame(Salud::OK, $estado);
    }

    public function test_una_migracion_sin_aplicar_falla_y_dice_cual(): void
    {
        DB::table('migrations')->where('migration', '2026_06_12_080342_create_tenants_table')->delete();

        [$estado, $detalle] = Salud::migraciones();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('create_tenants_table', $detalle);
        $this->assertStringContainsString('migrate --force', $detalle);
    }

    // -------------------------------------------------------------- scheduler

    public function test_sin_latido_del_scheduler_falla(): void
    {
        [$estado, $detalle] = Salud::scheduler();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('schedule:run', $detalle);
    }

    public function test_con_un_latido_reciente_pasa_y_con_uno_viejo_falla(): void
    {
        Salud::anotarPasoDelScheduler();

        $this->assertSame(Salud::OK, Salud::scheduler()[0]);

        $this->travel(4)->minutes();

        [$estado, $detalle] = Salud::scheduler();
        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('parado', $detalle);
    }

    // ------------------------------------------------------------------ copia

    public function test_sin_ninguna_copia_falla(): void
    {
        [$estado, $detalle] = Salud::copia();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('No hay ninguna copia', $detalle);
    }

    public function test_con_una_copia_reciente_pasa_y_si_envejece_falla(): void
    {
        Storage::disk('copias')->put('copias/2026-10-10_033000-production.sql', '-- volcado');

        $this->assertSame(Salud::OK, Salud::copia()[0]);

        // 26 horas de margen: la copia es diaria, y pedir una "de hoy" fallaría
        // cada noche antes de las 03:30.
        $this->travel(25)->hours();
        $this->assertSame(Salud::OK, Salud::copia()[0]);

        $this->travel(2)->hours();
        [$estado, $detalle] = Salud::copia();
        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('copias:crear', $detalle);
    }

    public function test_un_disco_de_copias_publico_falla(): void
    {
        config(['backups.disco' => 'public']);
        Storage::fake('public');
        Storage::disk('public')->put('copias/una.sql', '-- volcado');

        [$estado, $detalle] = Salud::copia();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('público', $detalle);
    }

    public function test_en_produccion_una_copia_en_el_mismo_servidor_falla_aunque_sea_reciente(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['backups.disco' => 'local']);
        Storage::fake('local');
        Storage::disk('local')->put('copias/una.sql', '-- volcado');

        [$estado, $detalle] = Salud::copia();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('mismo servidor', $detalle);
    }

    // ----------------------------------------------------------------- correo

    public function test_el_correo_solo_se_exige_en_produccion(): void
    {
        config(['mail.default' => 'log']);

        $this->assertSame(Salud::NO_APLICA, Salud::correo()[0]);

        $this->app->detectEnvironment(fn () => 'production');

        [$estado, $detalle] = Salud::correo();
        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('MAIL_MAILER=log', $detalle);

        config(['mail.default' => 'smtp']);
        $this->assertSame(Salud::OK, Salud::correo()[0]);
    }

    // ------------------------------------------------------ PHP del servidor web

    public function test_los_topes_del_php_de_la_web_que_llegan_al_readme_pasan(): void
    {
        $this->fingirPhpDeLaWeb(['memory_limit' => '512M', 'post_max_size' => '0']);

        [$estado, $detalle] = Salud::phpDeLaWeb();

        $this->assertSame(Salud::OK, $estado, $detalle);
        $this->assertStringContainsString('fpm-fcgi', $detalle);
    }

    public function test_un_tope_por_debajo_falla_y_dice_cual(): void
    {
        // Los de fábrica de PHP: los que hay en un servidor recién montado.
        $this->fingirPhpDeLaWeb(['upload_max_filesize' => '2M', 'memory_limit' => '128M']);

        [$estado, $detalle] = Salud::phpDeLaWeb();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('upload_max_filesize = 2M', $detalle);
        $this->assertStringContainsString('memory_limit = 128M', $detalle);
        $this->assertStringNotContainsString('post_max_size', $detalle);
    }

    public function test_memory_limit_sin_tope_pasa(): void
    {
        $this->fingirPhpDeLaWeb(['memory_limit' => '-1']);

        $this->assertSame(Salud::OK, Salud::phpDeLaWeb()[0]);
    }

    public function test_la_url_que_firma_el_comando_la_acepta_la_ruta_de_verdad(): void
    {
        // Lo que de verdad importa del camino HTTP: que lo que firma el comando
        // y lo que comprueba `signed` cuadren. Se captura la URL que el comando
        // pide y se le pasa a la aplicación.
        $pedida = null;

        Http::fake(function (Request $peticion) use (&$pedida) {
            $pedida = $peticion->url();

            return Http::response($this->topesBuenos());
        });

        Salud::phpDeLaWeb();

        $this->assertNotNull($pedida);
        $this->getJson($pedida)
            ->assertOk()
            ->assertJsonStructure(['sapi', 'upload_max_filesize', 'post_max_size', 'max_file_uploads', 'memory_limit']);
    }

    public function test_la_ruta_sin_firma_no_contesta(): void
    {
        $this->getJson('/api/salud/php')->assertForbidden();

        $caducada = URL::temporarySignedRoute('salud.php', now()->addMinutes(2));
        $this->travel(3)->minutes();
        $this->getJson($caducada)->assertForbidden();
    }

    public function test_si_app_url_rechaza_la_firma_lo_dice(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid signature.'], 403)]);

        [$estado, $detalle] = Salud::phpDeLaWeb();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('verificación de correo', $detalle);
    }

    public function test_si_en_app_url_contesta_otra_aplicacion_lo_dice(): void
    {
        // Pasó al probarlo en local el 2026-10-10: en el puerto de APP_URL
        // contestaba otro proyecto de la misma máquina.
        Http::fake(['*' => Http::response('Not Found', 404)]);

        [$estado, $detalle] = Salud::phpDeLaWeb();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('no contesta esta aplicación', $detalle);
    }

    public function test_si_app_url_no_responde_lo_dice(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        [$estado, $detalle] = Salud::phpDeLaWeb();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('APP_URL', $detalle);
    }

    // ------------------------------------------------------------- plataforma

    public function test_las_ips_de_plataforma_solo_se_exigen_en_produccion(): void
    {
        config(['platform.allowed_ips' => []]);

        $this->assertSame(Salud::NO_APLICA, Salud::ipsDePlataforma()[0]);

        $this->app->detectEnvironment(fn () => 'production');

        [$estado, $detalle] = Salud::ipsDePlataforma();
        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('PLATFORM_ALLOWED_IPS', $detalle);

        // La renuncia explícita vale: es una decisión escrita, no un olvido.
        config(['platform.allowed_ips' => ['*']]);
        $this->assertSame(Salud::OK, Salud::ipsDePlataforma()[0]);

        config(['platform.allowed_ips' => ['203.0.113.7']]);
        $this->assertSame(Salud::OK, Salud::ipsDePlataforma()[0]);
    }

    public function test_los_dos_pasos_solo_se_exigen_en_produccion(): void
    {
        $this->crearOperador('operador@plataforma.test');

        $this->assertSame(Salud::NO_APLICA, Salud::dosPasos()[0]);
    }

    public function test_en_produccion_sin_ningun_operador_falla(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        [$estado, $detalle] = Salud::dosPasos();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('platform:superadmin', $detalle);
    }

    public function test_en_produccion_un_operador_sin_dos_pasos_falla_y_dice_quien(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->crearOperador('con@plataforma.test', dosPasos: true);
        $this->crearOperador('sin@plataforma.test');
        // Uno desactivado no cuenta: no puede entrar.
        $this->crearOperador('baja@plataforma.test', activo: false);

        [$estado, $detalle] = Salud::dosPasos();

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('sin@plataforma.test', $detalle);
        $this->assertStringNotContainsString('con@plataforma.test', $detalle);
        $this->assertStringNotContainsString('baja@plataforma.test', $detalle);
    }

    public function test_en_produccion_con_todos_los_operadores_con_dos_pasos_pasa(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->crearOperador('con@plataforma.test', dosPasos: true);

        $this->assertSame(Salud::OK, Salud::dosPasos()[0]);
    }

    // ------------------------------------------------------------------- cola

    public function test_con_la_cola_sync_no_hay_worker_que_comprobar(): void
    {
        [$estado, $detalle] = Salud::cola(0);

        $this->assertSame(Salud::OK, $estado);
        $this->assertStringContainsString('no hay worker que comprobar', $detalle);
    }

    public function test_sin_worker_el_trabajo_de_prueba_se_queda_en_la_cola_y_falla(): void
    {
        config(['queue.default' => 'database']);

        $token = Salud::enviarPruebaALaCola();

        $this->assertSame(1, DB::table('jobs')->count(), 'El trabajo de prueba no llegó a la cola.');

        [$estado, $detalle] = Salud::leerPruebaDeLaCola($token, 0);

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('queue:work', $detalle);
    }

    public function test_un_worker_de_verdad_con_el_codigo_actual_pasa(): void
    {
        config(['queue.default' => 'database']);

        $token = Salud::enviarPruebaALaCola();
        $this->trabajarLaCola();

        // Si spatie se lo hubiera saltado por no llevar tienda (AUD-11), no
        // estaría en `failed_jobs` pero tampoco habría escrito nada.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        [$estado, $detalle] = Salud::leerPruebaDeLaCola($token, 0);

        $this->assertSame(Salud::OK, $estado, $detalle);
        $this->assertStringContainsString('código actual', $detalle);
    }

    public function test_un_worker_que_arranco_antes_del_ultimo_cambio_falla(): void
    {
        // El "código" de este test es una carpeta propia, para poder cambiarlo
        // sin tocar el de verdad.
        $this->carpetaDeHuella = 'storage/framework/testing/huella-'.uniqid();
        File::ensureDirectoryExists(base_path($this->carpetaDeHuella));
        File::put(base_path($this->carpetaDeHuella.'/Algo.php'), '<?php // versión 1');
        config(['salud.huella' => [$this->carpetaDeHuella], 'queue.default' => 'database']);

        // El worker arranca —y apunta su huella— con la versión 1…
        event(new WorkerStarting('database', 'default', new WorkerOptions));

        // …se despliega la versión 2 sin `queue:restart`…
        File::put(base_path($this->carpetaDeHuella.'/Algo.php'), '<?php // versión 2');

        // …y el worker atiende el trabajo con lo que tenía en memoria.
        $token = Salud::enviarPruebaALaCola();
        $this->trabajarLaCola();

        [$estado, $detalle] = Salud::leerPruebaDeLaCola($token, 0);

        $this->assertSame(Salud::FALLO, $estado);
        $this->assertStringContainsString('queue:restart', $detalle);
    }

    public function test_la_huella_cambia_con_el_contenido_y_no_con_la_fecha(): void
    {
        $this->carpetaDeHuella = 'storage/framework/testing/huella-'.uniqid();
        $archivo = base_path($this->carpetaDeHuella.'/Algo.php');
        File::ensureDirectoryExists(dirname($archivo));
        File::put($archivo, '<?php // versión 1');
        config(['salud.huella' => [$this->carpetaDeHuella]]);

        $antes = HuellaDelCodigo::delDisco();

        touch($archivo, time() + 3600);
        $this->assertSame($antes, HuellaDelCodigo::delDisco(), 'Una fecha distinta con el mismo contenido no es otro código.');

        File::put($archivo, '<?php // versión 2');
        $this->assertNotSame($antes, HuellaDelCodigo::delDisco());
    }

    // ---------------------------------------------------------------- comando

    public function test_el_comando_sale_bien_cuando_todo_esta_en_orden(): void
    {
        $this->dejarTodoEnOrden();

        $this->artisan('salud:comprobar', ['--espera' => 0])
            ->expectsOutputToContain('Todo en orden')
            ->assertExitCode(0);
    }

    public function test_el_comando_sale_con_error_y_lo_escribe_en_el_log_si_algo_falla(): void
    {
        $this->dejarTodoEnOrden();
        Storage::disk('copias')->deleteDirectory('copias');
        Log::spy();

        $this->artisan('salud:comprobar', ['--espera' => 0])
            ->expectsOutputToContain('No hay ninguna copia')
            ->expectsOutputToContain('1 de las comprobaciones fallaron')
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $mensaje, array $contexto) => array_keys($contexto['fallos']) === ['Copia de la base'])
            ->once();
    }

    public function test_una_comprobacion_que_revienta_cuenta_como_fallo_y_no_para_las_demas(): void
    {
        $this->dejarTodoEnOrden();
        config(['backups.disco' => 'no-existe']);

        $this->artisan('salud:comprobar', ['--espera' => 0])
            ->expectsOutputToContain('No se pudo comprobar')
            // La última, que va después de la que revienta, se sigue haciendo.
            ->expectsOutputToContain('no hay worker que comprobar')
            ->assertExitCode(1);
    }

    // ---------------------------------------------------------------- ayudas

    /** @return array<string, string> */
    private function topesBuenos(array $cambios = []): array
    {
        return array_merge([
            'sapi'                => 'fpm-fcgi',
            'upload_max_filesize' => '12M',
            'post_max_size'       => '100M',
            'max_file_uploads'    => '50',
            'memory_limit'        => '256M',
        ], $cambios);
    }

    private function fingirPhpDeLaWeb(array $cambios = []): void
    {
        Http::fake(['*/api/salud/php*' => Http::response($this->topesBuenos($cambios))]);
    }

    private function dejarTodoEnOrden(): void
    {
        Salud::anotarPasoDelScheduler();
        Storage::disk('copias')->put('copias/2026-10-10_033000-testing.sqlite', 'SQLite format 3');
        $this->fingirPhpDeLaWeb();
    }

    private function crearOperador(string $correo, bool $dosPasos = false, bool $activo = true): User
    {
        $operador = new User([
            'name'      => 'Operador',
            'email'     => $correo,
            'password'  => 'password123',
            'role'      => 'superadmin',
            'is_active' => $activo,
        ]);
        $operador->tenant_id = null;

        if ($dosPasos) {
            $operador->forceFill([
                'two_factor_secret'       => 'JBSWY3DPEHPK3PXP',
                'two_factor_confirmed_at' => now(),
            ]);
        }

        $operador->save();

        return $operador;
    }

    /** El worker de verdad, dentro del proceso: ver la cabecera. */
    private function trabajarLaCola(): void
    {
        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--tries'           => 1,
            '--sleep'           => 0,
            // Por lo mismo que en `QueuedMailWithRealQueueTest`: el proceso es
            // el de PHPUnit con la suite cargada, no un worker limpio.
            '--memory'          => 4096,
        ]);
    }
}
