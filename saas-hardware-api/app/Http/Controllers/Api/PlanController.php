<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;

/**
 * Plan de la tienda, sus limites y lo que lleva gastado (SAAS-3).
 *
 * Existe para que el panel pueda pintar "12 / 20 productos" ANTES de que el
 * dueño choque con el tope. Un limite que solo se conoce al recibir un 422 se
 * vive como un fallo del programa, no como el plan que se contrato.
 *
 * Va en su propio endpoint y no dentro de `GET /tenant` a proposito: esa
 * respuesta todavia devuelve el modelo crudo (la deuda consciente de `TEC-4`) y
 * ensancharla arrastraria ese refactor de rebote.
 */
class PlanController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'plan'   => PlanGate::plan(),
            'label'  => PlanGate::label(),
            'limits' => PlanGate::limits(),
            'usage'  => PlanGate::usage(),
        ]);
    }
}
