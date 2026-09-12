<?php

namespace App\Http\Middleware;

use Closure;
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

        return $next($request);
    }
}
