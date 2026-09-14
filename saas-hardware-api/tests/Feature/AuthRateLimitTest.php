<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fija TEC-13: cada formulario limitado tiene su propio contador.
 *
 * Antes todas estas rutas llevaban `throttle:N,1` sin nombre, cuya clave es
 * solo la IP, y el contador sumaba cada peticion. Se descubrio verificando
 * UI-11: tras cinco logins de plataforma, el login de tienda respondio 429 al
 * primer intento. Estos casos no prueban que haya limite -eso ya lo hacia el
 * throttle viejo- sino A QUIEN se le cuenta cada intento.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tiendaA;

    private Tenant $tiendaB;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->tiendaA = Tenant::create([
            'slug' => 'tienda-a', 'name' => 'Tienda A',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->tiendaB = Tenant::create([
            'slug' => 'tienda-b', 'name' => 'Tienda B',
            'whatsapp_number' => '51888888888', 'is_active' => true,
        ]);

        $this->makeUser('duenio@tienda-a.com', 'admin', $this->tiendaA);
        $this->makeUser('operador@plataforma.com', 'superadmin', null);
        $this->makeUser('cliente1@correo.com', 'customer', $this->tiendaA);
        $this->makeUser('cliente2@correo.com', 'customer', $this->tiendaA);
        $this->makeUser('cliente1@correo.com', 'customer', $this->tiendaB);
    }

    private function makeUser(string $email, string $role, ?Tenant $tenant): User
    {
        $user = new User([
            'name' => $role, 'email' => $email,
            'password' => 'password123', 'role' => $role, 'is_active' => true,
        ]);
        $user->tenant_id = $tenant?->id;
        $user->save();

        return $user;
    }

    private function loginFallido(string $url, string $email, int $veces): void
    {
        for ($i = 0; $i < $veces; $i++) {
            $this->postJson($url, ['email' => $email, 'password' => 'incorrecta'])->assertStatus(422);
        }
    }

    /** El caso exacto que aparecio al verificar UI-11. */
    public function test_fallar_el_login_de_plataforma_no_bloquea_el_login_del_panel(): void
    {
        $this->loginFallido('/api/platform/login', 'operador@plataforma.com', 5);
        $this->postJson('/api/platform/login', ['email' => 'operador@plataforma.com', 'password' => 'incorrecta'])
            ->assertStatus(429);

        $this->postJson('/api/auth/login', ['email' => 'duenio@tienda-a.com', 'password' => 'password123'])
            ->assertOk();
    }

    /** El de la explicacion: un comprador en el wifi de la tienda y el dueño. */
    public function test_un_cliente_fallando_su_contrasenia_no_bloquea_al_duenio(): void
    {
        $this->loginFallido('/api/public/tienda-a/auth/login', 'cliente1@correo.com', 5);

        $this->postJson('/api/auth/login', ['email' => 'duenio@tienda-a.com', 'password' => 'password123'])
            ->assertOk();
    }

    /** El login sigue protegido: por correo, a los 5 intentos. */
    public function test_el_login_sigue_limitado_para_el_correo_que_falla(): void
    {
        $url = '/api/public/tienda-a/auth/login';

        $this->loginFallido($url, 'cliente1@correo.com', 5);
        $this->postJson($url, ['email' => 'cliente1@correo.com', 'password' => 'password123'])->assertStatus(429);

        // El mismo formulario, desde la misma IP, con otro correo: no le toca.
        $this->postJson($url, ['email' => 'cliente2@correo.com', 'password' => 'password123'])->assertOk();
    }

    /** Mayusculas o espacios no dan un cupo nuevo para el mismo correo. */
    public function test_el_correo_se_normaliza_antes_de_contar(): void
    {
        $url = '/api/public/tienda-a/auth/login';

        $this->loginFallido($url, 'cliente1@correo.com', 5);

        $this->postJson($url, ['email' => 'CLIENTE1@correo.com', 'password' => 'incorrecta'])->assertStatus(429);
    }

    /** Sin techo por IP, una lista de correos tendria 5 intentos por cada uno. */
    public function test_ir_cambiando_de_correo_tiene_techo_por_ip(): void
    {
        $url = '/api/auth/login';

        for ($i = 0; $i < 20; $i++) {
            $this->postJson($url, ['email' => "probando{$i}@lista.com", 'password' => 'x'])->assertStatus(422);
        }

        $this->postJson($url, ['email' => 'otro-mas@lista.com', 'password' => 'x'])->assertStatus(429);
    }

    public function test_desde_otra_ip_el_mismo_correo_tiene_su_propio_cupo(): void
    {
        $url = '/api/auth/login';

        $this->loginFallido($url, 'duenio@tienda-a.com', 5);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson($url, ['email' => 'duenio@tienda-a.com', 'password' => 'password123'])
            ->assertOk();
    }

    /** Las cuentas de cliente son por tienda, y su cupo tambien. */
    public function test_el_cupo_del_login_de_clientes_es_por_tienda(): void
    {
        $this->loginFallido('/api/public/tienda-a/auth/login', 'cliente1@correo.com', 5);

        $this->postJson('/api/public/tienda-b/auth/login', ['email' => 'cliente1@correo.com', 'password' => 'password123'])
            ->assertOk();
    }

    /**
     * Hacer pedidos no gasta el cupo del login. Con el throttle viejo, seis
     * peticiones a `/orders` -validas o no- dejaban la IP en 429 para cualquier
     * login, porque `throttle:10,1` y `throttle:5,1` escribian el mismo contador.
     */
    public function test_hacer_pedidos_no_gasta_el_cupo_del_login(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/public/tienda-a/orders', [])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'duenio@tienda-a.com', 'password' => 'password123'])
            ->assertOk();
    }

    public function test_recuperar_contrasenia_tiene_su_limite_y_no_gasta_el_del_login(): void
    {
        $url = '/api/auth/forgot-password';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($url, ['email' => 'nadie@correo.com'])->assertOk();
        }
        $this->postJson($url, ['email' => 'nadie@correo.com'])->assertStatus(429);

        $this->postJson('/api/auth/login', ['email' => 'duenio@tienda-a.com', 'password' => 'password123'])
            ->assertOk();
    }
}
