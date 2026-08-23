<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja el panel de plataforma solo a las IPs autorizadas (AUD-13).
 *
 * Va tanto en el login como en el grupo autenticado, y el login es el que de
 * verdad importa: es donde se prueban contrasenias. Ponerlo solo detras de
 * `auth:sanctum` habria dejado el formulario de entrada abierto a cualquiera.
 *
 * No sustituye al 2FA, que sigue siendo lo deseable; es el "como minimo" que
 * pedia la auditoria y que no depende de que el operador tenga un movil a mano.
 */
class RestrictPlatformIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $permitidas = config('platform.allowed_ips', []);

        // Renuncia explicita: queda en el .env, que es donde se puede auditar.
        if (in_array('*', $permitidas, true)) {
            return $next($request);
        }

        if ($permitidas === []) {
            // Sin configurar. En local o en pruebas no se estorba a nadie.
            if (! app()->isProduction()) {
                return $next($request);
            }

            Log::warning('Panel de plataforma sin IPs autorizadas: acceso denegado', [
                'ip' => $request->ip(),
            ]);

            abort(403, 'El panel de plataforma no tiene IPs autorizadas. Configura PLATFORM_ALLOWED_IPS en el servidor (o ponlo en "*" si no quieres restringir por IP).');
        }

        if (! IpUtils::checkIp((string) $request->ip(), $permitidas)) {
            Log::warning('Acceso al panel de plataforma desde una IP no autorizada', [
                'ip' => $request->ip(),
            ]);

            abort(403, 'No autorizado.');
        }

        return $next($request);
    }
}
