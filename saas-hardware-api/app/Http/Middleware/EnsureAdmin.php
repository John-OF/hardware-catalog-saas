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
     * deja pasar tambien a `staff`-, sino la segunda cerradura de lo que solo
     * decide un admin: configuracion, categorias, paginas, equipo y lo que borra
     * o cambia el catalogo de golpe. Sigue bloqueando a clientes igual que antes.
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
