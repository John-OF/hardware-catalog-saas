<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogo de planes de la plataforma, para la landing (INF-1).
 *
 * Lo que importa fijar: que salgan los tres planes con su precio y sus
 * límites reales -la MISMA matriz que ya usa `PlanGate`, no una copia a
 * mano-, y que sea de verdad público (sin token ni `X-Tenant`).
 */
class PublicPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_los_tres_planes_sin_autenticacion(): void
    {
        $respuesta = $this->getJson('/api/public/plans')->assertOk();

        $planes = collect($respuesta->json('plans'))->keyBy('key');

        $this->assertCount(3, $planes);
        $this->assertSame(['free', 'pro', 'enterprise'], $planes->keys()->all());
    }

    public function test_cada_plan_trae_su_precio_y_sus_limites_reales(): void
    {
        config(['plans.plans.pro.price_usd' => 29, 'plans.plans.pro.limits.products' => 500]);
        config(['plans.plans.free.price_usd' => 15]);

        $planes = collect($this->getJson('/api/public/plans')->json('plans'))->keyBy('key');

        $this->assertSame(29, $planes['pro']['price_usd']);
        $this->assertSame(500, $planes['pro']['limits']['products']);
        // FUN-16: el más barato ya no es gratis, y no debería volver a serlo
        // sin que alguien lo decida a propósito.
        $this->assertSame(15, $planes['free']['price_usd']);
        // `null` es "sin tope" (ver la cabecera de config/plans.php), y tiene
        // que viajar tal cual y no como string ni desaparecer de la respuesta.
        $this->assertNull($planes['enterprise']['limits']['products']);
    }

    /**
     * `trial` es un plan interno (FUN-16): lo usa `PlanGate` mientras dura la
     * prueba de una tienda, nadie lo compra. Enseñarlo aquí sería una cuarta
     * tarjeta de precio sin sentido en la landing.
     */
    public function test_el_plan_de_prueba_no_sale_en_el_catalogo_publico(): void
    {
        $claves = collect($this->getJson('/api/public/plans')->json('plans'))->pluck('key');

        $this->assertFalse($claves->contains('trial'));
    }
}
