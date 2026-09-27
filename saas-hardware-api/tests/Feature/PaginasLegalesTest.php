<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * `INF-15`. Las páginas legales de la plataforma (`/terminos`, `/privacidad`,
 * `/reembolsos`) son rutas del frontend que van antes que `/:slug`: una tienda
 * que se registrara con uno de esos nombres tendría su catálogo tapado por la
 * página legal. Por eso son slugs reservados.
 *
 * Las rutas se leen de `utils/legal.ts`, igual que las zonas horarias y las
 * monedas: si mañana se añade una página legal y se olvida reservar su nombre,
 * esto falla.
 */
class PaginasLegalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_ninguna_tienda_puede_llamarse_como_una_pagina_legal(): void
    {
        $slugs = $this->rutasDelFrontend();

        $this->assertSame(['terminos', 'privacidad', 'reembolsos'], $slugs);

        foreach ($slugs as $i => $slug) {
            $this->registrar($slug, "duenio{$i}@legal.test")
                ->assertUnprocessable()
                ->assertJsonPath('errors.slug.0', 'El slug elegido está reservado por la plataforma.');
        }
    }

    /** Control: un slug normal sigue entrando. */
    public function test_un_slug_normal_sigue_valiendo(): void
    {
        $this->registrar('terminos-y-mas', 'otro@legal.test')->assertCreated();
    }

    // ------------------------------------------------------------ apoyo

    /** @return list<string> */
    private function rutasDelFrontend(): array
    {
        $archivo = base_path('../saas-hardware-frontend/src/utils/legal.ts');

        if (! file_exists($archivo)) {
            $this->markTestSkipped('El frontend no está en este árbol.');
        }

        preg_match('/export const PAGINAS_LEGALES = \[(.*?)\] as const;/s', file_get_contents($archivo), $bloque);
        $this->assertNotEmpty($bloque, 'No se encontró PAGINAS_LEGALES en utils/legal.ts.');

        preg_match_all("/ruta: '\\/([a-z-]+)'/", $bloque[1], $rutas);

        return $rutas[1];
    }

    private function registrar(string $slug, string $correo): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/register', [
            'store_name' => 'Tienda '.$slug,
            'slug' => $slug,
            'whatsapp' => '593999999999',
            'name' => 'Duenio',
            'email' => $correo,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }
}
