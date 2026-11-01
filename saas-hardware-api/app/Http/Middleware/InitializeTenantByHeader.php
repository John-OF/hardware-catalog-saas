<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Observabilidad;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InitializeTenantByHeader
{
    /**
     * Lo único que el equipo de una tienda cerrada puede hacer en el panel
     * (FUN-28): saber quién es y por qué está cerrada, y salir. Lo mismo que
     * deja `platform.dos-pasos` a un operador sin la verificación activa.
     */
    private const ABIERTAS_CON_LA_TIENDA_CERRADA = ['api/auth/me', 'api/auth/logout'];

    /** El `motivo` del 403 de una tienda cerrada, para que el panel lo distinga. */
    public const MOTIVO_TIENDA_CERRADA = 'tienda_cerrada';

    public function handle(Request $request, Closure $next): mixed
    {
        // El frontend envía el slug del tenant en el header X-Tenant
        $slug = $request->header('X-Tenant');
        $tenant = null;

        // Sin filtrar por `is_active` (FUN-28): una tienda cerrada se busca igual,
        // para poder decirle a SU equipo por qué está cerrada. A cualquier otro
        // le sigue respondiendo el mismo 404 de antes, más abajo.
        if ($slug) {
            $tenant = Tenant::where('slug', $slug)->first();
        } else {
            // Resolver por dominio personalizado. `conDominioVerificado()` es a
            // proposito (FUN-6): sin ella, cualquiera podia escribir el dominio
            // de otra tienda y entrar aqui con SU host, antes de demostrar que
            // el dominio es suyo.
            $host = $request->getHost();
            $tenant = Tenant::conDominioVerificado()->where('custom_domain', $host)->first();
        }

        if (! $tenant || (! $tenant->is_active && ! $this->esDeSuEquipo($tenant))) {
            return response()->json(['message' => 'Tienda no encontrada, inactiva o no especificada.'], 404);
        }

        // Prevenir vulnerabilidad BOLA: Validar pertenencia del usuario autenticado al tenant
        if (Auth::check()) {
            $user = Auth::user();
            if ($user->tenant_id !== $tenant->id) {
                return response()->json(['message' => 'Acceso no autorizado para este tenant.'], 403);
            }
        }

        // FUN-28: antes, una tienda cerrada respondía 404 a todo y el panel lo
        // pintaba como un fallo cualquiera («Error al cargar métricas»), sin decir
        // que estaba cerrada ni qué hacer; y el logout también daba 404, así que
        // el token quedaba vivo. Ahora `me` y `logout` pasan, y el resto responde
        // 403 con el motivo, que es lo que lleva al panel a su pantalla.
        if (! $tenant->is_active && ! $request->is(...self::ABIERTAS_CON_LA_TIENDA_CERRADA)) {
            return response()->json([
                'message' => 'Tu tienda está cerrada: el catálogo no se ve y el panel no deja trabajar hasta que se reabra.',
                'motivo'  => self::MOTIVO_TIENDA_CERRADA,
                'cierre'  => $tenant->cierre(),
            ], 403);
        }

        $tenant->makeCurrent();
        Observabilidad::tienda($tenant->slug);

        return $next($request);
    }

    /**
     * Si quien pregunta es del panel de esa misma tienda. Solo a esa persona se le
     * dice que la tienda existe y está cerrada: para cualquier otro, una tienda
     * cerrada sigue sin existir.
     */
    private function esDeSuEquipo(Tenant $tenant): bool
    {
        $user = Auth::user();

        return $user !== null && $user->tenant_id === $tenant->id && $user->esDelPanel();
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
