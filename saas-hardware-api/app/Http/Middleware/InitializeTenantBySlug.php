<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la tienda de las rutas publicas desde el slug de la URL (AUD-4).
 *
 * El panel privado ya resolvia la suya con `InitializeTenantByHeader`, pero las
 * rutas publicas no resolvian ninguna: cada metodo del controlador hacia su
 * `Tenant::where('slug', ...)` y filtraba a mano en cada consulta. Estaba bien
 * hecho —se reviso una a una—, pero sin red debajo: un endpoint publico nuevo al
 * que se le olvidara el `where('tenant_id')` habria devuelto datos de todas las
 * tiendas sin que saltara nada.
 *
 * Con la tienda resuelta aqui, el global scope de `BelongsToTenant` filtra solo y
 * el filtro a mano de los controladores pasa a ser redundante. Esa redundancia
 * es el objetivo: que sobre, no que haga falta. Por eso NO se han quitado los
 * `where('tenant_id')` existentes; son dos barreras, no una repetida por descuido.
 *
 * Se devuelve el mismo 404 que daba el `firstOrFail()` de los controladores, solo
 * que antes de llegar a ellos.
 */
class InitializeTenantBySlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');

        if (!is_string($slug)) {
            abort(404, 'Tienda no encontrada.');
        }

        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();

        if (!$tenant) {
            abort(404, 'Tienda no encontrada o inactiva.');
        }

        $tenant->makeCurrent();

        return $next($request);
    }

    /**
     * La tienda actual muere con la peticion.
     *
     * Desde AUD-4 el global scope falla en cerrado, o sea que "que tienda es la
     * actual" pasa a ser dato de correctitud y no un detalle: dejarla enlazada
     * despues de responder significa que el siguiente trabajo que corra en este
     * mismo proceso consultaria filtrando por una tienda que no es la suya.
     *
     * Con PHP-FPM cada peticion arranca un contenedor nuevo y no se notaria,
     * pero eso es que lo tapa el modelo de proceso, no que este bien: bajo
     * Octane, en la cola o en la suite de tests -donde varias peticiones
     * comparten contenedor- si se nota. Se limpia aqui para que produccion y
     * pruebas se comporten igual.
     */
    public function terminate(Request $request, $response): void
    {
        Tenant::forgetCurrent();
    }

}
