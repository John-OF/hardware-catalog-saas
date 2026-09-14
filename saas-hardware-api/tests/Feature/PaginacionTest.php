<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fija TEC-12: ningun listado paginado devuelve la tabla entera de una vez.
 *
 * Pedidos, resenias, lista de espera y tiendas de plataforma leian `per_page`
 * sin tope. La bitacora de plataforma, que ya lo tenia, esta en
 * `PlatformPanelTest`.
 */
class PaginacionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $duenio;

    private User $operador;

    private string $guardOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->guardOriginal = (string) config('auth.defaults.guard');

        $this->tienda = Tenant::create([
            'slug' => 'tienda-a', 'name' => 'Tienda A',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->duenio = $this->makeUser('duenio@tienda-a.com', 'admin', $this->tienda);
        $this->operador = $this->makeUser('operador@plataforma.com', 'superadmin', null);
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

    /** Mismo motivo que en PlatformPanelTest: el guard recuerda al usuario anterior. */
    private function desdeCero(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse($this->guardOriginal);
    }

    private function comoDuenio(): static
    {
        $this->desdeCero();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->duenio->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
        ]);
    }

    private function comoOperador(): static
    {
        $this->desdeCero();

        return $this->withHeader(
            'Authorization',
            'Bearer '.$this->operador->createToken('test', ['superadmin'])->plainTextToken,
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: int}> ruta, si es de plataforma, valor por defecto
     */
    public static function listados(): array
    {
        return [
            'pedidos' => ['/api/orders', false, 15],
            'resenias' => ['/api/reviews', false, 15],
            'lista de espera' => ['/api/stock-notifications', false, 20],
            'tiendas plataforma' => ['/api/platform/tenants', true, 20],
        ];
    }

    private function pedir(string $ruta, bool $plataforma, string $perPage)
    {
        $cliente = $plataforma ? $this->comoOperador() : $this->comoDuenio();

        return $cliente->getJson($ruta.'?per_page='.$perPage)->assertOk();
    }

    #[DataProvider('listados')]
    public function test_un_per_page_enorme_se_queda_en_el_maximo(string $ruta, bool $plataforma, int $porDefecto): void
    {
        $this->pedir($ruta, $plataforma, '100000')->assertJsonPath('per_page', 100);
    }

    #[DataProvider('listados')]
    public function test_un_per_page_sin_sentido_cae_al_valor_por_defecto(string $ruta, bool $plataforma, int $porDefecto): void
    {
        $this->pedir($ruta, $plataforma, '0')->assertJsonPath('per_page', $porDefecto);
        $this->pedir($ruta, $plataforma, '-5')->assertJsonPath('per_page', $porDefecto);
        $this->pedir($ruta, $plataforma, 'abc')->assertJsonPath('per_page', $porDefecto);
    }

    #[DataProvider('listados')]
    public function test_un_per_page_valido_se_respeta(string $ruta, bool $plataforma, int $porDefecto): void
    {
        // Los que usa el frontend hoy: 10 en Pedidos y Resenias, 15 en Lista de espera.
        $this->pedir($ruta, $plataforma, '10')->assertJsonPath('per_page', 10);
    }
}
