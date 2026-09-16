<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Costos;
use App\Support\Reportes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La pantalla de Reportes: cuanto se vendio, cuando, de que y con cuanto margen (MOD-9).
 *
 * El panel ya tenia `/dashboard/stats`, que es otra cosa: cuatro totales desde
 * el principio de los tiempos, los cinco productos mas VISTOS y los cinco
 * ultimos pedidos. Sirve para abrir el panel por la maniana, no para decidir
 * nada. No habia forma de preguntar "cuanto vendi en agosto", "que se vende de
 * verdad" ni "que se me esta acabando", que son las tres preguntas del dueño de
 * una tienda. Aquel endpoint se queda igual -lo usa Resumen- y este responde
 * esas tres.
 *
 * Las sumas viven en `App\Support\Reportes` porque tambien las pide la
 * exportacion a CSV; aqui solo se decide quien ve que.
 *
 * **El costo y la utilidad, solo para un admin** (MOD-6). Aqui no vale con
 * esconder un campo del modelo: son sumas hechas en SQL, asi que se piden solo
 * si `Costos::usuarioPuedeVerlos()` y las claves ni siquiera aparecen en la
 * respuesta de staff. Lo demas -ventas, unidades, mas vendidos, stock bajo- si
 * lo ve staff: son los mismos numeros que ya tiene delante en Pedidos y en
 * Productos, ordenados de otra forma.
 */
class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$desde, $hasta, $agrupacion] = Reportes::rango($request);

        $reportes = new Reportes(app('currentTenant')->id, $desde, $hasta, $agrupacion);
        $conCostos = Costos::usuarioPuedeVerlos();

        return response()->json([
            'rango' => [
                'desde'      => $desde->toDateString(),
                'hasta'      => $hasta->toDateString(),
                'agrupacion' => $agrupacion,
            ],
            'resumen'      => $reportes->resumen($conCostos),
            'serie'        => $reportes->serie($conCostos),
            'mas_vendidos' => $reportes->masVendidos($conCostos),
            'stock_bajo'   => $reportes->stockBajo(),
        ]);
    }
}
