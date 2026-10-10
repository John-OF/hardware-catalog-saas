<?php

namespace App\Http\Middleware;

use App\Support\DosPasos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En producción, el panel de plataforma exige la verificación en dos pasos (SEC-9).
 *
 * Un operador que todavía no la tiene puede entrar —si no, no podría
 * activarla—, pero con esa sesión solo llega a `me`, a activarla y a salir: el
 * resto de rutas responde 403. Es lo que convierte la verificación en
 * obligatoria y no en una recomendación que nadie sigue.
 *
 * Fuera de producción no estorba, igual que la lista de IPs (`AUD-13`): en local
 * se puede probar sin montar una app de autenticación.
 *
 * El 403 lleva `motivo` para que el panel lo distinga del de la cerca de IP, que
 * es el otro 403 de estas rutas y pide algo muy distinto.
 */
class EnsurePlatformDosPasos
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (DosPasos::obligatoria() && $usuario && ! $usuario->tieneDosPasos()) {
            return response()->json([
                'message' => 'Activa la verificación en dos pasos para usar el panel de plataforma.',
                'motivo'  => 'dos_pasos_pendiente',
            ], 403);
        }

        return $next($request);
    }
}
