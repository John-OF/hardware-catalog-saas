<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La tienda pidio algo que su plan no le permite (SAAS-3).
 *
 * Es una excepcion y no un `response()->json` en cada controlador por dos
 * razones. La primera es que los seis puntos donde se aplica un limite
 * devuelven exactamente la misma forma de error, y repetirla seis veces
 * garantiza que el septimo se escriba distinto. La segunda es que asi el
 * chequeo se puede hacer ANTES de tocar nada -al principio del metodo- sin
 * arrastrar un `return` por todo el cuerpo.
 *
 * **422 y no 402 Payment Required.** 402 describe mejor lo que pasa, pero el
 * panel ya trata el 422 como "el servidor rechazo esto y el mensaje se le
 * enseña al usuario", que es el comportamiento que se quiere; y el tope de
 * filas del import CSV (AUD-10), que es un rechazo por politica igual que
 * este, ya responde 422. Lo que distingue a este error de una validacion
 * normal es `code: plan_limit`, que es por lo que pregunta el frontend.
 */
class PlanLimitException extends \Exception
{
    public function __construct(
        private readonly string $plan,
        private readonly string $limite,
        private readonly int|bool|null $tope,
        private readonly ?int $actual,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message'   => $this->getMessage(),
            'code'      => 'plan_limit',
            'plan'      => $this->plan,
            'limit_key' => $this->limite,
            'limit'     => $this->tope,
            'current'   => $this->actual,
        ], 422);
    }
}
