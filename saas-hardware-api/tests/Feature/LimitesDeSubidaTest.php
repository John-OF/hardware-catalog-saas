<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Subidas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * `UI-15`. Los topes de subida viven en `config/subidas.php` y el navegador tiene
 * su copia en `utils/subidas.ts` para avisar antes de subir. Antes cada regla
 * llevaba su número escrito a mano y el formulario de producto decía "Máx 5MB"
 * mientras el servidor aceptaba 10: nada vigilaba que dijeran lo mismo.
 */
class LimitesDeSubidaTest extends TestCase
{
    use RefreshDatabase;

    /** Mismo patrón que la lista de zonas horarias (`ZonaHorariaDeLaTiendaTest`). */
    public function test_la_copia_del_frontend_no_se_ha_separado_de_la_config(): void
    {
        $archivo = base_path('../saas-hardware-frontend/src/utils/subidas.ts');

        if (! file_exists($archivo)) {
            $this->markTestSkipped('El frontend no está en este árbol.');
        }

        preg_match('/export const LIMITES_DE_SUBIDA = \{(.*?)\n\} as const;/s', file_get_contents($archivo), $bloque);
        $this->assertNotEmpty($bloque, 'No se encontró LIMITES_DE_SUBIDA en utils/subidas.ts.');

        preg_match_all("/^\s*(\w+): \{ kb: (\d+), tipos: \[([^\]]*)\] \},/m", $bloque[1], $filas, PREG_SET_ORDER);

        $delFrontend = [];
        foreach ($filas as [, $tipo, $kb, $tipos]) {
            preg_match_all("/'([a-z]+)'/", $tipos, $extensiones);
            $delFrontend[$tipo] = ['kb' => (int) $kb, 'tipos' => $extensiones[1]];
        }

        $this->assertSame(config('subidas'), $delFrontend, 'config/subidas.php y utils/subidas.ts dicen topes o tipos distintos.');
    }

    /** Los mismos casos que `tamanoLegible()` en `utils/subidas.test.ts`. */
    public function test_el_tope_se_dice_como_lo_diria_una_persona(): void
    {
        $this->assertSame('10 MB', Subidas::legible(10240));
        $this->assertSame('5 MB', Subidas::legible(5120));
        $this->assertSame('1,5 MB', Subidas::legible(1536));
        $this->assertSame('512 KB', Subidas::legible(512));
    }

    /**
     * Que las reglas lean la config y no un número suyo: se baja el tope en
     * caliente y el mensaje tiene que seguirlo.
     */
    public function test_la_regla_sigue_a_la_config(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        config(['subidas.imagen.kb' => 100]);

        $tenant = Tenant::create(['slug' => 'tienda-topes', 'name' => 'T', 'whatsapp_number' => '51999999999', 'is_active' => true, 'plan' => 'enterprise']);
        $admin = new User(['name' => 'D', 'email' => 'd@topes.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $admin->tenant_id = $tenant->id;
        $admin->save();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('t', ['admin'])->plainTextToken,
            'X-Tenant' => $tenant->slug,
            'Accept' => 'application/json',
        ])->post('/api/products', [
            'name' => 'RTX', 'price' => 10, 'stock' => 1,
            'image' => UploadedFile::fake()->image('foto.jpg')->size(101),
        ])->assertStatus(422)->assertJsonPath('errors.image.0', 'El campo imagen no puede pesar más de 100 KB.');
    }

    /**
     * Ninguna regla de archivo escribe su tipo o su tope a mano: si alguien lo
     * hace, el navegador y el servidor vuelven a poder decir cosas distintas.
     */
    public function test_ninguna_regla_escribe_su_tope_a_mano(): void
    {
        $aMano = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $archivo) {
            if ($archivo->getRealPath() === realpath(app_path('Support/Subidas.php'))) {
                continue;
            }

            foreach (file($archivo->getRealPath()) as $n => $linea) {
                if (preg_match("/['\"|]mimes:|(image|file)\|[^'\"]*max:\d/", $linea)) {
                    $aMano[] = $archivo->getRelativePathname().':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $aMano, 'Estas reglas escriben mimes o max a mano en vez de usar Subidas::reglas().');
    }
}
