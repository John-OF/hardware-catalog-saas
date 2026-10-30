<?php

namespace App\Http\Middleware;

use App\Support\Permisos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La cerradura de cada ruta del panel (MOD-21): `permiso:productos.borrar`.
 *
 * Va DETRAS de `panel`, que ya comprobo que quien llama es del equipo y esta
 * activo; esto solo mira si su rol tiene ese permiso. Un admin pasa siempre.
 *
 * Esta en la lista de prioridad de `bootstrap/app.php` antes de
 * `SubstituteBindings`, por lo mismo que `EnsureAdmin` (FUN-4): la negativa
 * tiene que llegar antes de buscar el `{product}` de la URL, o el 404 frente al
 * 403 diria que ids existen.
 *
 * Que toda ruta del panel lleve uno de estos (o `admin`, o figure en la lista
 * de las que no lo necesitan) lo vigila `PermisosPorAccionTest`.
 */
class EnsurePermiso
{
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        if (! Permisos::usuarioPuede($permiso)) {
            abort(403, Permisos::MENSAJE_SIN_PERMISO);
        }

        return $next($request);
    }
}
