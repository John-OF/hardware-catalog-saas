<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\TeamRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlantillasWhatsapp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Los mensajes de WhatsApp que cada tienda reescribe (MOD-25).
 *
 * Lo que fija, por orden de lo que costaría romperlo:
 *
 * 1. **El catálogo público solo lleva los del comprador.** Los que la tienda
 *    manda a sus clientes (el recordatorio de pago, el de pedido cancelado) no
 *    salen del panel, ni resueltos ni en crudo.
 * 2. **Se guarda solo lo que cambió.** Un texto igual al de fábrica, vacío o
 *    `null` no se guarda: así una mejora del de fábrica llega a quien no lo tocó.
 * 3. **Una variable que ese mensaje no admite se rechaza al guardar**, con un
 *    mensaje que dice cuáles sí; si no, el cliente recibiría "Hola {clinete}".
 * 4. La config es coherente: cada texto de fábrica usa solo sus variables.
 */
class PlantillasWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tienda;
    private User $admin;
    private string $guardOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->guardOriginal = (string) config('auth.defaults.guard');

        $this->tienda = $this->crearTienda('tienda-mensajes');
        $this->admin = $this->crearUsuario($this->tienda, 'duenia@mensajes.test', 'admin');
    }

    // ------------------------------------------------------------- la config

    public function test_la_config_es_coherente(): void
    {
        $variables = config('plantillas_whatsapp.variables');

        // MOD-26 añadió «Pago confirmado» y «Pago no confirmado».
        $this->assertCount(12, PlantillasWhatsapp::catalogo());

        foreach (PlantillasWhatsapp::catalogo() as $clave => $plantilla) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $clave, 'Las claves van sin tildes ni mayúsculas: se guardan en la base.');
            $this->assertContains($plantilla['grupo'], [PlantillasWhatsapp::GRUPO_COMPRADOR, PlantillasWhatsapp::GRUPO_TIENDA], $clave);
            $this->assertNotEmpty($plantilla['etiqueta'], $clave);
            $this->assertNotEmpty($plantilla['cuando'], $clave);

            foreach ($plantilla['variables'] as $variable) {
                $this->assertArrayHasKey($variable, $variables, "La variable {$variable} de {$clave} no tiene descripción.");
            }

            $this->assertSame([], PlantillasWhatsapp::variablesQueNoAdmite($clave, $plantilla['texto']), "El texto de fábrica de {$clave} usa una variable que no admite.");
            $this->assertLessThanOrEqual(PlantillasWhatsapp::maximo(), mb_strlen($plantilla['texto']), $clave);
            $this->assertSame(PlantillasWhatsapp::limpiar($plantilla['texto']), $plantilla['texto'], "El texto de fábrica de {$clave} no puede empezar ni terminar en blanco: se compararía distinto al guardarlo.");
        }
    }

    /**
     * El frontend copia solo las claves (`utils/plantillasWhatsapp.ts`): con una
     * mal escrita, el botón abriría WhatsApp sin texto y nadie lo notaría.
     */
    public function test_las_claves_del_frontend_son_las_mismas(): void
    {
        $archivo = base_path('../saas-hardware-frontend/src/utils/plantillasWhatsapp.ts');

        if (! is_file($archivo)) {
            $this->markTestSkipped('El frontend no está al lado (CI de la API sola).');
        }

        $contenido = (string) file_get_contents($archivo);
        $this->assertSame(1, preg_match('/export const PLANTILLAS = \[(.*?)\] as const;/s', $contenido, $bloque));
        preg_match_all("/'([a-z_]+)'/", $bloque[1], $claves);

        $this->assertSame(PlantillasWhatsapp::claves(), $claves[1], 'config/plantillas_whatsapp.php y utils/plantillasWhatsapp.ts tienen claves distintas (o en otro orden).');
    }

    // ------------------------------------------------------------- el panel

    public function test_una_tienda_nueva_tiene_las_diez_de_fabrica(): void
    {
        $respuesta = $this->como($this->admin)->getJson('/api/whatsapp-templates')->assertOk();

        $this->assertSame(PlantillasWhatsapp::maximo(), $respuesta->json('max'));
        $this->assertSame(config('plantillas_whatsapp.variables'), $respuesta->json('variables'));
        $this->assertSame(PlantillasWhatsapp::claves(), array_column($respuesta->json('plantillas'), 'clave'));

        foreach ($respuesta->json('plantillas') as $plantilla) {
            $this->assertFalse($plantilla['personalizada'], $plantilla['clave']);
            $this->assertSame($plantilla['de_fabrica'], $plantilla['texto'], $plantilla['clave']);
        }
    }

    public function test_guarda_solo_lo_que_cambio_y_lo_devuelve_resuelto(): void
    {
        $texto = "¡Hola {cliente}! Tu pedido {pedido} ya está listo.\n\n{productos}";

        $respuesta = $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['pedido_listo' => $texto],
        ])->assertOk();

        $listo = collect($respuesta->json('plantillas'))->firstWhere('clave', 'pedido_listo');
        $this->assertSame($texto, $listo['texto']);
        $this->assertTrue($listo['personalizada']);
        $this->assertSame(config('plantillas_whatsapp.plantillas.pedido_listo.texto'), $listo['de_fabrica']);

        // En la base, esa y nada más: las otras nueve siguen siendo de fábrica.
        $this->assertSame(['pedido_listo' => $texto], $this->guardadas());
    }

    public function test_los_saltos_de_windows_y_los_espacios_de_los_bordes_no_cuentan(): void
    {
        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['contacto_pedido' => "  Hola {cliente},\r\nsobre tu pedido {pedido}.  \r\n"],
        ])->assertOk();

        $this->assertSame(['contacto_pedido' => "Hola {cliente},\nsobre tu pedido {pedido}."], $this->guardadas());
    }

    public function test_el_texto_de_fabrica_vacio_o_null_no_se_guarda(): void
    {
        $this->tienda->forceFill(['whatsapp_templates' => [
            'pedido_listo' => 'Listo, {cliente}.',
            'pedido_cancelado' => 'Cancelado, {cliente}.',
            'contacto_pedido' => 'Hola, {cliente}.',
        ]])->save();

        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => [
                // Idéntico al de fábrica, aunque con un salto de más al final.
                'pedido_listo' => config('plantillas_whatsapp.plantillas.pedido_listo.texto')."\r\n",
                'pedido_cancelado' => '',
                'contacto_pedido' => null,
            ],
        ])->assertOk();

        // Quedó todo de fábrica: la columna vuelve a null, como en una tienda nueva.
        $this->assertNull($this->tienda->fresh()->whatsapp_templates);
    }

    public function test_lo_que_no_llega_no_se_toca(): void
    {
        $this->tienda->forceFill(['whatsapp_templates' => ['pedido_listo' => 'Listo, {cliente}.']])->save();

        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['pedido_cancelado' => 'Cancelado, {cliente}.'],
        ])->assertOk();

        $this->assertSame(
            ['pedido_listo' => 'Listo, {cliente}.', 'pedido_cancelado' => 'Cancelado, {cliente}.'],
            $this->guardadas(),
        );
    }

    public function test_una_variable_que_ese_mensaje_no_admite_se_rechaza_y_dice_cuales_si(): void
    {
        $respuesta = $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            // {clinete} mal escrito, y {piezas}, que es del armador.
            'plantillas' => ['contacto_pedido' => 'Hola {clinete}, tu pedido {pedido} con {piezas}.'],
        ])->assertStatus(422);

        $mensaje = $respuesta->json('errors')['plantillas.contacto_pedido'][0];
        $this->assertStringContainsString('«Contacto sobre un pedido» usa {clinete}, {piezas}', $mensaje);
        $this->assertStringContainsString('{cliente}, {tienda}, {pedido}, {productos}, {total}, {entrega}, {pagos} y {seguimiento}', $mensaje);

        $this->assertNull($this->tienda->fresh()->whatsapp_templates);
    }

    public function test_un_mensaje_que_no_existe_se_rechaza(): void
    {
        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['saludo_de_navidad' => 'Feliz Navidad, {cliente}.'],
        ])->assertStatus(422)->assertJsonValidationErrors('plantillas');

        $this->assertNull($this->tienda->fresh()->whatsapp_templates);
    }

    public function test_un_texto_demasiado_largo_se_rechaza_con_el_nombre_del_mensaje(): void
    {
        $respuesta = $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['pedido_listo' => str_repeat('a', PlantillasWhatsapp::maximo() + 1)],
        ])->assertStatus(422);

        $this->assertStringContainsString('mensaje «Pedido listo»', $respuesta->json('errors')['plantillas.pedido_listo'][0]);
    }

    public function test_cambiarlos_pide_configurar_la_tienda_y_leerlos_no(): void
    {
        $rol = new TeamRole(['name' => 'Ventas', 'permissions' => ['pedidos.ver']]);
        $rol->tenant_id = $this->tienda->id;
        $rol->save();
        $vendedor = $this->crearUsuario($this->tienda, 'ventas@mensajes.test', 'staff', $rol);

        $this->como($vendedor)->getJson('/api/whatsapp-templates')->assertOk();
        $this->como($vendedor)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['pedido_listo' => 'Listo, {cliente}.'],
        ])->assertForbidden();

        $this->assertNull($this->tienda->fresh()->whatsapp_templates);
    }

    public function test_los_de_una_tienda_no_cambian_los_de_otra(): void
    {
        $otra = $this->crearTienda('otra-tienda-mensajes');
        $suAdmin = $this->crearUsuario($otra, 'duenio@otra.test', 'admin');

        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['pedido_listo' => 'Listo, {cliente}.'],
        ])->assertOk();

        $listo = collect($this->como($suAdmin, $otra)->getJson('/api/whatsapp-templates')->json('plantillas'))
            ->firstWhere('clave', 'pedido_listo');

        $this->assertFalse($listo['personalizada']);
        $this->assertNull($otra->fresh()->whatsapp_templates);
    }

    public function test_guardar_deja_una_linea_que_dice_cuales_cambiaron(): void
    {
        $this->tienda->forceFill(['whatsapp_templates' => ['pedido_cancelado' => 'Cancelado, {cliente}.']])->save();

        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => [
                'pedido_listo' => 'Listo, {cliente}.',
                'pedido_cancelado' => null,
                // Sin cambio: no se nombra.
                'contacto_pedido' => config('plantillas_whatsapp.plantillas.contacto_pedido.texto'),
            ],
        ])->assertOk();

        $linea = ActivityLog::latest('created_at')->latest('id')->firstOrFail();
        $this->assertSame(ActivityLog::CONFIGURACION_EDITADA, $linea->action);
        $this->assertSame('Mensajes de WhatsApp: cambió «Pedido listo»; volvió al texto de fábrica en «Pedido cancelado».', $linea->description);
    }

    public function test_guardar_sin_cambiar_nada_no_anota(): void
    {
        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['pedido_listo' => config('plantillas_whatsapp.plantillas.pedido_listo.texto')],
        ])->assertOk();

        $this->assertSame(0, ActivityLog::count());
    }

    // ------------------------------------------------------------ el catálogo

    public function test_el_catalogo_trae_solo_los_del_comprador_ya_resueltos(): void
    {
        $this->tienda->forceFill(['whatsapp_templates' => [
            'pedido_nuevo' => 'Hola {tienda}, quiero: {productos}',
            // Sin tildes a propósito: la respuesta escapa los caracteres no
            // ASCII y el `assertStringNotContainsString` de abajo pasaría solo.
            'recordatorio_pago' => 'Recuerda pagar, {cliente}.',
        ]])->save();

        foreach ([
            $this->getJson("/api/public/{$this->tienda->slug}"),
            $this->getJson('/api/public/resolve-domain?domain=mensajes.example'),
        ] as $respuesta) {
            $respuesta->assertOk();

            $plantillas = $respuesta->json('plantillas_whatsapp');
            $this->assertSame(['pedido_nuevo', 'consulta_producto', 'armado_pc'], array_keys($plantillas));
            $this->assertSame('Hola {tienda}, quiero: {productos}', $plantillas['pedido_nuevo']);
            $this->assertSame(config('plantillas_whatsapp.plantillas.consulta_producto.texto'), $plantillas['consulta_producto']);

            // Ni el crudo, que lleva los mensajes de la tienda a sus clientes.
            $respuesta->assertJsonMissingPath('whatsapp_templates');
            $this->assertStringNotContainsString('Recuerda pagar', $respuesta->getContent());
            $this->assertStringNotContainsString('recordatorio_pago', $respuesta->getContent());
        }
    }

    public function test_el_panel_tampoco_recibe_el_crudo_con_la_tienda(): void
    {
        $this->tienda->forceFill(['whatsapp_templates' => ['pedido_listo' => 'Listo, {cliente}.']])->save();

        $this->como($this->admin)->getJson('/api/tenant')->assertOk()->assertJsonMissingPath('whatsapp_templates');
    }

    public function test_guardar_borra_la_cache_del_catalogo(): void
    {
        // Primera visita: queda en caché con el de fábrica.
        $this->getJson("/api/public/{$this->tienda->slug}")->assertOk();

        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['consulta_producto' => '¿Tienes {producto}?'],
        ])->assertOk();

        $this->desdeCero();
        $this->getJson("/api/public/{$this->tienda->slug}")
            ->assertOk()
            ->assertJsonPath('plantillas_whatsapp.consulta_producto', '¿Tienes {producto}?');
    }

    public function test_lo_cacheado_antes_de_mod_25_sale_con_las_de_fabrica(): void
    {
        // Lo que había en caché antes de existir la columna: sin la clave.
        $this->getJson("/api/public/{$this->tienda->slug}")->assertOk();
        $viejo = Cache::get("tenant:{$this->tienda->slug}");
        unset($viejo['plantillas_whatsapp']);
        Cache::put("tenant:{$this->tienda->slug}", $viejo, 300);

        $this->getJson("/api/public/{$this->tienda->slug}")
            ->assertOk()
            ->assertJsonPath('plantillas_whatsapp', PlantillasWhatsapp::deFabrica(PlantillasWhatsapp::GRUPO_COMPRADOR));
    }

    public function test_una_clave_guardada_que_ya_no_existe_se_ignora(): void
    {
        DB::table('tenants')->where('id', $this->tienda->id)->update([
            'whatsapp_templates' => json_encode(['mensaje_retirado' => 'Ya no existo.', 'pedido_listo' => 'Listo, {cliente}.']),
        ]);

        $plantillas = $this->como($this->admin)->getJson('/api/whatsapp-templates')->json('plantillas');
        $this->assertSame(PlantillasWhatsapp::claves(), array_column($plantillas, 'clave'));

        // Y al guardar cualquier cosa, sale de la columna.
        $this->como($this->admin)->putJson('/api/whatsapp-templates', [
            'plantillas' => ['pedido_cancelado' => 'Cancelado, {cliente}.'],
        ])->assertOk();

        $this->assertSame(['pedido_listo' => 'Listo, {cliente}.', 'pedido_cancelado' => 'Cancelado, {cliente}.'], $this->guardadas());
    }

    // ---------------------------------------------------------------- ayudas

    /** Lo que de verdad quedó en la columna, sin pasar por el modelo. */
    private function guardadas(): ?array
    {
        $crudo = DB::table('tenants')->where('id', $this->tienda->id)->value('whatsapp_templates');

        return $crudo === null ? null : json_decode($crudo, true);
    }

    private function como(User $usuario, ?Tenant $tienda = null): self
    {
        $this->desdeCero();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant'      => ($tienda ?? $this->tienda)->slug,
        ]);
    }

    /** Ver `TeamUsersTest::desdeCero()`: el guard recuerda al usuario de la petición anterior. */
    private function desdeCero(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse($this->guardOriginal);
    }

    private function crearTienda(string $slug): Tenant
    {
        $tienda = Tenant::create([
            'slug' => $slug, 'name' => 'Tienda '.$slug, 'whatsapp_number' => '51999999999',
            'is_active' => true, 'is_published' => true,
        ]);

        // Para `resolve-domain`, que solo resuelve dominios verificados (FUN-6).
        if ($slug === 'tienda-mensajes') {
            $tienda->forceFill(['custom_domain' => 'mensajes.example', 'custom_domain_verified_at' => now()])->save();
        }

        return $tienda;
    }

    private function crearUsuario(Tenant $tienda, string $email, string $rol, ?TeamRole $rolDelEquipo = null): User
    {
        $usuario = new User(['name' => 'Persona', 'email' => $email, 'password' => 'secret1234', 'role' => $rol, 'is_active' => true]);
        $usuario->tenant_id = $tienda->id;
        $usuario->save();

        if ($rolDelEquipo) {
            DB::table('users')->where('id', $usuario->id)->update(['team_role_id' => $rolDelEquipo->id]);
        }

        return $usuario->fresh();
    }
}
