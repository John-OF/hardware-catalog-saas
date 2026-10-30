<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Permitir el acceso solo a usuarios administradores activos.
     *
     * Desde FUN-4 ya no es la puerta del panel -esa es `EnsurePanelUser`, que
     * deja pasar tambien a `staff`-, y desde MOD-21 tampoco es la cerradura de
     * cada ruta -esa es `EnsurePermiso`, segun el rol del equipo-. Queda para lo
     * que no se delega a ningun rol: el equipo y sus roles. Sigue bloqueando a
     * clientes igual que antes.
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
