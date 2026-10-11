<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Lo que `salud:comprobar` necesita saber del PHP que atiende la web (`INF-19`).
 *
 * Existe porque la consola no puede verlo: suele leer otro php.ini, y comparar
 * sus topes daría por buena una subida que la web corta (`operacion.md` § 5,
 * la verificación que comparte la suposición con la acción).
 *
 * La ruta va firmada (`signed`) y la URL caduca en dos minutos: solo la genera
 * quien tiene la `APP_KEY`, o sea el propio comando en el servidor. No hay nada
 * de las tiendas aquí, pero tampoco hace falta enseñarle a nadie la
 * configuración de PHP.
 */
class SaludController extends Controller
{
    public function php(): JsonResponse
    {
        return response()->json([
            'sapi'                => PHP_SAPI,
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size'       => ini_get('post_max_size'),
            'max_file_uploads'    => ini_get('max_file_uploads'),
            'memory_limit'        => ini_get('memory_limit'),
        ]);
    }
}
