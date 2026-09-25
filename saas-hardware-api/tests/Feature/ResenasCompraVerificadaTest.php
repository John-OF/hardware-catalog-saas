<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `ACC-2`. La compra verificada se conseguia con un digito y se saltaba la
 * moderacion: el telefono se comparaba por SUFIJO (`LIKE '%7'`) y coincidir
 * aprobaba la resena sola. Tambien se aprobaba sola la de cualquier cliente
 * registrado, y el registro es abierto. Ahora solo se publica sola la de quien
 * compro de verdad con su cuenta; el telefono de un anonimo pone la insignia
 * -que el dueno ve al moderar- pero no aprueba, y la respuesta no dice si
 * coincidio.
 */
class ResenasCompraVerificadaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        config(['services.turnstile.secret' => 'clave-real']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->tenant = Tenant::create([
            'slug' => 'tienda-verificada', 'name' => 'Tienda Verificada',
            'whatsapp_number' => '51999999999', 'is_active' => true,
        ]);

        $this->producto = new Product(['name' => 'GPU', 'price' => 100, 'stock' => 5, 'status' => 'published', 'is_active' => true]);
        $this->producto->tenant_id = $this->tenant->id;
        $this->producto->save();
    }

    // ------------------------------------------------------------ el ataque

    public function test_un_digito_ya_no_consigue_la_compra_verificada(): void
    {
        $this->pedidoAtendido('51922222227');

        $this->resenar(['customer_phone' => '7'])->assertCreated();

        $review = $this->unicaResena();
        $this->assertFalse($review->is_approved, 'Un digito volvio a saltarse la moderacion.');
        $this->assertFalse($review->verified_purchase);
    }

    public function test_el_telefono_exacto_de_un_comprador_pone_la_insignia_pero_no_aprueba(): void
    {
        $this->pedidoAtendido('51922222222');

        $this->resenar(['customer_phone' => '51922222222'])->assertCreated();

        $review = $this->unicaResena();
        $this->assertTrue($review->verified_purchase);
        $this->assertFalse($review->is_approved, 'Nadie comprueba que el telefono sea de quien lo escribe: tiene que moderarse.');
    }

    /** El checkout guarda con prefijo; la venta de mostrador, como la escriba el dueno. */
    public function test_el_telefono_coincide_con_o_sin_prefijo_y_con_separadores(): void
    {
        $this->pedidoAtendido('+51 922-222-222');
        $this->resenar(['customer_phone' => '922222222'])->assertCreated();
        $this->assertTrue($this->unicaResena()->verified_purchase, 'Sin prefijo contra con prefijo.');

        Review::withoutGlobalScopes()->delete();

        $this->resenar(['customer_phone' => '51922222222', 'visitor_id' => 'otro-navegador'])->assertCreated();
        $this->assertTrue($this->unicaResena()->verified_purchase, 'Con prefijo contra con prefijo y separadores.');
    }

    public function test_menos_de_ocho_digitos_no_cuenta_aunque_coincida_entero(): void
    {
        $this->pedidoAtendido('2222222');

        $this->resenar(['customer_phone' => '2222222'])->assertCreated();

        $this->assertFalse($this->unicaResena()->verified_purchase);
    }

    public function test_un_pedido_sin_atender_no_cuenta_como_compra(): void
    {
        $this->pedidoAtendido('51922222222', 'pending');

        $this->resenar(['customer_phone' => '51922222222'])->assertCreated();

        $this->assertFalse($this->unicaResena()->verified_purchase);
    }

    // ---------------------------------------------------------- el oraculo

    /**
     * La respuesta de una resena pendiente es la misma haya coincidido el
     * telefono o no: si no, publicar una resena con el numero de otra persona
     * diria si esa persona compro este producto en esta tienda.
     */
    public function test_la_respuesta_no_dice_si_el_telefono_coincidio(): void
    {
        $this->pedidoAtendido('51922222222');

        $coincide = $this->resenar(['customer_phone' => '51922222222', 'visitor_id' => 'a'])->assertCreated();
        $noCoincide = $this->resenar(['customer_phone' => '51933333333', 'visitor_id' => 'b'])->assertCreated();

        foreach ([$coincide, $noCoincide] as $respuesta) {
            $this->assertArrayNotHasKey('verified_purchase', $respuesta->json('review'));
            $this->assertFalse($respuesta->json('is_approved'));
        }

        $this->assertSame($coincide->json('message'), $noCoincide->json('message'));
        $this->assertSame(array_keys($coincide->json('review')), array_keys($noCoincide->json('review')));
    }

    public function test_la_resena_propia_pendiente_tampoco_lo_dice(): void
    {
        $this->pedidoAtendido('51922222222');
        $this->resenar(['customer_phone' => '51922222222', 'visitor_id' => 'mi-navegador'])->assertCreated();

        $respuesta = $this->withHeaders(['X-Visitor-Id' => 'mi-navegador'])
            ->getJson("/api/public/{$this->tenant->slug}/products/{$this->producto->id}")
            ->assertOk();

        $this->assertFalse($respuesta->json('user_review.is_approved'));
        $this->assertArrayNotHasKey('verified_purchase', $respuesta->json('user_review'));
    }

    // ------------------------------------------------------- con cuenta

    /**
     * El registro de clientes es abierto: tener cuenta cuesta un formulario, asi
     * que por si sola no acredita nada. Antes esto se publicaba solo.
     */
    public function test_un_cliente_registrado_sin_compra_pasa_por_moderacion(): void
    {
        $this->resenar([], $this->cliente())->assertCreated()->assertJsonPath('is_approved', false);

        $review = $this->unicaResena();
        $this->assertFalse($review->is_approved);
        $this->assertFalse($review->verified_purchase);
    }

    public function test_un_cliente_con_un_pedido_atendido_a_su_nombre_publica_al_instante(): void
    {
        $cliente = $this->cliente();
        $this->pedidoAtendido('51922222222', 'attended', $cliente);

        $this->resenar([], $cliente)->assertCreated()->assertJsonPath('is_approved', true);

        $review = $this->unicaResena();
        $this->assertTrue($review->is_approved);
        $this->assertTrue($review->verified_purchase);
    }

    public function test_el_pedido_tiene_que_ser_de_este_producto(): void
    {
        $cliente = $this->cliente();

        $otro = new Product(['name' => 'Otro', 'price' => 10, 'stock' => 5, 'status' => 'published', 'is_active' => true]);
        $otro->tenant_id = $this->tenant->id;
        $otro->save();

        $this->pedidoAtendido('51922222222', 'attended', $cliente, $otro);

        $this->resenar([], $cliente)->assertCreated();

        $this->assertFalse($this->unicaResena()->is_approved);
    }

    // ------------------------------------------------------------ el panel

    /**
     * La insignia es una pista para moderar, asi que el panel tiene que verla, y
     * saber si viene de una cuenta o solo de un telefono escrito a mano.
     */
    public function test_el_panel_ve_la_insignia_y_si_viene_de_una_cuenta(): void
    {
        $cliente = $this->cliente();
        $this->pedidoAtendido('51944444444', 'attended', $cliente);
        $this->resenar([], $cliente)->assertCreated();

        $this->pedidoAtendido('51922222222');
        $this->resenar(['customer_phone' => '51922222222', 'visitor_id' => 'anonimo'])->assertCreated();

        $admin = new User(['name' => 'Duenio', 'email' => 'duenio@verificada.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $admin->tenant_id = $this->tenant->id;
        $admin->save();

        $this->app['auth']->forgetGuards();

        $resenas = collect($this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant'      => $this->tenant->slug,
        ])->getJson('/api/reviews')->assertOk()->json('data'));

        $this->assertCount(2, $resenas);
        $this->assertTrue($resenas->every(fn ($r) => $r['verified_purchase'] === true));

        foreach ($resenas as $r) {
            $this->assertArrayHasKey('user_id', $r, 'El panel no recibe el user_id y no puede distinguir las dos.');
        }

        $this->assertEqualsCanonicalizing([$cliente->id, null], $resenas->pluck('user_id')->all());
    }

    // ------------------------------------------------------------ apoyo

    private function resenar(array $datos = [], ?User $cliente = null): TestResponse
    {
        // `withHeaders()` persiste entre peticiones: sin esto, una resena
        // "anonima" despues de una con cuenta seguiria llevando el token.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $peticion = $cliente
            ? $this->withHeaders(['Authorization' => 'Bearer '.$cliente->createToken('c', ['customer'])->plainTextToken])
            : $this;

        return $peticion->postJson("/api/public/{$this->tenant->slug}/products/{$this->producto->id}/reviews", array_merge([
            'customer_name'   => 'Quien sea',
            'rating'          => 5,
            'comment'         => 'Buenisima',
            'turnstile_token' => 'token-ok',
            'visitor_id'      => 'navegador-1',
        ], $datos));
    }

    private function pedidoAtendido(string $telefono, string $estado = 'attended', ?User $cliente = null, ?Product $producto = null): Order
    {
        $producto ??= $this->producto;

        $pedido = new Order([
            'customer_name' => 'Comprador', 'customer_phone' => $telefono,
            'status' => $estado, 'total' => 100,
        ]);
        $pedido->tenant_id = $this->tenant->id;
        $pedido->user_id = $cliente?->id;
        $pedido->save();

        $pedido->items()->create([
            'product_id' => $producto->id, 'product_name' => $producto->name,
            'unit_price' => 100, 'quantity' => 1, 'subtotal' => 100,
        ]);

        return $pedido;
    }

    private function cliente(): User
    {
        $cliente = new User(['name' => 'Cli', 'email' => 'cli@verificada.test', 'password' => 'password123', 'role' => 'customer', 'is_active' => true]);
        $cliente->tenant_id = $this->tenant->id;
        $cliente->save();

        return $cliente;
    }

    private function unicaResena(): Review
    {
        return Review::withoutGlobalScopes()->sole();
    }
}
