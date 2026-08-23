<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InitializeTenantByHeader
{
    public function handle(Request $request, Closure $next): mixed
    {
        // El frontend envía el slug del tenant en el header X-Tenant
        $slug = $request->header('X-Tenant');
        $tenant = null;

        if ($slug) {
            $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();
        } else {
            // Resolver por dominio personalizado
            $host = $request->getHost();
            $tenant = Tenant::where('custom_domain', $host)->where('is_active', true)->first();
        }

        if (!$tenant) {
            return response()->json(['message' => 'Tienda no encontrada, inactiva o no especificada.'], 404);
        }

        // Prevenir vulnerabilidad BOLA: Validar pertenencia del usuario autenticado al tenant
        if (Auth::check()) {
            $user = Auth::user();
            if ($user->tenant_id !== $tenant->id) {
                return response()->json(['message' => 'Acceso no autorizado para este tenant.'], 403);
            }
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
