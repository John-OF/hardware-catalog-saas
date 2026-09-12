<?php

namespace App\Http\Middleware;

use App\Support\Suplantacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja el panel en solo lectura cuando quien mira es soporte (INF-2).
 *
 * Va en el grupo entero del panel y no ruta por ruta. Es a posta: la lista de
 * rutas del panel crece cada semana, y una regla que hay que acordarse de poner
 * en cada ruta nueva es una regla que un día no se pone. Aquí lo que decide no
 * es qué ruta es, sino el verbo — GET y HEAD pasan, todo lo demás no—, así que
 * una ruta nueva nace protegida sin que nadie haga nada.
 *
 * El 403 lleva un mensaje explícito porque el frontend lo enseña tal cual: el
 * operador tiene que entender que no ha fallado nada, que sencillamente no se
 * puede escribir desde una sesión de soporte.
 */
class RestrictImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        // Cerrar la sesión es la única escritura que se permite, y no es una
        // excepción incómoda: revocar la llave prestada antes de que caduque
        // deja la tienda MÁS protegida, no menos. Sin esto, el botón de salir
        // del modo soporte respondería 403 y el token seguiría vivo sus quince
        // minutos.
        if ($request->is('api/auth/logout')) {
            return $next($request);
        }

        if (Suplantacion::activa($request) && ! $request->isMethodSafe()) {
            abort(403, 'Sesión de soporte: solo lectura. Para cambiar algo, entra el dueño de la tienda.');
        }

        return $next($request);
    }
}
