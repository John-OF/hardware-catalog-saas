<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Verificación en dos pasos del operador de la plataforma (SEC-9).
 *
 * Lo que fija, por orden de lo que costaría romperlo sin enterarse:
 *
 * 1. **Con el segundo paso activo, la contraseña sola no da ningún token.** Da
 *    un desafío, y el token llega con el código de la app o uno de recuperación.
 * 2. **Un código solo sirve una vez**, y uno de fuera de la ventana no sirve.
 * 3. **El desafío caduca y se agota**: cinco minutos y cinco códigos equivocados.
 * 4. **En producción es obligatoria**: sin ella, el panel solo deja activarla.
 * 5. **Lo secreto no sale de la base en claro**: la semilla va cifrada y los
 *    códigos de recuperación, además, como hash.
 * 6. **Quitarla es cosa del servidor**, cierra las sesiones y queda anotado.
 *
 * Los códigos se calculan aquí con la misma librería que usa la aplicación, a
 * partir de la semilla guardada: es lo que haría la app del móvil.
 */
class PlatformDosPasosTest extends TestCase
{
    use RefreshDatabase;

    private const CORREO = 'operador@plataforma.test';

    private const CONTRASENIA = 'password123';

    private User $operador;

    private string $guardOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardOriginal = (string) config('auth.defaults.guard');

        $this->operador = new User([
            'name'      => 'Operador',
            'email'     => self::CORREO,
            'password'  => self::CONTRASENIA,
            'role'      => 'superadmin',
            'is_active' => true,
        ]);
        $this->operador->tenant_id = null;
        $this->operador->save();
    }

    // ------------------------------------------------------------ sin activar

    public function test_sin_dos_pasos_la_contrasenia_da_el_token_como_siempre(): void
    {
        $this->entrarConContrasenia()
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('seguridad.dos_pasos', false)
            ->assertJsonMissingPath('requiere_dos_pasos');
    }

    // --------------------------------------------------------------- activar

    public function test_activar_da_el_qr_y_la_semilla_se_guarda_cifrada(): void
    {
        $respuesta = $this->comoOperador()->postJson('/api/platform/dos-pasos')->assertOk();

        $secreto = $respuesta->json('secreto');
        $this->assertStringStartsWith('otpauth://totp/', $respuesta->json('enlace'));
        $this->assertStringContainsString('<svg', $respuesta->json('qr_svg'));

        $enLaBase = DB::table('users')->where('id', $this->operador->id)->value('two_factor_secret');
        $this->assertNotSame($secreto, $enLaBase);
        $this->assertSame($secreto, Crypt::decryptString($enLaBase));

        // Empezada pero sin confirmar: el login todavía no la pide.
        $this->entrarConContrasenia()->assertOk()->assertJsonStructure(['token']);
    }

    public function test_activar_pide_un_codigo_bueno_y_da_ocho_codigos_de_recuperacion(): void
    {
        $this->comoOperador()->postJson('/api/platform/dos-pasos')->assertOk();

        $this->comoOperador()->postJson('/api/platform/dos-pasos/confirmar', ['codigo' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->assertFalse($this->operador->fresh()->tieneDosPasos());

        $respuesta = $this->comoOperador()
            ->postJson('/api/platform/dos-pasos/confirmar', ['codigo' => $this->codigo()])
            ->assertOk()
            ->assertJsonPath('seguridad.dos_pasos', true)
            ->assertJsonPath('seguridad.codigos_restantes', 8);

        $codigos = $respuesta->json('codigos');
        $this->assertCount(8, $codigos);
        foreach ($codigos as $codigo) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codigo);
        }

        // En la base no están ni en claro ni descifrando: se guarda su hash.
        $enLaBase = DB::table('users')->where('id', $this->operador->id)->value('two_factor_recovery_codes');
        $descifrados = json_decode(Crypt::decryptString($enLaBase), true);
        $this->assertNotContains($codigos[0], $descifrados);
        $this->assertCount(8, $descifrados);

        $this->assertDatabaseHas('activity_logs', [
            'action'   => ActivityLog::OPERADOR_DOS_PASOS_ACTIVADA,
            'actor_id' => $this->operador->id,
        ]);
    }

    public function test_confirmar_sin_haber_empezado_no_activa_nada(): void
    {
        $this->comoOperador()->postJson('/api/platform/dos-pasos/confirmar', ['codigo' => '123456'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');

        $this->assertFalse($this->operador->fresh()->tieneDosPasos());
    }

    /** Desde el panel solo se activa: quitarla o rehacerla es del servidor. */
    public function test_ya_activada_no_se_puede_reempezar_desde_el_panel(): void
    {
        $this->activar();

        $this->comoOperador()->postJson('/api/platform/dos-pasos')->assertStatus(409);
        $this->comoOperador()->postJson('/api/platform/dos-pasos/confirmar', ['codigo' => $this->codigo(1)])->assertStatus(409);
        $this->assertTrue($this->operador->fresh()->tieneDosPasos());
    }

    // ----------------------------------------------------------------- entrar

    public function test_con_dos_pasos_la_contrasenia_sola_no_da_token(): void
    {
        $this->activar();
        $tokensAntes = $this->operador->fresh()->tokens()->count();

        $respuesta = $this->entrarConContrasenia()
            ->assertOk()
            ->assertJsonPath('requiere_dos_pasos', true)
            ->assertJsonMissingPath('token');

        $this->assertIsString($respuesta->json('desafio'));
        // Ni un token nuevo, ni se cierran las sesiones que hubiera: eso pasa al
        // dar el código, no antes.
        $this->assertSame($tokensAntes, $this->operador->fresh()->tokens()->count());
        // No ha entrado: no cuenta como acceso.
        $this->assertNull($this->operador->fresh()->last_login_at);
    }

    public function test_con_el_codigo_de_la_app_entra(): void
    {
        $this->activar();
        $desafio = $this->entrarConContrasenia()->json('desafio');

        $token = $this->segundoPaso($desafio, $this->codigo(1))
            ->assertOk()
            ->assertJsonPath('seguridad.dos_pasos', true)
            ->json('token');

        $this->desdeCero();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/platform/tenants')->assertOk();
        $this->assertNotNull($this->operador->fresh()->last_login_at);
    }

    /** Un código visto en la pantalla de otro no sirve después. */
    public function test_un_codigo_no_sirve_dos_veces(): void
    {
        $this->activar();

        $codigo = $this->codigo(1);
        $this->segundoPaso($this->entrarConContrasenia()->json('desafio'), $codigo)->assertOk();

        $this->segundoPaso($this->entrarConContrasenia()->json('desafio'), $codigo)
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_un_codigo_de_fuera_de_la_ventana_no_sirve(): void
    {
        $this->activar();
        $desafio = $this->entrarConContrasenia()->json('desafio');

        // Cuatro pasos atrás: dos minutos. La ventana es de uno a cada lado.
        $this->segundoPaso($desafio, $this->codigo(-4))->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->segundoPaso($desafio, $this->codigo(4))->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_un_codigo_de_recuperacion_entra_una_sola_vez_y_queda_anotado(): void
    {
        $codigos = $this->activar();

        // Da igual cómo se escriba: en minúsculas y sin el guion también vale.
        $escrito = strtolower(str_replace('-', '', $codigos[0]));

        $this->segundoPaso($this->entrarConContrasenia()->json('desafio'), $escrito)
            ->assertOk()
            ->assertJsonPath('seguridad.codigos_restantes', 7);

        $this->assertDatabaseHas('activity_logs', [
            'action'   => ActivityLog::OPERADOR_CODIGO_DE_RECUPERACION,
            'actor_id' => $this->operador->id,
        ]);

        $this->segundoPaso($this->entrarConContrasenia()->json('desafio'), $codigos[0])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_el_desafio_muere_al_quinto_codigo_equivocado(): void
    {
        // Aquí se prueba el tope del desafío, no el de peticiones por minuto.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->activar();
        $desafio = $this->entrarConContrasenia()->json('desafio');

        for ($i = 1; $i <= 4; $i++) {
            $this->segundoPaso($desafio, '000000')->assertStatus(422)->assertJsonValidationErrors('codigo');
        }

        $this->segundoPaso($desafio, '000000')->assertStatus(422)->assertJsonValidationErrors('desafio');
        // Y ya no vale ni con el código bueno: hay que volver a la contraseña.
        $this->segundoPaso($desafio, $this->codigo(1))->assertStatus(422)->assertJsonValidationErrors('desafio');
    }

    public function test_el_desafio_caduca_a_los_cinco_minutos(): void
    {
        $this->activar();
        $desafio = $this->entrarConContrasenia()->json('desafio');

        $this->travel(6)->minutes();

        $this->segundoPaso($desafio, $this->codigo(1))->assertStatus(422)->assertJsonValidationErrors('desafio');
    }

    /** Entre un paso y otro pueden haberlo suspendido. */
    public function test_un_operador_suspendido_entre_los_dos_pasos_no_entra(): void
    {
        $this->activar();
        $desafio = $this->entrarConContrasenia()->json('desafio');

        $this->operador->forceFill(['is_active' => false])->save();

        $this->segundoPaso($desafio, $this->codigo(1))->assertStatus(422)->assertJsonValidationErrors('desafio');
    }

    public function test_un_desafio_inventado_no_entra(): void
    {
        $this->activar();

        $this->segundoPaso(str_repeat('a', 64), $this->codigo(1))->assertStatus(422)->assertJsonValidationErrors('desafio');
    }

    // ---------------------------------------------------- obligatoria o no

    public function test_en_produccion_sin_dos_pasos_solo_puede_activarla(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['platform.allowed_ips' => ['*']]);

        $this->comoOperador()->getJson('/api/platform/tenants')
            ->assertStatus(403)
            ->assertJsonPath('motivo', 'dos_pasos_pendiente');
        $this->comoOperador()->postJson("/api/platform/tenants/{$this->unaTienda()->id}/impersonate")
            ->assertStatus(403);

        $this->comoOperador()->getJson('/api/platform/me')
            ->assertOk()
            ->assertJsonPath('seguridad.obligatoria', true)
            ->assertJsonPath('seguridad.dos_pasos', false);

        $this->activar();

        $this->comoOperador()->getJson('/api/platform/tenants')->assertOk();
    }

    public function test_fuera_de_produccion_no_obliga(): void
    {
        $this->comoOperador()->getJson('/api/platform/tenants')->assertOk();
        $this->comoOperador()->getJson('/api/platform/me')->assertJsonPath('seguridad.obligatoria', false);
    }

    // ----------------------------------------------------- lo que no sale

    public function test_la_semilla_y_los_codigos_no_salen_en_ninguna_respuesta(): void
    {
        $this->activar();
        $secreto = $this->operador->fresh()->two_factor_secret;

        $me = $this->comoOperador()->getJson('/api/platform/me')->assertOk();

        $this->assertStringNotContainsString($secreto, $me->getContent());
        $this->assertStringNotContainsString('two_factor', $me->getContent());
        $this->assertArrayNotHasKey('two_factor_secret', $this->operador->fresh()->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $this->operador->fresh()->toArray());
    }

    // ------------------------------------------------------------ el comando

    public function test_el_comando_la_quita_cierra_las_sesiones_y_lo_anota(): void
    {
        $this->activar();
        $this->operador->createToken('abierta', ['superadmin']);

        $this->artisan('platform:quitar-dos-pasos', ['email' => self::CORREO])->assertSuccessful();

        $operador = $this->operador->fresh();
        $this->assertFalse($operador->tieneDosPasos());
        $this->assertNull($operador->two_factor_secret);
        $this->assertSame(0, $operador->tokens()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityLog::OPERADOR_DOS_PASOS_QUITADA]);

        $this->entrarConContrasenia()->assertOk()->assertJsonStructure(['token']);
    }

    public function test_el_comando_no_toca_a_un_usuario_de_tienda(): void
    {
        $tienda = $this->unaTienda();
        $duenio = new User([
            'name' => 'Dueño', 'email' => 'duenio@tienda.test', 'password' => 'password123',
            'role' => 'admin', 'is_active' => true,
        ]);
        $duenio->tenant_id = $tienda->id;
        $duenio->save();

        $this->artisan('platform:quitar-dos-pasos', ['email' => 'duenio@tienda.test'])->assertFailed();
    }

    // -------------------------------------------------- límite por minuto

    /** Seis cifras se adivinan probando si nada lo frena. */
    public function test_confirmar_la_activacion_tiene_limite_por_minuto(): void
    {
        $this->comoOperador()->postJson('/api/platform/dos-pasos')->assertOk();

        for ($i = 1; $i <= 5; $i++) {
            $this->comoOperador()->postJson('/api/platform/dos-pasos/confirmar', ['codigo' => '000000'])->assertStatus(422);
        }

        $this->comoOperador()->postJson('/api/platform/dos-pasos/confirmar', ['codigo' => '000000'])->assertStatus(429);
    }

    // ----------------------------------------------------------- ayudantes

    /**
     * Activa el segundo paso por la API, como lo haría el operador, y devuelve
     * sus códigos de recuperación. El código de la activación queda gastado, así
     * que el primer login posterior usa el del paso siguiente (`codigo(1)`), que
     * la ventana admite.
     *
     * @return list<string>
     */
    private function activar(): array
    {
        $this->comoOperador()->postJson('/api/platform/dos-pasos')->assertOk();

        $codigos = $this->comoOperador()
            ->postJson('/api/platform/dos-pasos/confirmar', ['codigo' => $this->codigo()])
            ->assertOk()
            ->json('codigos');

        $this->desdeCero();

        return $codigos;
    }

    /** El código que enseñaría la app ahora, o `$pasos` pasos de 30 s antes o después. */
    private function codigo(int $pasos = 0): string
    {
        $google2fa = new Google2FA;
        $secreto = User::withoutTenant()->findOrFail($this->operador->id)->two_factor_secret;

        return $google2fa->oathTotp($secreto, $google2fa->getTimestamp() + $pasos);
    }

    private function comoOperador(): static
    {
        $this->desdeCero();

        return $this->withHeader(
            'Authorization',
            'Bearer '.$this->operador->createToken('test', ['superadmin'])->plainTextToken,
        );
    }

    private function entrarConContrasenia(): TestResponse
    {
        $this->desdeCero();

        return $this->postJson('/api/platform/login', ['email' => self::CORREO, 'password' => self::CONTRASENIA]);
    }

    private function segundoPaso(string $desafio, string $codigo): TestResponse
    {
        $this->desdeCero();

        return $this->postJson('/api/platform/login/dos-pasos', ['desafio' => $desafio, 'codigo' => $codigo]);
    }

    /**
     * Otra petición, sin nada de la anterior: `auth:sanctum` recuerda al usuario
     * entre peticiones de un mismo test si no se le dice que lo olvide.
     */
    private function desdeCero(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse($this->guardOriginal);
    }

    private function unaTienda(): Tenant
    {
        return Tenant::create([
            'slug' => 'tienda-soporte', 'name' => 'Tienda Soporte',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);
    }
}
