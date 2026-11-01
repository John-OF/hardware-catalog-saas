<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\TeamRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Permisos por acción del equipo (MOD-21).
 *
 * Lo que se fija aquí, de fuera hacia dentro:
 *
 * 1. **Toda ruta del panel declara su permiso**, o `admin`, o figura en
 *    SIN_PERMISO con su motivo. Una ruta nueva sin decidir hace fallar esto,
 *    igual que `BitacoraDeTiendaTest` con la bitácora.
 * 2. **Cada permiso cierra su ruta y abre solo la suya**: se recorren todas las
 *    rutas con un rol vacío (403) y con un rol que solo tiene ese permiso (pasa).
 * 3. **Falla en cerrado**: sin rol, con un rol de otra tienda o con un permiso
 *    al que le falta lo que requiere, no se puede nada.
 * 4. Los roles en sí (crear, editar, borrar), su efecto en el equipo y los
 *    sitios que preguntan por un permiso dentro de una ruta: el costo, borrar en
 *    lote, la exportación, el aviso de pedido nuevo y la bitácora.
 */
class PermisosPorAccionTest extends TestCase
{
    use RefreshDatabase;

    /** Rutas del panel que no piden permiso, y por qué. */
    private const SIN_PERMISO = [
        'POST api/auth/logout'       => 'es de la propia sesión',
        'GET api/auth/me'            => 'es de la propia sesión: dice qué puede quien pregunta',
        'POST api/auth/email/resend' => 'reenvía el correo de verificación a uno mismo',
        'GET api/tenant'             => 'el panel necesita la moneda, el slug y si la tienda está publicada',
        'GET api/plan'               => 'los avisos de prueba y de tope salen en todo el panel',
        'GET api/whatsapp-templates' => 'Pedidos y Lista de espera arman sus mensajes con ellas; son textos sin datos de nadie (MOD-25)',
        'GET api/categories'         => 'el formulario de producto las necesita, y son las del catálogo público',
        'GET api/categories/{category}' => 'mismo motivo que la lista',
    ];

    private Tenant $tienda;
    private User $admin;
    private string $guardOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->guardOriginal = (string) config('auth.defaults.guard');

        $this->tienda = $this->crearTienda('tienda-permisos');
        // Sin tope de equipo: aquí se invita a más de una persona.
        $this->tienda->forceFill(['plan' => 'enterprise', 'trial_ends_at' => null])->save();
        $this->admin = $this->crearUsuario($this->tienda, 'duenia@permisos.test', 'admin');
    }

    // ------------------------------------------------------------ el catálogo

    public function test_el_catalogo_es_coherente(): void
    {
        $catalogo = Permisos::catalogo();

        $this->assertNotEmpty($catalogo);

        foreach ($catalogo as $clave => $permiso) {
            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$/', $clave, 'Las claves van sin tildes ni mayúsculas: se guardan en la base.');

            foreach ($permiso['requiere'] as $requerido) {
                $this->assertArrayHasKey($requerido, $catalogo, "{$clave} requiere {$requerido}, que no existe.");
                $this->assertNotSame($clave, $requerido);
            }
        }

        foreach (Permisos::delColaborador() as $clave) {
            $this->assertArrayHasKey($clave, $catalogo, "El rol de fábrica trae {$clave}, que no existe.");
        }

        // El de fábrica tiene que estar completo: si le faltara algo que
        // requiere uno de sus permisos, ese permiso no valdría (efectivos()).
        $this->assertSame(Permisos::normalizar(Permisos::delColaborador()), Permisos::efectivos(Permisos::delColaborador()));
    }

    /** El frontend copia solo las claves (`utils/permisos.ts`), para que una errata no esconda un botón. */
    public function test_las_claves_del_frontend_son_las_mismas(): void
    {
        $archivo = base_path('../saas-hardware-frontend/src/utils/permisos.ts');

        if (! is_file($archivo)) {
            $this->markTestSkipped('El frontend no está al lado (CI de la API sola).');
        }

        $contenido = (string) file_get_contents($archivo);
        $this->assertSame(1, preg_match('/export const PERMISOS = \[(.*?)\] as const;/s', $contenido, $bloque));
        preg_match_all("/'([a-z]+\.[a-z]+)'/", $bloque[1], $claves);

        $this->assertSame(Permisos::claves(), $claves[1], 'config/permisos.php y utils/permisos.ts tienen claves distintas (o en otro orden).');
    }

    public function test_normalizar_anade_lo_que_se_requiere_y_ordena(): void
    {
        $this->assertSame(
            ['productos.ver', 'productos.editar', 'productos.importar'],
            Permisos::normalizar(['productos.importar']),
        );

        $this->assertSame(
            ['productos.ver', 'productos.borrar', 'pedidos.ver', 'pedidos.borrar', 'papelera.gestionar'],
            Permisos::normalizar(['papelera.gestionar', 'no.existe', 42]),
        );
    }

    // --------------------------------------------------------------- las rutas

    public function test_toda_ruta_del_panel_declara_su_permiso(): void
    {
        $sinDecidir = [];
        $claves = Permisos::claves();

        foreach ($this->rutasDelPanel() as $firma => $ruta) {
            $permisos = $this->permisosDe($ruta);

            foreach ($permisos as $permiso) {
                $this->assertContains($permiso, $claves, "{$firma} pide «{$permiso}», que no está en config/permisos.php.");
            }

            $esDeAdmin = in_array('admin', $ruta->gatherMiddleware(), true);

            if ($permisos === [] && ! $esDeAdmin && ! array_key_exists($firma, self::SIN_PERMISO)) {
                $sinDecidir[] = $firma;
            }
        }

        $this->assertSame([], $sinDecidir, 'Ruta del panel sin permiso: añádele `permiso:<clave>`, `admin` o ponla en SIN_PERMISO con su motivo.');

        $firmas = array_keys($this->rutasDelPanel());
        $this->assertSame([], array_values(array_diff(array_keys(self::SIN_PERMISO), $firmas)), 'Hay rutas en SIN_PERMISO que ya no existen.');
    }

    /** Ningún permiso abre el equipo: lo que no se delega va con `admin`. */
    public function test_el_equipo_y_sus_roles_son_solo_de_admin(): void
    {
        foreach ($this->rutasDelPanel() as $firma => $ruta) {
            if (str_contains($ruta->uri(), 'api/users') || str_contains($ruta->uri(), 'api/team-roles') || $ruta->uri() === 'api/permissions') {
                $this->assertContains('admin', $ruta->gatherMiddleware(), "{$firma} tiene que ser solo de admin.");
                $this->assertSame([], $this->permisosDe($ruta), "{$firma} no puede abrirse con un permiso.");
            }
        }

        $todo = $this->crearRol('Todo', Permisos::claves());
        $staff = $this->crearUsuario($this->tienda, 'todo@permisos.test', 'staff', $todo);

        $this->como($staff)->getJson('/api/users')->assertForbidden();
        $this->como($staff)->getJson('/api/team-roles')->assertForbidden();
        $this->como($staff)->getJson('/api/permissions')->assertForbidden();
        $this->como($staff)->postJson('/api/team-roles', ['name' => 'Yo', 'permissions' => []])->assertForbidden();
    }

    public function test_sin_el_permiso_cada_ruta_responde_403(): void
    {
        $vacio = $this->crearRol('Sin nada', []);
        $staff = $this->crearUsuario($this->tienda, 'nada@permisos.test', 'staff', $vacio);

        foreach ($this->rutasConPermiso() as $firma => [$metodo, $uri]) {
            $respuesta = $this->como($staff)->json($metodo, $uri);

            $this->assertSame(403, $respuesta->status(), "{$firma} respondió {$respuesta->status()} a un rol sin permisos.");
            $this->assertSame(Permisos::MENSAJE_SIN_PERMISO, $respuesta->json('message'), $firma);
        }
    }

    /**
     * Con SOLO el permiso de la ruta (y lo que requiere), la ruta deja pasar.
     * Lo que responda después -404 por el id inventado, 422 por el cuerpo
     * vacío, 200- da igual: lo que se mira es que no sea la negativa del
     * permiso. Así se ve también que ningún permiso abre una ruta ajena: el
     * recorrido de arriba ya cerró todas con el rol vacío.
     */
    public function test_con_el_permiso_justo_la_ruta_deja_pasar(): void
    {
        $rol = $this->crearRol('Uno a uno', []);
        $staff = $this->crearUsuario($this->tienda, 'uno@permisos.test', 'staff', $rol);

        foreach ($this->rutasConPermiso() as $firma => [$metodo, $uri, $permisos]) {
            DB::table('team_roles')->where('id', $rol->id)->update(['permissions' => json_encode(Permisos::normalizar($permisos))]);

            $respuesta = $this->como($staff)->json($metodo, $uri);

            // Una descarga CSV no trae JSON: si llegó a descargarse, pasó.
            if ($respuesta->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                $this->assertSame(200, $respuesta->baseResponse->getStatusCode(), $firma);

                continue;
            }

            $this->assertNotSame(
                Permisos::MENSAJE_SIN_PERMISO,
                $respuesta->json('message'),
                "{$firma} rechazó a un rol que tiene «".implode(', ', $permisos).'».',
            );
        }
    }

    // --------------------------------------------------------- falla en cerrado

    public function test_el_admin_lo_puede_todo_sin_rol(): void
    {
        $me = $this->como($this->admin)->getJson('/api/auth/me')->assertOk();

        $this->assertSame(Permisos::claves(), $me->json('user.permisos'));
        $this->assertNull($me->json('user.team_role'));
        $this->assertNull($me->json('user.team_role_id'));
    }

    public function test_toda_tienda_nace_con_el_colaborador_de_antes(): void
    {
        $roles = TeamRole::withoutTenant()->where('tenant_id', $this->tienda->id)->get();

        $this->assertCount(1, $roles);
        $this->assertSame('Colaborador', $roles[0]->name);
        $this->assertSame(Permisos::normalizar(Permisos::delColaborador()), $roles[0]->permissions);

        // Un staff creado sin elegir rol recibe ese.
        $staff = $this->crearUsuario($this->tienda, 'sinelegir@permisos.test', 'staff');
        $this->assertSame($roles[0]->id, $staff->team_role_id);

        $me = $this->como($staff)->getJson('/api/auth/me')->assertOk();
        $this->assertSame(['id' => $roles[0]->id, 'name' => 'Colaborador'], $me->json('user.team_role'));
        $this->assertSame($roles[0]->permissions, $me->json('user.permisos'));
    }

    public function test_un_staff_sin_rol_no_puede_nada(): void
    {
        $staff = $this->crearUsuario($this->tienda, 'huerfano@permisos.test', 'staff');
        DB::table('users')->where('id', $staff->id)->update(['team_role_id' => null]);

        $this->como($staff)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.permisos', []);
        $this->como($staff)->getJson('/api/products')->assertForbidden();

        // Y guardarlo -el login lo hace- no le da un rol a escondidas.
        $staff->refresh()->forceFill(['last_login_at' => now()])->save();
        $this->assertNull($staff->fresh()->team_role_id);
    }

    public function test_un_rol_de_otra_tienda_no_da_nada(): void
    {
        $otra = $this->crearTienda('otra-permisos');
        $rolAjeno = $this->crearRol('Todo ajeno', Permisos::claves(), $otra);

        $staff = $this->crearUsuario($this->tienda, 'colado@permisos.test', 'staff');
        DB::table('users')->where('id', $staff->id)->update(['team_role_id' => $rolAjeno->id]);

        $this->como($staff)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.permisos', []);
        $this->como($staff)->getJson('/api/products')->assertForbidden();
    }

    /** Si la config cambia después de guardar un rol, lo que quedó cojo no vale. */
    public function test_un_permiso_sin_lo_que_requiere_no_vale(): void
    {
        $rol = $this->crearRol('Cojo', []);
        DB::table('team_roles')->where('id', $rol->id)->update(['permissions' => json_encode(['productos.editar', 'pedidos.ver'])]);
        $staff = $this->crearUsuario($this->tienda, 'cojo@permisos.test', 'staff', $rol);

        $this->como($staff)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.permisos', ['pedidos.ver']);
        $this->como($staff)->postJson('/api/products', ['name' => 'X', 'price' => 1, 'stock' => 1])->assertForbidden();
        $this->como($staff)->getJson('/api/orders')->assertOk();
    }

    // ---------------------------------------------------------------- los roles

    public function test_el_admin_crea_un_rol_y_se_guarda_con_lo_que_requiere(): void
    {
        $respuesta = $this->como($this->admin)->postJson('/api/team-roles', [
            'name'        => 'Catálogo',
            'description' => 'Sube el catálogo',
            'permissions' => ['productos.importar'],
        ])->assertCreated();

        $respuesta->assertJsonPath('permissions', ['productos.ver', 'productos.editar', 'productos.importar']);
        $respuesta->assertJsonPath('areas', ['Productos']);
        $respuesta->assertJsonPath('members_count', 0);

        $this->assertSame(
            ['productos.ver', 'productos.editar', 'productos.importar'],
            TeamRole::withoutTenant()->where('name', 'Catálogo')->firstOrFail()->permissions,
        );

        $linea = ActivityLog::latest('created_at')->first();
        $this->assertSame(ActivityLog::EQUIPO_ROL_CREADO, $linea->action);
        $this->assertSame('Creó el rol «Catálogo»: con acceso a productos.', $linea->description);
    }

    public function test_un_rol_sin_permisos_es_valido(): void
    {
        $this->como($this->admin)->postJson('/api/team-roles', ['name' => 'De prueba', 'permissions' => []])
            ->assertCreated()
            ->assertJsonPath('permissions', [])
            ->assertJsonPath('areas', []);
    }

    public function test_un_permiso_que_no_existe_se_rechaza(): void
    {
        $this->como($this->admin)->postJson('/api/team-roles', ['name' => 'Raro', 'permissions' => ['equipo.gestionar']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_el_nombre_no_se_repite_ni_con_mayusculas_ni_con_tildes(): void
    {
        $this->como($this->admin)->postJson('/api/team-roles', ['name' => 'colaborádor', 'permissions' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name' => 'Ya hay un rol con ese nombre.']);

        $this->como($this->admin)->postJson('/api/team-roles', ['name' => 'Administrador', 'permissions' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Otra tienda puede tener el suyo con el mismo nombre.
        $otra = $this->crearTienda('otra-nombres');
        $this->assertSame(1, TeamRole::withoutTenant()->where('tenant_id', $otra->id)->where('name', 'Colaborador')->count());

        // Y renombrar un rol a su propio nombre no choca consigo mismo.
        $rol = $this->rolDeFabrica();
        $this->como($this->admin)->putJson("/api/team-roles/{$rol->id}", ['name' => 'Colaborador'])->assertOk();
    }

    public function test_un_rol_de_otra_tienda_responde_404(): void
    {
        $otra = $this->crearTienda('otra-404');
        $ajeno = TeamRole::withoutTenant()->where('tenant_id', $otra->id)->firstOrFail();

        $this->como($this->admin)->putJson("/api/team-roles/{$ajeno->id}", ['name' => 'Mío'])->assertNotFound();
        $this->como($this->admin)->deleteJson("/api/team-roles/{$ajeno->id}")->assertNotFound();
        $this->assertSame('Colaborador', $ajeno->fresh()->name);
    }

    public function test_cambiar_los_permisos_de_un_rol_cierra_la_sesion_de_quien_lo_tiene(): void
    {
        $rol = $this->rolDeFabrica();
        $staff = $this->crearUsuario($this->tienda, 'vendedor@permisos.test', 'staff', $rol);
        $staff->createToken('abierta');

        $this->como($this->admin)->putJson("/api/team-roles/{$rol->id}", ['name' => 'Vendedor'])->assertOk();
        $this->assertSame(1, $staff->tokens()->count(), 'Cambiar solo el nombre no echa a nadie.');

        $this->como($this->admin)->putJson("/api/team-roles/{$rol->id}", [
            'permissions' => [...$rol->permissions, 'productos.borrar'],
        ])->assertOk()->assertJsonPath('members_count', 1);

        $this->assertSame(0, $staff->tokens()->count());

        $linea = ActivityLog::latest('created_at')->latest('id')->first();
        $this->assertSame(ActivityLog::EQUIPO_ROL_EDITADO, $linea->action);
        $this->assertSame('Cambió el rol «Vendedor»: ahora puede mandar productos a la papelera.', $linea->description);
    }

    /** El permiso nuevo vale en la siguiente petición, sin esperar a ningún token. */
    public function test_el_permiso_nuevo_vale_en_la_siguiente_peticion(): void
    {
        $rol = $this->rolDeFabrica();
        $staff = $this->crearUsuario($this->tienda, 'siguiente@permisos.test', 'staff', $rol);

        $this->como($staff)->getJson('/api/activity')->assertForbidden();

        $rol->update(['permissions' => [...$rol->permissions, 'actividad.ver']]);

        $this->como($staff)->getJson('/api/activity')->assertOk()
            ->assertJsonPath('personas.0.email', 'duenia@permisos.test');
    }

    public function test_un_rol_que_alguien_tiene_no_se_borra(): void
    {
        $rol = $this->rolDeFabrica();
        $this->crearUsuario($this->tienda, 'tiene@permisos.test', 'staff', $rol);

        $this->como($this->admin)->deleteJson("/api/team-roles/{$rol->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este rol lo tiene una persona del equipo: cámbiale el rol antes de borrarlo.');

        $libre = $this->crearRol('Libre', []);
        $this->como($this->admin)->deleteJson("/api/team-roles/{$libre->id}")->assertNoContent();
        $this->assertNull(TeamRole::withoutTenant()->find($libre->id));
    }

    public function test_la_lista_de_roles_cuenta_a_su_gente(): void
    {
        $rol = $this->rolDeFabrica();
        $this->crearUsuario($this->tienda, 'a@permisos.test', 'staff', $rol);
        $inactivo = $this->crearUsuario($this->tienda, 'b@permisos.test', 'staff', $rol);
        $inactivo->update(['is_active' => false]);
        $this->crearRol('Vacío', []);

        $lista = $this->como($this->admin)->getJson('/api/team-roles')->assertOk();

        $this->assertSame(['Colaborador', 'Vacío'], array_column($lista->json(), 'name'));
        // El desactivado también lo tiene: al reactivarlo volvería con ese rol.
        $this->assertSame([2, 0], array_column($lista->json(), 'members_count'));

        $catalogo = $this->como($this->admin)->getJson('/api/permissions')->assertOk();
        $this->assertSame('Productos', $catalogo->json('areas.1.etiqueta'));
        $this->assertSame(['productos.ver'], $catalogo->json('areas.1.permisos.1.requiere'));
    }

    // ------------------------------------------------------ el rol de cada uno

    public function test_se_invita_con_un_rol_y_no_con_uno_de_otra_tienda(): void
    {
        $caja = $this->crearRol('Caja', ['pedidos.gestionar']);

        $this->como($this->admin)->postJson('/api/users', [
            'name' => 'Cajera', 'email' => 'cajera@permisos.test', 'team_role_id' => $caja->id,
        ])->assertCreated()
            ->assertJsonPath('team_role', ['id' => $caja->id, 'name' => 'Caja'])
            ->assertJsonPath('permisos', ['productos.ver', 'pedidos.ver', 'pedidos.gestionar']);

        $this->assertSame('Invitó a Cajera (cajera@permisos.test) con el rol «Caja».', ActivityLog::latest('created_at')->first()->description);

        $otra = $this->crearTienda('otra-invitar');
        $ajeno = TeamRole::withoutTenant()->where('tenant_id', $otra->id)->firstOrFail();

        $this->como($this->admin)->postJson('/api/users', [
            'name' => 'Colado', 'email' => 'colado@permisos.test', 'team_role_id' => $ajeno->id,
        ])->assertStatus(422)->assertJsonValidationErrors('team_role_id');
    }

    public function test_cambiar_a_alguien_de_rol_le_cierra_la_sesion_y_lo_anota(): void
    {
        $caja = $this->crearRol('Caja', ['pedidos.gestionar']);
        $staff = $this->crearUsuario($this->tienda, 'mueve@permisos.test', 'staff');
        $staff->createToken('abierta');

        $this->como($this->admin)->putJson("/api/users/{$staff->id}", ['team_role_id' => $caja->id])
            ->assertOk()
            ->assertJsonPath('team_role.name', 'Caja');

        $this->assertSame(0, $staff->tokens()->count());
        $this->assertSame('Cambió a mueve@permisos.test: rol Colaborador → Caja.', ActivityLog::latest('created_at')->first()->description);

        // Mandar el mismo rol no cambia nada ni echa a nadie.
        $staff->createToken('otra');
        $this->como($this->admin)->putJson("/api/users/{$staff->id}", ['team_role_id' => $caja->id])->assertOk();
        $this->assertSame(1, $staff->tokens()->count());
    }

    public function test_al_subir_a_admin_pierde_el_rol_y_al_bajar_recibe_uno(): void
    {
        $staff = $this->crearUsuario($this->tienda, 'sube@permisos.test', 'staff');

        $this->como($this->admin)->putJson("/api/users/{$staff->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('team_role_id', null)
            ->assertJsonPath('permisos', Permisos::claves());

        $caja = $this->crearRol('Caja', ['pedidos.ver']);
        $this->como($this->admin)->putJson("/api/users/{$staff->id}", ['role' => 'staff', 'team_role_id' => $caja->id])
            ->assertOk()
            ->assertJsonPath('team_role.name', 'Caja');

        // Bajar sin elegir rol da el de por defecto de la tienda.
        $this->como($this->admin)->putJson("/api/users/{$staff->id}", ['role' => 'admin'])->assertOk();
        $this->como($this->admin)->putJson("/api/users/{$staff->id}", ['role' => 'staff'])
            ->assertOk()
            ->assertJsonPath('team_role.name', 'Colaborador');
    }

    // ------------------------------------------- lo que se decide dentro de una ruta

    public function test_el_costo_lo_ve_quien_tiene_su_permiso(): void
    {
        $producto = $this->crearProducto('Ryzen 5 7600', 900);
        $producto->forceFill(['cost' => 700])->save();

        $sin = $this->crearUsuario($this->tienda, 'sincosto@permisos.test', 'staff');
        $this->assertArrayNotHasKey('cost', $this->como($sin)->getJson("/api/products/{$producto->id}")->assertOk()->json());

        $compras = $this->crearRol('Compras', ['productos.ver', 'costos.ver']);
        $con = $this->crearUsuario($this->tienda, 'concosto@permisos.test', 'staff', $compras);
        $this->assertEquals(700, $this->como($con)->getJson("/api/products/{$producto->id}")->assertOk()->json('cost'));
    }

    public function test_exportar_sin_ver_el_costo_se_lleva_el_archivo_sin_esas_columnas(): void
    {
        $producto = $this->crearProducto('Ryzen 5 7600', 900);
        $producto->forceFill(['cost' => 700])->save();

        $exporta = $this->crearRol('Exporta', ['productos.exportar', 'pedidos.exportar']);
        $staff = $this->crearUsuario($this->tienda, 'exporta@permisos.test', 'staff', $exporta);

        $catalogo = $this->cabeceraDe($this->como($staff)->get('/api/products/export')->assertOk()->streamedContent());
        $this->assertNotContains('costo', $catalogo);
        $this->assertContains('precio', $catalogo);

        $pedidos = $this->cabeceraDe($this->como($staff)->get('/api/orders/export')->assertOk()->streamedContent());
        $this->assertNotContains('costo_total', $pedidos);
        $this->assertNotContains('utilidad', $pedidos);
        $this->assertContains('total', $pedidos);

        $deAdmin = $this->cabeceraDe($this->como($this->admin)->get('/api/products/export')->assertOk()->streamedContent());
        $this->assertContains('costo', $deAdmin);
    }

    public function test_borrar_en_lote_pide_ademas_poder_borrar(): void
    {
        $producto = $this->crearProducto('Ryzen 5 7600', 900);

        $lote = $this->crearRol('Lote', ['productos.masivo']);
        $staff = $this->crearUsuario($this->tienda, 'lote@permisos.test', 'staff', $lote);

        $this->como($staff)->postJson('/api/products/bulk', ['product_ids' => [$producto->id], 'bulk_action' => 'delete'])
            ->assertForbidden()
            ->assertJsonPath('message', Permisos::MENSAJE_SIN_PERMISO);
        $this->assertFalse($producto->fresh()->trashed());

        $this->como($staff)->postJson('/api/products/bulk', ['product_ids' => [$producto->id], 'bulk_action' => 'deactivate'])->assertOk();

        $lote->update(['permissions' => Permisos::normalizar(['productos.masivo', 'productos.borrar'])]);
        $this->como($staff)->postJson('/api/products/bulk', ['product_ids' => [$producto->id], 'bulk_action' => 'delete'])->assertOk();
        $this->assertTrue($producto->fresh()->trashed());
    }

    public function test_el_aviso_de_pedido_nuevo_va_solo_a_quien_ve_pedidos(): void
    {
        $producto = $this->crearProducto('Ryzen 5 7600', 900);

        $vendedor = $this->crearUsuario($this->tienda, 'vende@permisos.test', 'staff');
        $catalogo = $this->crearRol('Solo catálogo', ['productos.editar']);
        $fotografa = $this->crearUsuario($this->tienda, 'fotos@permisos.test', 'staff', $catalogo);

        $this->postJson("/api/public/{$this->tienda->slug}/orders", [
            'customer_name' => 'Ana', 'customer_phone' => '51988877766',
            'items' => [['product_id' => $producto->id, 'quantity' => 1]],
        ])->assertCreated();

        Notification::assertSentTo($this->admin, NewOrderNotification::class);
        Notification::assertSentTo($vendedor, NewOrderNotification::class);
        Notification::assertNotSentTo($fotografa, NewOrderNotification::class);
    }

    public function test_la_bitacora_guarda_con_que_rol_se_hizo(): void
    {
        $producto = $this->crearProducto('Ryzen 5 7600', 900);
        $staff = $this->crearUsuario($this->tienda, 'firma@permisos.test', 'staff');

        $this->como($staff)->putJson("/api/products/{$producto->id}", ['name' => 'Ryzen 5 7600', 'price' => 850, 'stock' => 5])->assertOk();

        $linea = ActivityLog::latest('created_at')->first();
        $this->assertSame('staff', $linea->actor_role);
        $this->assertSame('Colaborador', $linea->context['rol_del_actor']);
    }

    // --------------------------------------------------------------- auxiliares

    /** @return array<string, \Illuminate\Routing\Route> "METODO uri" => ruta */
    private function rutasDelPanel(): array
    {
        $rutas = [];

        foreach (Route::getRoutes() as $ruta) {
            if (! in_array('panel', $ruta->gatherMiddleware(), true)) {
                continue;
            }

            foreach (array_diff($ruta->methods(), ['HEAD', 'PATCH']) as $metodo) {
                $rutas["{$metodo} {$ruta->uri()}"] = $ruta;
            }
        }

        return $rutas;
    }

    /** @return list<string> */
    private function permisosDe(\Illuminate\Routing\Route $ruta): array
    {
        $permisos = [];

        foreach ($ruta->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permiso:')) {
                array_push($permisos, ...explode(',', substr($middleware, strlen('permiso:'))));
            }
        }

        return $permisos;
    }

    /**
     * Las rutas con permiso, listas para llamar: los `{parametros}` con un id
     * inventado (la negativa llega antes de buscarlo, ver `EnsurePermiso`).
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private function rutasConPermiso(): array
    {
        $llamadas = [];

        foreach ($this->rutasDelPanel() as $firma => $ruta) {
            $permisos = $this->permisosDe($ruta);

            if ($permisos === []) {
                continue;
            }

            [$metodo] = explode(' ', $firma, 2);
            $uri = preg_replace(
                ['/\{tipo\}/', '/\{[a-z_]+\}/'],
                ['productos', '0190a6e5-0000-7000-8000-000000000000'],
                $ruta->uri(),
            );

            $llamadas[$firma] = [$metodo, '/'.$uri, $permisos];
        }

        $this->assertGreaterThan(30, count($llamadas), 'El recorrido no encontró las rutas del panel.');

        return $llamadas;
    }

    /** @return list<string> */
    private function cabeceraDe(string $csv): array
    {
        $primera = strtok(ltrim($csv, "\u{FEFF}"), "\n");

        return array_map('trim', explode(';', (string) $primera));
    }

    private function rolDeFabrica(): TeamRole
    {
        return TeamRole::withoutTenant()->where('tenant_id', $this->tienda->id)->where('name', 'Colaborador')->firstOrFail();
    }

    /** @param list<string> $permisos */
    private function crearRol(string $nombre, array $permisos, ?Tenant $tienda = null): TeamRole
    {
        $rol = new TeamRole(['name' => $nombre, 'permissions' => Permisos::normalizar($permisos)]);
        $rol->tenant_id = ($tienda ?? $this->tienda)->id;
        $rol->save();

        return $rol;
    }

    private function crearProducto(string $nombre, float $precio): Product
    {
        $producto = new Product(['name' => $nombre, 'price' => $precio, 'stock' => 10, 'is_active' => true, 'status' => 'published']);
        $producto->tenant_id = $this->tienda->id;
        $producto->save();

        return $producto;
    }

    private function crearTienda(string $slug): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => 'Tienda '.$slug, 'whatsapp_number' => '51999999999', 'is_active' => true]);
    }

    private function crearUsuario(Tenant $tienda, string $email, string $rol, ?TeamRole $rolDelEquipo = null): User
    {
        $usuario = new User([
            'name' => 'Usuario '.$email, 'email' => $email, 'password' => 'secret1234',
            'role' => $rol, 'team_role_id' => $rolDelEquipo?->id, 'is_active' => true,
        ]);
        $usuario->tenant_id = $tienda->id;
        $usuario->save();

        return $usuario;
    }

    private function como(User $usuario): self
    {
        // Ver `TeamUsersTest::desdeCero()`: el guard recuerda al usuario de la
        // petición anterior, y con él su rol ya cargado.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse($this->guardOriginal);

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken,
            'X-Tenant'      => $this->tienda->slug,
        ]);
    }
}
