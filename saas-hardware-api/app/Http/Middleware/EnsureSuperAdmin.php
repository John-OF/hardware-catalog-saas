<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe las rutas de plataforma al operador del SaaS (SAAS-4).
 *
 * Es el hermano de EnsureAdmin, pero al reves en un punto importante: estas
 * rutas NO llevan el middleware de tenant, porque el super-admin trabaja por
 * encima de todas las tiendas y no pertenece a ninguna (`tenant_id` null).
 *
 * Se exige ademas la habilidad 'superadmin' del token: un token de dueño de
 * tienda o de cliente no sirve aqui aunque alguien cambiara el rol en la base.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'superadmin' || ! $user->is_active) {
            abort(403, 'No autorizado.');
        }

        if (! $user->tokenCan('superadmin')) {
            abort(403, 'No autorizado.');
        }

        return $next($request);
    }
}
