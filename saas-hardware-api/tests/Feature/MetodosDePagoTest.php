<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\MetodosDePago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Los métodos de pago que arma cada tienda (MOD-23).
 *
 * Hasta ahora eran cuatro fijos, y dos (Yape, Plin) solo existen en Perú. Lo
 * que fija este fichero es lo que puede salir mal al pasar a una lista libre:
 * que lo que ya tenía guardado una tienda se pierda o se lea mal, que el QR de
 * un método se quede huérfano o se borre el de otro, y que una lista sin
 * límite se cuele.
 */
class MetodosDePagoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');

        $this->tienda = Tenant::create([
            'slug' => 'tienda-pagos', 'name' => 'Tienda Pagos',
            'whatsapp_number' => '593991234567', 'is_active' => true, 'is_published' => true,
        ]);

        $this->admin = new User([
            'name' => 'Dueña', 'email' => 'duenia@pagos.test', 'password' => 'secret1234',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tienda->id;
        $this->admin->save();
    }

    private function comoAdmin(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('test', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tienda->slug,
            'Accept' => 'application/json',
        ]);
    }

    /** Como lo manda Configuración: un FormData, con los booleanos como '1'/'0'. */
    private function guardar(array $metodos, array $qrs = []): \Illuminate\Testing\TestResponse
    {
        $datos = ['_method' => 'PUT', 'payment_methods' => $metodos === [] ? '' : $metodos];

        foreach ($qrs as $posicion => $archivo) {
            $datos['payment_method_qr'][$posicion] = $archivo;
        }

        return $this->comoAdmin()->post('/api/tenant', $datos);
    }

    private function qr(): UploadedFile
    {
        return UploadedFile::fake()->image('qr.png', 300, 300);
    }

    /** Lo que hay en la columna, sin pasar por el cast. */
    private function crudo(): mixed
    {
        return json_decode(DB::table('tenants')->where('id', $this->tienda->id)->value('payment_methods'), true);
    }

    private const ANTIGUO = [
        'yape' => ['enabled' => true, 'phone' => '987654321', 'holder_name' => 'Ana Dueña'],
        'plin' => ['enabled' => false, 'phone' => '999888777', 'holder_name' => null],
        'transferencia' => [
            'enabled' => true, 'bank' => 'BCP', 'account_type' => 'ahorros',
            'account_number' => '191-1234567-0-12', 'cci' => '00219100123456701254', 'holder_name' => 'Ana Dueña',
        ],
        'efectivo' => ['enabled' => true],
    ];

    // --- Lo de antes ----------------------------------------------------------

    public function test_lo_guardado_con_los_cuatro_fijos_se_lee_como_lista_sin_perder_nada(): void
    {
        DB::table('tenants')->where('id', $this->tienda->id)->update(['payment_methods' => json_encode(self::ANTIGUO)]);

        $lista = $this->tienda->fresh()->payment_methods;

        $this->assertSame(['Yape', 'Plin', 'Transferencia bancaria', 'Efectivo contra entrega'], array_column($lista, 'name'));
        $this->assertSame('987654321 · Ana Dueña', $lista[0]['details']);
        // El apagado se conserva apagado, con sus datos.
        $this->assertFalse($lista[1]['enabled']);
        $this->assertSame('999888777', $lista[1]['details']);
        $this->assertSame('BCP · Cuenta de ahorros 191-1234567-0-12 · CCI 00219100123456701254 · Ana Dueña', $lista[2]['details']);
        $this->assertNull($lista[3]['details']);
    }

    public function test_la_migracion_reescribe_lo_de_antes_y_deja_lo_nuevo_como_esta(): void
    {
        $otra = Tenant::create(['slug' => 'otra', 'name' => 'Otra', 'whatsapp_number' => '51999999999', 'is_active' => true]);
        $nueva = [['id' => 'abc123', 'name' => 'Nequi', 'details' => '3001234567', 'instructions' => null, 'qr_url' => null, 'enabled' => true]];

        DB::table('tenants')->where('id', $this->tienda->id)->update(['payment_methods' => json_encode(self::ANTIGUO)]);
        DB::table('tenants')->where('id', $otra->id)->update(['payment_methods' => json_encode($nueva)]);

        (require database_path('migrations/2026_11_05_110000_convertir_metodos_de_pago_en_lista.php'))->up();

        $this->assertTrue(array_is_list($this->crudo()));
        $this->assertSame('yape', $this->crudo()[0]['id']);
        $this->assertSame($nueva, json_decode(DB::table('tenants')->where('id', $otra->id)->value('payment_methods'), true));
    }

    // --- Guardar --------------------------------------------------------------

    public function test_la_lista_manda_lo_que_no_viene_se_quita(): void
    {
        $this->guardar([['name' => 'Yape'], ['name' => 'Plin']])->assertOk();
        $yape = $this->tienda->fresh()->payment_methods[0];

        $this->guardar([['id' => $yape['id'], 'name' => 'Yape', 'details' => '987654321']])->assertOk();

        $lista = $this->tienda->fresh()->payment_methods;
        $this->assertSame(['Yape'], array_column($lista, 'name'));
        // Es el mismo método: conserva su identificador.
        $this->assertSame($yape['id'], $lista[0]['id']);
    }

    public function test_la_lista_vacia_los_quita_todos(): void
    {
        $this->guardar([['name' => 'Yape']])->assertOk();

        $this->guardar([])->assertOk();

        $this->assertSame([], $this->tienda->fresh()->payment_methods);
    }

    public function test_no_admite_mas_de_ocho(): void
    {
        $nueve = array_map(fn ($i) => ['name' => "Método {$i}"], range(1, MetodosDePago::MAXIMO + 1));

        $this->guardar($nueve)->assertStatus(422)->assertJsonValidationErrors('payment_methods');
    }

    // --- El QR ----------------------------------------------------------------

    public function test_el_qr_se_guarda_como_ruta_y_el_catalogo_lo_recibe_como_url(): void
    {
        $this->guardar([['name' => 'Deuna', 'details' => '0991234567']], [0 => $this->qr()])->assertOk();

        $ruta = $this->crudo()[0]['qr_url'];
        $this->assertStringStartsWith('pagos/tienda-pagos/', $ruta);
        $this->assertStringEndsWith('.png', $ruta);
        Storage::disk('public')->assertExists($ruta);

        $url = $this->getJson('/api/public/tienda-pagos')->assertOk()->json('payment_methods.0.qr_url');
        $this->assertSame(Storage::disk('public')->url($ruta), $url);
    }

    public function test_guardar_sin_tocar_el_qr_lo_conserva(): void
    {
        $this->guardar([['name' => 'Deuna']], [0 => $this->qr()])->assertOk();
        $metodo = $this->tienda->fresh()->payment_methods[0];

        $this->guardar([['id' => $metodo['id'], 'name' => 'Deuna Pichincha']])->assertOk();

        $this->assertSame($metodo['qr_url'], $this->tienda->fresh()->payment_methods[0]['qr_url']);
    }

    public function test_cambiar_quitar_el_qr_o_el_metodo_borra_el_archivo_que_sobra(): void
    {
        $this->guardar([['name' => 'Deuna'], ['name' => 'Nequi']], [0 => $this->qr(), 1 => $this->qr()])->assertOk();
        [$deuna, $nequi] = $this->crudo();

        // Cambiar el de Deuna y quitar el método Nequi entero.
        $this->guardar([['id' => $deuna['id'], 'name' => 'Deuna']], [0 => $this->qr()])->assertOk();

        Storage::disk('public')->assertMissing($deuna['qr_url']);
        Storage::disk('public')->assertMissing($nequi['qr_url']);
        $nuevo = $this->crudo()[0]['qr_url'];
        Storage::disk('public')->assertExists($nuevo);

        // Y quitarlo con `remove_qr`.
        $this->guardar([['id' => $deuna['id'], 'name' => 'Deuna', 'remove_qr' => '1']])->assertOk();

        Storage::disk('public')->assertMissing($nuevo);
        $this->assertNull($this->crudo()[0]['qr_url']);
    }

    public function test_un_id_que_no_es_de_la_tienda_no_se_queda_con_ningun_qr(): void
    {
        $this->guardar([['name' => 'Deuna']], [0 => $this->qr()])->assertOk();

        // Un id inventado es un método nuevo, sin QR. Y el que no vino se va.
        $this->guardar([['id' => 'inventado', 'name' => 'Otro']])->assertOk();

        $metodo = $this->tienda->fresh()->payment_methods[0];
        $this->assertNotSame('inventado', $metodo['id']);
        $this->assertNull($metodo['qr_url']);
    }

    public function test_borrar_un_qr_nunca_toca_un_archivo_fuera_de_la_carpeta_de_pagos_de_la_tienda(): void
    {
        Storage::disk('public')->put('products/tienda-pagos/foto.webp', 'x');
        Storage::disk('public')->put('pagos/otra/qr.png', 'x');

        // Lo escrito a mano en la base que apunte fuera de su carpeta.
        DB::table('tenants')->where('id', $this->tienda->id)->update(['payment_methods' => json_encode([
            ['id' => 'a', 'name' => 'A', 'qr_url' => 'products/tienda-pagos/foto.webp', 'enabled' => true],
            ['id' => 'b', 'name' => 'B', 'qr_url' => 'pagos/otra/qr.png', 'enabled' => true],
        ])]);

        $this->guardar([])->assertOk();

        Storage::disk('public')->assertExists('products/tienda-pagos/foto.webp');
        Storage::disk('public')->assertExists('pagos/otra/qr.png');
    }

    public function test_el_qr_tiene_que_ser_una_imagen(): void
    {
        $this->guardar([['name' => 'Deuna']], [0 => UploadedFile::fake()->create('qr.pdf', 10, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method_qr.0');
    }
}
