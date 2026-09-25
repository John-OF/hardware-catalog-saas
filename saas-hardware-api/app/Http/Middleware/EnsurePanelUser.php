<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelUser
{
    /**
     * La puerta del panel: cualquier usuario del equipo activo, admin o staff
     * (FUN-4). Lo que solo puede hacer un admin lleva ademas `EnsureAdmin`.
     *
     * El rol se lee del usuario en cada peticion y no de las abilities del
     * token a proposito: asi, bajar a alguien de admin a staff surte efecto en
     * su siguiente clic, sin esperar a que caduque un token que se emitio cuando
     * aun era admin.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->esDelPanel() || ! $user->is_active) {
            abort(403, 'No autorizado.');
        }

        // ACC-3: un token del login de clientes no entra al panel aunque su
        // usuario sea del equipo. El login de clientes ya no se lo da a nadie
        // del equipo, pero los que emitio antes siguen vivos hasta 30 dias, y
        // esta es la barrera que los apaga sin tener que ir a borrarlos.
        if (self::esTokenDeCliente($user)) {
            abort(403, 'No autorizado.');
        }

        return $next($request);
    }

    /**
     * Lo mismo que `Suplantacion::activa()`: con la sesion por cookie de Sanctum
     * llega un `TransientToken`, que no es un modelo y no tiene abilities.
     */
    private static function esTokenDeCliente($user): bool
    {
        $token = $user->currentAccessToken();

        return $token instanceof Model
            && in_array('customer', (array) $token->getAttribute('abilities'), true);
    }
}
