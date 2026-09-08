<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Los correos encolados salen de verdad con la cola de produccion.
 *
 * **Este fichero existe porque la suite entera se estaba mintiendo.** `phpunit.xml`
 * fija `QUEUE_CONNECTION=sync`, asi que en los tests un correo encolado se envia en
 * el acto, sin pasar por la cola: nunca se serializa un payload ni se ejecuta el
 * envoltorio tenant aware de spatie. En produccion `QUEUE_CONNECTION=database`, y
 * ahi la recuperacion de contrasenia (SAAS-2) **no enviaba nada desde que se
 * encolo** (AUD-11): `queues_are_tenant_aware_by_default => true` exige un
 * `tenantId` en el payload, y esa ruta no resuelve tienda a proposito, porque quien
 * la usa es quien no puede entrar.
 *
 * Lo peor era el silencio: el trabajo se borraba **sin llegar a `failed_jobs`**, asi
 * que no habia ni correo ni rastro en la bandeja de fallidos, solo una linea de
 * ERROR en el log. Se descubrio el 2026-09-07 al levantar el worker a mano.
 *
 * Por eso estos tests cambian la conexion de cola a `database` y **corren el worker
 * de verdad**: es la unica forma de que el fallo aparezca. Cubren un camino de cada
 * clase, que es lo que importa mantener:
 *
 * 1. Un correo SIN tienda (reset) tiene que salir igual.
 * 2. Un correo CON tienda (pedido nuevo) tiene que seguir saliendo, para que nadie
 *    arregle el punto 1 marcandolo todo como `NotTenantAware`.
 * 3. Un correo disparado desde un HOOK DEL MODELO y no desde el controlador (el
 *    aviso de reposicion, FUN-1b), que es el tercer camino por el que se encola.
 *
 * Al anadir un correo nuevo, anadir aqui su caso: la suite normal no lo cubre.
 */
class QueuedMailWithRealQueueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        // La conexion de cola de produccion, no la `sync` del phpunit.xml.
        config()->set('queue.default', 'database');

        $this->tenant = Tenant::create([
            'slug'            => 'tienda-cola',
            'name'            => 'Tienda Cola',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);

        $this->admin = new User([
            'name'      => 'Duenio',
            'email'     => 'duenio@tienda-cola.com',
            'password'  => 'password123',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    public function test_el_correo_de_recuperacion_sale_aunque_no_haya_tienda_resuelta(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => $this->admin->email])
            ->assertOk();

        // Primero se comprueba que de verdad quedo encolado: si esto fuera 0, el
        // test estaria pasando por la puerta de atras (envio sincrono) y no probaria
        // nada de lo que vino a probar.
        $this->assertSame(1, DB::table('jobs')->count(), 'El correo no llego a la cola.');

        $this->trabajarLaCola();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'El trabajo fallo en el worker.');

        $mensajes = $this->correosEnviados();

        $this->assertCount(1, $mensajes, 'El worker no envio el correo de recuperacion.');
        $this->assertStringContainsString($this->admin->email, $mensajes[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame('Recupera el acceso a tu tienda', $mensajes[0]->getOriginalMessage()->getSubject());
    }

    public function test_el_aviso_de_pedido_nuevo_sigue_saliendo_con_su_tienda(): void
    {
        $producto = new Product([
            'name'      => 'RAM 16GB',
            'price'     => 100,
            'stock'     => 5,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $producto->tenant_id = $this->tenant->id;
        $producto->save();

        $this->postJson("/api/public/{$this->tenant->slug}/orders", [
            'customer_name'  => 'Compradora',
            'customer_phone' => '999888777',
            'customer_email' => 'compradora@ejemplo.com',
            'items'          => [
                ['product_id' => $producto->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        // Dos: el aviso al dueno (OWN-2) y la confirmacion al comprador (FUN-2).
        $this->assertSame(2, DB::table('jobs')->count());

        $this->trabajarLaCola();

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Un correo del pedido fallo en el worker.');
        $this->assertCount(2, $this->correosEnviados());
    }

    public function test_el_aviso_de_reposicion_de_stock_sale_por_la_cola(): void
    {
        // FUN-1b se dispara desde el hook `updated` del modelo, o sea desde el
        // guardado de un producto en el panel: un camino distinto a los otros dos
        // correos, y por tanto uno mas que comprobar contra la cola de verdad.
        $agotado = new Product([
            'name'      => 'RTX 5090',
            'price'     => 3000,
            'stock'     => 0,
            'status'    => 'published',
            'is_active' => true,
        ]);
        $agotado->tenant_id = $this->tenant->id;
        $agotado->save();

        $this->postJson("/api/public/{$this->tenant->slug}/products/{$agotado->id}/notify-me", [
            'customer_name'    => 'Ana',
            'customer_contact' => 'ana@ejemplo.com',
        ])->assertCreated();

        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => $this->tenant->slug,
        ])->putJson("/api/products/{$agotado->id}", ['stock' => 4])
            ->assertOk();

        $this->assertSame(1, DB::table('jobs')->count(), 'El aviso de reposicion no llego a la cola.');

        $this->trabajarLaCola();

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'El aviso de reposicion fallo en el worker.');

        $mensajes = $this->correosEnviados();
        $this->assertCount(1, $mensajes);
        $this->assertStringContainsString('Ya llego', $mensajes[0]->getOriginalMessage()->getSubject());
    }

    /**
     * Corre el worker de verdad hasta vaciar la cola.
     *
     * En proceso, asi que comparte contenedor con el test y el transporte `array`
     * del correo recoge lo que se envie. `--tries=1` para que un fallo caiga en
     * `failed_jobs` en vez de reintentarse.
     */
    private function trabajarLaCola(): void
    {
        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--tries'           => 1,
            '--sleep'           => 0,
        ]);
    }

    /**
     * @return array<int, \Symfony\Component\Mailer\SentMessage>
     */
    private function correosEnviados(): array
    {
        return Mail::getSymfonyTransport()->messages()->all();
    }
}
