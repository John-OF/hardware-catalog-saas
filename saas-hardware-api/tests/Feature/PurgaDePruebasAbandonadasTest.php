<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\TenantPayment;
use App\Models\User;
use App\Notifications\AbandonedTrialNotification;
use App\Support\Comprobantes;
use App\Support\Copias;
use App\Support\PruebasAbandonadas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La purga de las pruebas abandonadas (INF-24).
 *
 * Es un borrado masivo y automático, así que lo que más se prueba es lo que NO
 * hace: tocar otra tienda, tocar a quien pagó alguna vez, borrar sin dos avisos
 * o antes de la fecha prometida, y borrar sin una copia reciente de la base.
 */
class PurgaDePruebasAbandonadasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 05:00:00');
        Notification::fake();
        Storage::fake('public');
        Storage::fake(Comprobantes::DISCO);

        // Una copia de hace una hora: sin ella no se borra nada.
        config(['backups.disco' => 'copias']);
        Storage::fake('copias');
        Storage::disk('copias')->put(Copias::CARPETA.'/reciente.sql.gz', 'x');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una prueba que se cerró hace `$dias` días, con su admin. */
    private function pruebaCerrada(string $slug, int $dias, bool $pagoAlgunaVez = false): Tenant
    {
        $tienda = Tenant::create([
            'slug' => $slug, 'name' => "Tienda {$slug}", 'whatsapp_number' => '51999999999',
            'is_active' => false, 'timezone' => 'America/Lima',
        ]);
        $tienda->forceFill(['trial_ends_at' => now()->subDays($dias)])->save();

        $admin = new User(['name' => 'Dueña '.$slug, 'email' => "{$slug}@purga.test", 'password' => 'secret1234', 'role' => 'admin', 'is_active' => true]);
        $admin->tenant_id = $tienda->id;
        $admin->save();

        if ($pagoAlgunaVez) {
            TenantPayment::create([
                'tenant_id' => $tienda->id, 'plan' => 'pro', 'months' => 1, 'amount_usd' => 20, 'method' => 'transferencia',
                'paid_on' => now()->subMonths(6)->toDateString(), 'starts_at' => now()->subMonths(6), 'ends_at' => now()->subMonths(5),
            ]);
        }

        return $tienda->fresh();
    }

    /**
     * Un catálogo con una de cada: producto con foto, galería y variante, una
     * reseña, una espera, un favorito, un pedido con su línea y su comprobante,
     * y un cliente del directorio.
     */
    private function conDatos(Tenant $tienda): array
    {
        $producto = (string) Str::uuid();
        $variante = (string) Str::uuid();
        $pedido = (string) Str::uuid();
        $fotos = ["products/{$tienda->slug}/principal.webp", "products/{$tienda->slug}/galeria.webp", "products/{$tienda->slug}/variante.webp"];
        $comprobante = "comprobantes/{$tienda->id}/pago.png";

        foreach ($fotos as $foto) {
            Storage::disk('public')->put($foto, 'foto');
        }
        Storage::disk(Comprobantes::DISCO)->put($comprobante, 'png');

        $comprador = new User(['name' => 'Comprador', 'email' => "comprador@{$tienda->slug}.test", 'password' => 'secret1234', 'role' => 'customer', 'is_active' => true]);
        $comprador->tenant_id = $tienda->id;
        $comprador->save();

        DB::table('products')->insert(['id' => $producto, 'tenant_id' => $tienda->id, 'name' => 'Ryzen', 'price' => 100, 'image_url' => $fotos[0]]);
        DB::table('product_images')->insert(['id' => (string) Str::uuid(), 'product_id' => $producto, 'image_url' => $fotos[1]]);
        DB::table('product_variants')->insert(['id' => $variante, 'tenant_id' => $tienda->id, 'product_id' => $producto, 'options' => '[]', 'price' => 100, 'image_url' => $fotos[2]]);
        DB::table('reviews')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tienda->id, 'product_id' => $producto, 'customer_name' => 'Ana', 'rating' => 5]);
        DB::table('stock_notifications')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tienda->id, 'product_id' => $producto, 'variant_id' => $variante, 'customer_name' => 'Ana', 'customer_contact' => '999']);
        DB::table('user_favorites')->insert(['user_id' => $comprador->id, 'product_id' => $producto]);
        DB::table('orders')->insert(['id' => $pedido, 'tenant_id' => $tienda->id, 'customer_name' => 'Ana', 'tracking_token' => Str::random(40), 'payment_proof_path' => $comprobante]);
        DB::table('order_items')->insert(['id' => (string) Str::uuid(), 'order_id' => $pedido, 'product_id' => $producto, 'product_name' => 'Ryzen', 'unit_price' => 100, 'quantity' => 1, 'subtotal' => 100]);
        DB::table('customers')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tienda->id, 'name' => 'Ana invitada', 'phone_key' => '99999999']);

        return ['fotos' => $fotos, 'comprobante' => $comprobante, 'comprador' => $comprador];
    }

    /** Avanza el reloj y lanza el comando, como haría el cron ese día. */
    private function correrEl(string $fecha, int $salida = 0): string
    {
        Carbon::setTestNow($fecha);

        // La copia de esa misma madrugada, como la dejaría `copias:crear`.
        $copia = Copias::CARPETA.'/reciente.sql.gz';
        if (Storage::disk('copias')->exists($copia)) {
            touch(Storage::disk('copias')->path($copia), now()->subHour()->getTimestamp());
        }

        $codigo = \Illuminate\Support\Facades\Artisan::call('pruebas:purgar');
        $salidaDelComando = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame($salida, $codigo, $salidaDelComando);

        return $salidaDelComando;
    }

    // --------------------------------------------------------------- avisos

    public function test_el_primer_aviso_sale_a_los_60_dias_y_fija_la_fecha_del_borrado(): void
    {
        $tienda = $this->pruebaCerrada('aviso', 59);

        $this->correrEl('2026-11-05 05:00:00');
        Notification::assertNothingSent();

        $this->correrEl('2026-11-06 05:00:00');

        $tienda->refresh();
        // 90 días desde el cierre (2026-09-07 05:00): el 06/12/2026.
        $this->assertSame('2026-12-06', $tienda->purge_scheduled_at->toDateString());
        $this->assertNull($tienda->purge_reminded_at);

        $admin = User::withoutTenant()->where('email', 'aviso@purga.test')->first();
        Notification::assertSentTo($admin, AbandonedTrialNotification::class, function (AbandonedTrialNotification $n) {
            $correo = $n->toMail((object) ['name' => 'Dueña']);

            return ! $n->ultimo
                && $n->fechaDeBorrado === '06/12/2026'
                && $correo->subject === 'Los datos de Tienda aviso se borrarán el 06/12/2026';
        });

        $linea = ActivityLog::query()->where('action', ActivityLog::TIENDA_AVISO_DE_PURGA)->firstOrFail();
        $this->assertStringContainsString('Primer aviso a Tienda aviso', $linea->description);
        $this->assertStringContainsString('a su administrador', $linea->description);

        // Al día siguiente no repite.
        Notification::fake();
        $this->correrEl('2026-11-07 05:00:00');
        Notification::assertNothingSent();
    }

    public function test_el_ultimo_aviso_sale_una_semana_antes_con_la_misma_fecha_y_despues_se_borra(): void
    {
        $tienda = $this->pruebaCerrada('ciclo', 60);
        $this->conDatos($tienda);

        $this->correrEl('2026-11-05 05:00:00');
        $fecha = $tienda->fresh()->purge_scheduled_at;
        $this->assertSame('2026-12-05', $fecha->toDateString());

        // Seis días antes, todavía no; a los siete, el último aviso.
        Notification::fake();
        $this->correrEl('2026-11-27 05:00:00');
        Notification::assertNothingSent();

        $this->correrEl('2026-11-28 05:00:00');
        Notification::assertSentTo(User::withoutTenant()->where('email', 'ciclo@purga.test')->first(), AbandonedTrialNotification::class, fn ($n) => $n->ultimo && $n->fechaDeBorrado === '05/12/2026');
        $this->assertSame('2026-12-05', $tienda->fresh()->purge_scheduled_at->toDateString());

        // Un día antes de la fecha no se borra nada.
        $this->correrEl('2026-12-04 05:00:00');
        $this->assertSame(1, DB::table('products')->where('tenant_id', $tienda->id)->count());

        $this->correrEl('2026-12-05 05:00:00');
        $this->assertSame(0, DB::table('products')->where('tenant_id', $tienda->id)->count());
        $this->assertNotNull($tienda->fresh()->data_purged_at);
    }

    /**
     * Si el cron estuvo parado, los avisos salen tarde pero nunca se borra antes
     * de lo prometido: el primero da 30 días, y entre el último y el borrado
     * siempre hay una semana.
     */
    public function test_con_el_cron_parado_los_avisos_salen_tarde_y_la_fecha_se_corre(): void
    {
        $tienda = $this->pruebaCerrada('tarde', 120);
        $this->conDatos($tienda);

        $this->correrEl('2026-11-05 05:00:00');
        $this->assertSame('2026-12-05', $tienda->fresh()->purge_scheduled_at->toDateString());
        $this->assertSame(1, DB::table('products')->where('tenant_id', $tienda->id)->count());

        // El cron no corre hasta después de la fecha: el último aviso la corre una semana.
        $this->correrEl('2026-12-20 05:00:00');
        $this->assertSame('2026-12-27', $tienda->fresh()->purge_scheduled_at->toDateString());
        $this->assertSame(1, DB::table('products')->where('tenant_id', $tienda->id)->count());

        $this->correrEl('2026-12-27 05:00:00');
        $this->assertSame(0, DB::table('products')->where('tenant_id', $tienda->id)->count());
    }

    // -------------------------------------------------------------- borrado

    public function test_borra_catalogo_fotos_pedidos_y_clientes_y_conserva_la_tienda_y_las_cuentas(): void
    {
        $tienda = $this->pruebaCerrada('borrar', 100);
        $datos = $this->conDatos($tienda);
        $tienda->forceFill(['purge_scheduled_at' => now()->subDay(), 'purge_reminded_at' => now()->subDays(8)])->save();

        $this->correrEl('2026-11-05 05:00:00');

        foreach (['products', 'product_variants', 'reviews', 'stock_notifications', 'orders'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->where('tenant_id', $tienda->id)->count(), $tabla);
        }
        $this->assertFalse(DB::table('customers')->where('name', 'Ana invitada')->exists());
        $this->assertSame(0, DB::table('product_images')->count());
        $this->assertSame(0, DB::table('order_items')->count());
        $this->assertSame(0, DB::table('user_favorites')->count());

        foreach ($datos['fotos'] as $foto) {
            Storage::disk('public')->assertMissing($foto);
        }
        Storage::disk(Comprobantes::DISCO)->assertMissing($datos['comprobante']);

        // La tienda, su admin y la cuenta del comprador siguen; la cuenta vuelve
        // al directorio, sin pedidos.
        $this->assertNotNull(Tenant::find($tienda->id));
        $this->assertSame(2, User::withoutTenant()->where('tenant_id', $tienda->id)->count());
        $this->assertSame([$datos['comprador']->id], DB::table('customers')->where('tenant_id', $tienda->id)->pluck('user_id')->all());

        $linea = ActivityLog::query()->where('action', ActivityLog::TIENDA_DATOS_BORRADOS)->firstOrFail();
        // Dos fichas en el directorio: la invitada y la del comprador con cuenta.
        $this->assertStringContainsString('Productos: 1. Pedidos: 1. Clientes del directorio: 2.', $linea->description);
        $this->assertSame(1, $linea->context['filas']['product_images']);
    }

    /** La regla de TEC-16: un borrado de una tienda no toca nada de otra. */
    public function test_no_toca_los_datos_ni_las_fotos_de_otra_tienda(): void
    {
        $abandonada = $this->pruebaCerrada('abandonada', 100);
        $this->conDatos($abandonada);
        $abandonada->forceFill(['purge_scheduled_at' => now()->subDay(), 'purge_reminded_at' => now()->subDays(8)])->save();

        $activa = Tenant::create(['slug' => 'activa', 'name' => 'Activa', 'whatsapp_number' => '51999999999', 'is_active' => true]);
        $suyos = $this->conDatos($activa);

        $this->correrEl('2026-11-05 05:00:00');

        $this->assertSame(0, DB::table('products')->where('tenant_id', $abandonada->id)->count());
        foreach (['products', 'product_variants', 'reviews', 'stock_notifications', 'orders', 'customers'] as $tabla) {
            $this->assertGreaterThan(0, DB::table($tabla)->where('tenant_id', $activa->id)->count(), $tabla);
        }
        $this->assertSame(1, DB::table('product_images')->count());
        $this->assertSame(1, DB::table('order_items')->count());
        $this->assertSame(1, DB::table('user_favorites')->count());
        foreach ($suyos['fotos'] as $foto) {
            Storage::disk('public')->assertExists($foto);
        }
        Storage::disk(Comprobantes::DISCO)->assertExists($suyos['comprobante']);
    }

    /**
     * La red de TEC-16: la cuenta de cada tabla entera, sin `where`. Aquí una
     * reseña de OTRA tienda cuelga de un producto de la abandonada (un dato
     * incoherente, pero la base lo admite): al borrar el producto, la cascada se
     * la llevaría. La tabla baja más de lo que la tienda tenía, y entonces no se
     * borra nada —tampoco las fotos, que van después—.
     */
    public function test_si_el_borrado_se_lleva_filas_de_otra_tienda_no_borra_nada(): void
    {
        $abandonada = $this->pruebaCerrada('cascada', 100);
        $datos = $this->conDatos($abandonada);
        $abandonada->forceFill(['purge_scheduled_at' => now()->subDay(), 'purge_reminded_at' => now()->subDays(8)])->save();

        $otra = Tenant::create(['slug' => 'otra-tienda', 'name' => 'Otra', 'whatsapp_number' => '51999999999', 'is_active' => true]);
        DB::table('reviews')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $otra->id, 'customer_name' => 'Ajena', 'rating' => 4,
            'product_id' => DB::table('products')->where('tenant_id', $abandonada->id)->value('id'),
        ]);

        $salida = $this->correrEl('2026-11-05 05:00:00', salida: 1);

        $this->assertStringContainsString('reviews: la tienda tenía 1 filas y la tabla bajó 2. No se borró nada.', $salida);
        $this->assertSame(1, DB::table('products')->where('tenant_id', $abandonada->id)->count());
        $this->assertSame(1, DB::table('reviews')->where('tenant_id', $otra->id)->count());
        $this->assertSame(1, DB::table('orders')->where('tenant_id', $abandonada->id)->count());
        $this->assertNull($abandonada->fresh()->data_purged_at);
        foreach ($datos['fotos'] as $foto) {
            Storage::disk('public')->assertExists($foto);
        }
        $this->assertFalse(ActivityLog::query()->where('action', ActivityLog::TIENDA_DATOS_BORRADOS)->exists());
    }

    public function test_sin_una_copia_reciente_avisa_pero_no_borra_y_sale_con_error(): void
    {
        Storage::fake('copias');

        $porBorrar = $this->pruebaCerrada('sin-copia', 100);
        $this->conDatos($porBorrar);
        $porBorrar->forceFill(['purge_scheduled_at' => now()->subDay(), 'purge_reminded_at' => now()->subDays(8)])->save();
        $porAvisar = $this->pruebaCerrada('por-avisar', 61);

        $this->correrEl('2026-11-05 05:00:00', salida: 1);

        $this->assertSame(1, DB::table('products')->where('tenant_id', $porBorrar->id)->count());
        $this->assertNull($porBorrar->fresh()->data_purged_at);
        // Los avisos no dependen de la copia: no borran nada.
        $this->assertNotNull($porAvisar->fresh()->purge_scheduled_at);
    }

    // --------------------------------------------------------- a quién nunca

    public function test_nunca_toca_a_quien_pago_alguna_vez_ni_a_una_tienda_abierta_o_sin_prueba(): void
    {
        $pago = $this->pruebaCerrada('pago', 200, pagoAlgunaVez: true);
        $this->conDatos($pago);

        $abierta = $this->pruebaCerrada('abierta', 200);
        $abierta->update(['is_active' => true]);

        $suspendida = Tenant::create(['slug' => 'suspendida', 'name' => 'Suspendida', 'whatsapp_number' => '51999999999', 'is_active' => false]);

        $this->correrEl('2026-11-05 05:00:00');

        Notification::assertNothingSent();
        $this->assertSame(1, DB::table('products')->where('tenant_id', $pago->id)->count());
        $this->assertNull($pago->fresh()->purge_scheduled_at);
        $this->assertNull($abierta->fresh()->purge_scheduled_at);
        $this->assertNull($suspendida->fresh()->purge_scheduled_at);
        $this->assertNull(PruebasAbandonadas::estado($pago->fresh()));
    }

    /** Reabrir empieza la cuenta de cero: un cierre nuevo vuelve a avisar dos veces. */
    public function test_al_reabrirse_se_olvidan_los_avisos(): void
    {
        $tienda = $this->pruebaCerrada('reabre', 80);
        $tienda->forceFill(['purge_scheduled_at' => now()->addDays(10), 'purge_reminded_at' => now()])->save();

        $tienda->forceFill(['is_active' => true, 'trial_ends_at' => now()->addDays(7)])->save();

        $tienda->refresh();
        $this->assertNull($tienda->purge_scheduled_at);
        $this->assertNull($tienda->purge_reminded_at);
    }

    public function test_simular_no_avisa_ni_borra(): void
    {
        $porBorrar = $this->pruebaCerrada('simular', 100);
        $this->conDatos($porBorrar);
        $porBorrar->forceFill(['purge_scheduled_at' => now()->subDay(), 'purge_reminded_at' => now()->subDays(8)])->save();
        $porAvisar = $this->pruebaCerrada('simular-aviso', 61);

        $this->artisan('pruebas:purgar', ['--simular' => true])
            ->expectsOutputToContain('simular: borraría order_items 1, orders 1, customers 2')
            ->expectsOutputToContain('simular-aviso: primer aviso, con el borrado el')
            ->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertSame(1, DB::table('products')->where('tenant_id', $porBorrar->id)->count());
        $this->assertNull($porAvisar->fresh()->purge_scheduled_at);
    }

    public function test_tienda_limita_el_comando_a_una(): void
    {
        $una = $this->pruebaCerrada('una', 61);
        $otra = $this->pruebaCerrada('otra', 61);

        $this->artisan('pruebas:purgar', ['--tienda' => 'una'])->assertExitCode(0);

        $this->assertNotNull($una->fresh()->purge_scheduled_at);
        $this->assertNull($otra->fresh()->purge_scheduled_at);
    }

    // ------------------------------------------------------- lo que se ve

    /** La pantalla de tienda cerrada recibe la fecha, y después que se borró. */
    public function test_el_cierre_dice_desde_cuando_se_borran_los_datos_y_cuando_se_borraron(): void
    {
        $tienda = $this->pruebaCerrada('pantalla', 10);

        // Antes del primer aviso, la estimación: 90 días desde el cierre.
        $cierre = $tienda->cierre();
        $this->assertSame('prueba', $cierre['motivo']);
        $this->assertSame(now()->subDays(10)->addDays(90)->toIso8601String(), $cierre['borrado_desde']);
        $this->assertNull($cierre['datos_borrados']);

        $tienda->forceFill(['data_purged_at' => now()])->save();
        $cierre = $tienda->fresh()->cierre();
        $this->assertNull($cierre['borrado_desde']);
        $this->assertSame(now()->toIso8601String(), $cierre['datos_borrados']);

        // Y viaja en `me` del panel, que es de donde la lee la pantalla.
        $admin = User::withoutTenant()->where('email', 'pantalla@purga.test')->first();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken,
            'X-Tenant' => $tienda->slug,
            'Accept' => 'application/json',
        ])->getJson('/api/auth/me')->assertOk()->assertJsonPath('cierre.datos_borrados', now()->toIso8601String());
    }

    /** Las páginas legales prometen el plazo: si cambia la constante, cambian ellas. */
    public function test_las_paginas_legales_prometen_los_mismos_dias(): void
    {
        $dias = PruebasAbandonadas::DIAS_HASTA_BORRAR;

        foreach (['TerminosPage.tsx', 'PrivacidadPage.tsx'] as $pagina) {
            $texto = (string) file_get_contents(base_path("../saas-hardware-frontend/src/pages/legal/{$pagina}"));
            $this->assertStringContainsString("{$dias} días", strip_tags($texto), "{$pagina} tiene que decir que los datos de una prueba sin pagar se guardan {$dias} días.");
        }
    }

    public function test_el_correo_del_ultimo_aviso_lo_dice(): void
    {
        $correo = (new AbandonedTrialNotification('Mi *tienda*', '07/09/2026', '06/12/2026', true))->toMail((object) ['name' => 'Ana']);

        $this->assertSame('Último aviso: el 06/12/2026 se borran los datos de Mi *tienda*', $correo->subject);
        $texto = implode("\n", $correo->introLines);
        $this->assertStringContainsString('Es el último aviso', $texto);
        // SEC-8: el nombre lo escribe el dueño; en el cuerpo va escapado.
        $this->assertStringContainsString('Mi \\*tienda\\*', $texto);
        $this->assertSame('Elegir un plan', $correo->actionText);
    }
}
