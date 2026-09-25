<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `ACC-7`: la auditoria de TODAS las rutas publicas que siguio a `ACC-1`, fijada.
 *
 * Se recorre cada ruta sin sesion (y las de la cuenta de cliente) con una tienda
 * que tiene de todo lo que no debe salir: el token del dominio propio, plan,
 * fin de la prueba, visitas, costos, correos de otros compradores y un borrador.
 * Lo que encontro: el pedido del checkout devolvia la fila ENTERA de la tienda
 * -las notificaciones leen `$order->tenant` y eso la deja cargada en la misma
 * instancia- y el catalogo publicaba las visitas de cada producto.
 *
 * Una ruta publica nueva se anade a `respuestasPublicas()`: es la lista que
 * este test vigila.
 */
class RutasPublicasNoExponenDatosInternosTest extends TestCase
{
    use RefreshDatabase;

    /** Lo que ninguna respuesta publica puede llevar, con el motivo. */
    private const PROHIBIDO = [
        'SECRETO-TOKEN-DOMINIO' => 'el token del dominio propio',
        'custom_domain_token'   => 'el token del dominio propio',
        'trial_ends_at'         => 'el fin de la prueba',
        'next_order_number'     => 'el volumen de pedidos de la tienda',
        '"plan"'                => 'el plan contratado',
        '"views_count"'         => 'las visitas (AUD-9)',
        '"cost"'                => 'el costo de compra (MOD-6)',
        'unit_cost'             => 'el costo de compra (MOD-6)',
        '61.37'                 => 'el costo de un producto',
        '33.19'                 => 'el costo de una variante',
        'ana@privado.test'      => 'el correo de quien reseno (ACC-1)',
        'VIS-ANA'               => 'el visitor_id de quien reseno (ACC-1)',
        'otro@privado.test'     => 'el correo de otro comprador',
        'eva@privado.test'      => 'el contacto de una lista de espera',
        'duenio@x.test'         => 'el correo del dueno',
        'BORRADOR-OCULTO'       => 'un producto en borrador',
        '"tenant":'             => 'la fila de la tienda colgada de otro modelo',
    ];

    private Tenant $tenant;
    private Product $producto;
    private Product $conVariantes;
    private ProductVariant $variante;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug' => 'tienda-x', 'name' => 'Tienda X', 'whatsapp_number' => '51999999999',
            'is_active' => true, 'is_published' => true, 'plan' => 'pro', 'custom_domain' => 'tiendax.test',
        ]);
        DB::table('tenants')->where('id', $this->tenant->id)->update([
            'custom_domain_token' => 'SECRETO-TOKEN-DOMINIO', 'custom_domain_verified_at' => now(),
            'views_count' => 4242, 'trial_ends_at' => now()->addDays(3),
        ]);
        $this->tenant->refresh()->makeCurrent();

        $categoria = $this->deLaTienda(new Category(['name' => 'GPUs', 'is_active' => true]));

        $this->producto = $this->deLaTienda(new Product([
            'name' => 'RTX', 'price' => 100, 'stock' => 5, 'status' => 'published', 'is_active' => true,
            'cost' => 61.37, 'category_id' => $categoria->id,
        ]));
        DB::table('products')->where('id', $this->producto->id)->update(['views_count' => 777]);

        $this->conVariantes = $this->deLaTienda(new Product([
            'name' => 'RAM', 'price' => 50, 'stock' => 0, 'status' => 'published', 'is_active' => true,
            'category_id' => $categoria->id,
        ]));
        $this->variante = $this->deLaTienda(new ProductVariant([
            'product_id' => $this->conVariantes->id, 'options' => [['name' => 'Capacidad', 'value' => '8 GB']],
            'price' => 50, 'stock' => 0, 'cost' => 33.19,
        ]));

        $this->deLaTienda(new Product(['name' => 'BORRADOR-OCULTO', 'price' => 10, 'stock' => 5, 'status' => 'draft', 'is_active' => true]));

        $this->deLaTienda(new Review([
            'product_id' => $this->producto->id, 'visitor_id' => 'VIS-ANA', 'customer_name' => 'Ana',
            'customer_email' => 'ana@privado.test', 'rating' => 5, 'is_approved' => true,
        ]));

        $this->deLaTienda(new Page(['title' => 'Envios', 'slug' => 'envios', 'content' => '<p>Hola</p>', 'is_active' => true]));
        $this->deLaTienda(new Coupon(['code' => 'DESC10', 'type' => 'percent', 'value' => 10, 'is_active' => true, 'max_uses' => 50]));

        $this->admin = $this->deLaTienda(new User([
            'name' => 'Duenio', 'email' => 'duenio@x.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]));

        $this->deLaTienda(new Order([
            'customer_name' => 'Otro', 'customer_phone' => '51911111111', 'customer_email' => 'otro@privado.test',
            'status' => 'attended', 'total' => 100,
        ]));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     * @param  T  $modelo
     * @return T
     */
    private function deLaTienda($modelo)
    {
        $modelo->tenant_id = $this->tenant->id;
        $modelo->save();

        return $modelo;
    }

    /** @return array<string, TestResponse> */
    private function respuestasPublicas(): array
    {
        $base = "/api/public/{$this->tenant->slug}";
        $r = [];

        $r['GET tienda']           = $this->getJson($base);
        $r['GET resolve-domain']   = $this->getJson('/api/public/resolve-domain?domain=tiendax.test');
        $r['GET planes']           = $this->getJson('/api/public/plans');
        $r['GET categorias']       = $this->getJson("$base/categories");
        $r['GET productos']        = $this->getJson("$base/products");
        $r['GET facetas']          = $this->getJson("$base/facets");
        $r['GET ficha']            = $this->getJson("$base/products/{$this->producto->id}");
        $r['GET ficha variantes']  = $this->getJson("$base/products/{$this->conVariantes->id}");
        $r['GET paginas']          = $this->getJson("$base/pages");
        $r['GET pagina']           = $this->getJson("$base/pages/envios");
        $r['POST cupon']           = $this->postJson("$base/coupons/check", ['code' => 'DESC10', 'subtotal' => 100]);
        $r['POST avisame']         = $this->postJson("$base/products/{$this->conVariantes->id}/notify-me", [
            'customer_name' => 'Eva', 'customer_contact' => 'eva@privado.test', 'variant_id' => $this->variante->id,
        ]);

        $r['POST registro'] = $this->postJson("$base/auth/register", [
            'name' => 'Cli', 'email' => 'cli@x.test', 'password' => 'password123',
            'password_confirmation' => 'password123', 'phone' => '51922222222',
        ]);
        $cabeceras = ['Authorization' => 'Bearer '.$r['POST registro']->json('token')];

        // Con la cuenta del cliente: el pedido y lo que ve despues.
        $r['POST pedido'] = $this->comoCliente($cabeceras)->postJson("$base/orders", [
            'customer_name' => 'Cli', 'customer_phone' => '51922222222', 'customer_email' => 'cli@x.test',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]], 'coupon_code' => 'DESC10',
        ]);
        $r['GET mis pedidos']   = $this->comoCliente($cabeceras)->getJson("$base/my-orders");
        $r['POST favorito']     = $this->comoCliente($cabeceras)->postJson("$base/favorites/{$this->producto->id}");
        $r['GET favoritos']     = $this->comoCliente($cabeceras)->getJson("$base/favorites");
        $r['GET yo']            = $this->comoCliente($cabeceras)->getJson("$base/auth/me");

        // Lo que se le sirve a un buscador (INF-4).
        $bot = ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        $this->app['auth']->forgetGuards();
        $r['WEB catalogo'] = $this->withHeaders($bot)->get('/tienda-x');
        $r['WEB ficha']    = $this->withHeaders($bot)->get("/tienda-x/product/{$this->producto->id}");
        $r['WEB pagina']   = $this->withHeaders($bot)->get('/tienda-x/p/envios');
        $r['WEB armador']  = $this->withHeaders($bot)->get('/tienda-x/builder');
        $r['WEB sitemap']  = $this->get('/tienda-x/sitemap.xml');

        return $r;
    }

    private function comoCliente(array $cabeceras): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($cabeceras);
    }

    public function test_ninguna_ruta_publica_devuelve_datos_internos(): void
    {
        foreach ($this->respuestasPublicas() as $ruta => $respuesta) {
            $this->assertLessThan(300, $respuesta->getStatusCode(), "{$ruta} no respondio bien: el barrido no la esta mirando.");

            foreach (self::PROHIBIDO as $marca => $que) {
                $this->assertStringNotContainsString($marca, $respuesta->getContent(), "{$ruta} expone {$que}.");
            }
        }
    }

    /**
     * El caso concreto que destapo el barrido, con nombre propio: las dos
     * notificaciones del pedido leen `$order->tenant` al construirse, y eso
     * cargaba la relacion en la instancia que luego se devolvia al comprador.
     */
    public function test_el_pedido_del_checkout_no_arrastra_la_tienda(): void
    {
        $respuesta = $this->postJson("/api/public/{$this->tenant->slug}/orders", [
            'customer_name' => 'Anonimo', 'customer_phone' => '51933333333', 'customer_email' => 'anon@x.test',
            'items' => [['product_id' => $this->producto->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertArrayNotHasKey('tenant', $respuesta->json());
        $this->assertNotNull($respuesta->json('number'), 'El pedido tiene que seguir llegando entero.');
    }

    /** El panel sigue viendo las visitas: es el unico sitio donde son el dato. */
    public function test_el_resumen_del_panel_sigue_viendo_las_visitas(): void
    {
        $token = $this->admin->createToken('test', ['admin'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Tenant' => $this->tenant->slug])
            ->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('most_viewed_products.0.views_count', 777);
    }
}
