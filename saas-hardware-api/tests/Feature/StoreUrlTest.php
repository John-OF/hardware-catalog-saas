<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\StoreUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enlaces públicos de una tienda, para los correos.
 *
 * Se fija aparte porque `FUN-6` introdujo una regresión silenciosa: un dominio
 * sin verificar dejó de resolver nada (`Tenant::scopeConDominioVerificado()`),
 * pero `StoreUrl::forTenant()` seguía devolviéndolo igual — un correo con un
 * enlace que garantiza un 404. Nadie lo notó al cerrar `FUN-6` porque ningún
 * test de las tres notificaciones que usan `StoreUrl` fija el contenido del
 * enlace, sólo que se envían.
 */
class StoreUrlTest extends TestCase
{
    use RefreshDatabase;

    private function tienda(): Tenant
    {
        return Tenant::create([
            'slug'            => 'tienda-a',
            'name'            => 'Tienda A',
            'whatsapp_number' => '51999999999',
            'is_active'       => true,
        ]);
    }

    public function test_sin_dominio_propio_devuelve_la_url_con_slug(): void
    {
        $this->assertSame(
            'http://localhost:5173/tienda-a',
            StoreUrl::forTenant($this->tienda()),
        );
    }

    /** La regresión que motivó este archivo. */
    public function test_con_dominio_propio_sin_verificar_devuelve_la_url_con_slug(): void
    {
        $tienda = $this->tienda();
        $tienda->forceFill(['custom_domain' => 'midominio.com'])->save();

        $this->assertSame('http://localhost:5173/tienda-a', StoreUrl::forTenant($tienda));
    }

    public function test_con_dominio_propio_verificado_devuelve_el_dominio(): void
    {
        $tienda = $this->tienda();
        $tienda->forceFill([
            'custom_domain'             => 'midominio.com',
            'custom_domain_verified_at' => now(),
        ])->save();

        $this->assertSame('https://midominio.com', StoreUrl::forTenant($tienda));
    }

    public function test_sin_tienda_devuelve_null(): void
    {
        $this->assertNull(StoreUrl::forTenant(null));
    }

    public function test_forproduct_compone_sobre_fortenant(): void
    {
        $tienda = $this->tienda();

        $this->assertSame(
            'http://localhost:5173/tienda-a/product/abc',
            StoreUrl::forProduct($tienda, 'abc'),
        );

        $tienda->forceFill(['custom_domain' => 'midominio.com', 'custom_domain_verified_at' => now()])->save();

        $this->assertSame('https://midominio.com/product/abc', StoreUrl::forProduct($tienda, 'abc'));
    }
}
