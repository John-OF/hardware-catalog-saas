<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Permitir el acceso solo a usuarios administradores activos.
     * Bloquea que un cliente (role 'customer') use las rutas del panel.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'admin' || !$user->is_active) {
            abort(403, 'No autorizado.');
        }

        return $next($request);
    }
}
