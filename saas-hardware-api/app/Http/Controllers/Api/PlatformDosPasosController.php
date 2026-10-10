<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\DosPasos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activar la verificación en dos pasos del operador (SEC-9).
 *
 * Dos pasos porque activarla sin comprobar nada es la forma de quedarse fuera:
 * si el QR no llegó bien al móvil, el siguiente login pediría un código que el
 * operador no puede dar. Por eso la semilla nace sin confirmar y solo cuenta
 * cuando vuelve un código bueno hecho con ella.
 *
 * Desde el panel solo se activa. Quitarla es un comando del servidor
 * (`platform:quitar-dos-pasos`): si bastara con la sesión abierta, quien la
 * robara podría apagarla, y en producción sin ella el panel no deja hacer nada.
 */
class PlatformDosPasosController extends Controller
{
    /** Empieza la activación: una semilla nueva, su QR y la clave para escribirla a mano. */
    public function iniciar(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario->tieneDosPasos()) {
            abort(409, 'La verificación en dos pasos ya está activada.');
        }

        $secreto = DosPasos::iniciar($usuario);
        $enlace = DosPasos::enlace($usuario, $secreto);

        return response()->json([
            'secreto' => $secreto,
            'enlace'  => $enlace,
            'qr_svg'  => DosPasos::qr($enlace),
        ]);
    }

    /**
     * Termina la activación con un código de la app, que prueba que la semilla
     * llegó al móvil, y da los códigos de recuperación. Es la única vez que se
     * pueden ver: se guardan como hash.
     */
    public function confirmar(Request $request): JsonResponse
    {
        $request->validate(['codigo' => 'required|string|max:20']);

        $usuario = $request->user();

        if ($usuario->tieneDosPasos()) {
            abort(409, 'La verificación en dos pasos ya está activada.');
        }

        if ($usuario->two_factor_secret === null) {
            throw ValidationException::withMessages([
                'codigo' => ['No hay ninguna activación empezada. Vuelve a pulsar «Activar».'],
            ]);
        }

        if (! DosPasos::gastarCodigo($usuario, (string) $request->input('codigo'))) {
            throw ValidationException::withMessages([
                'codigo' => ['El código no es correcto. Si el móvil no tiene la hora en automático, ponla: los códigos dependen de ella.'],
            ]);
        }

        // Todo o nada. En plataforma la línea de la bitácora es parte de la acción
        // y, si no se puede anotar, falla (ver ActivityLog); sin la transacción,
        // ese fallo dejaría la verificación activa y al operador sin haber visto
        // nunca sus códigos de recuperación.
        $codigos = DB::transaction(function () use ($usuario, $request) {
            $usuario->forceFill(['two_factor_confirmed_at' => now()])->save();
            $codigos = DosPasos::nuevosCodigosDeRecuperacion($usuario);

            ActivityLog::registrar(
                ActivityLog::OPERADOR_DOS_PASOS_ACTIVADA,
                'Activó la verificación en dos pasos.',
                null,
                $usuario,
                [],
                $request,
            );

            return $codigos;
        });

        return response()->json([
            'codigos'   => $codigos,
            'seguridad' => DosPasos::estado($usuario),
        ]);
    }
}
