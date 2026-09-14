<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de planes de LA PLATAFORMA, para la landing (INF-1).
 *
 * Distinto de `PlanController` (que devuelve el plan de la tienda ya
 * autenticada, con su consumo) y de `PlanGate` (que decide qué puede crear
 * cada tienda): aquí no hay tienda ni sesión de por medio, es la lista entera
 * de `config/plans.php` para quien todavía no se ha registrado y está
 * decidiendo si comprar.
 *
 * Sin autenticación y sin `X-Tenant` a propósito: es la primera pantalla que
 * ve alguien que ni siquiera tiene tienda todavía.
 */
class PublicPlansController extends Controller
{
    public function index(): JsonResponse
    {
        // `public` (FUN-16) es lo que separa los planes que se VENDEN -los que
        // salen aquí- del plan 'trial', que es un calculo interno de
        // `PlanGate` mientras dura la prueba de una tienda y no algo que nadie
        // elija ni compre. Sin este filtro, la landing enseñaría una cuarta
        // tarjeta sin precio y sin sentido para quien la lee.
        $planes = collect(config('plans.plans'))
            ->filter(fn (array $plan) => $plan['public'] ?? false)
            ->map(fn (array $plan, string $clave) => [
                'key'       => $clave,
                'label'     => $plan['label'],
                'price_usd' => $plan['price_usd'],
                'limits'    => $plan['limits'],
            ])
            ->values();

        return response()->json(['plans' => $planes]);
    }
}
