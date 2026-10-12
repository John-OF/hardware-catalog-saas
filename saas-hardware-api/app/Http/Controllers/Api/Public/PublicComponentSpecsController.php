<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Support\SpecsFijas;
use Illuminate\Http\JsonResponse;

/**
 * Las specs con nombre fijo por tipo de componente (FUN-8, paso 13.2).
 *
 * Es la unica copia que tiene el frontend de `config/component_specs.php`: el
 * formulario del panel la usa para preguntar los campos del tipo, y el catalogo
 * publico para ponerles etiqueta y unidad. Antes habia dos listas de claves
 * aproximadas escritas a mano en `PcBuilderPage.tsx` y ninguna en el servidor.
 *
 * Sin tienda y sin sesion, como `PublicPlansController`: la definicion es de la
 * plataforma, la misma para todas las tiendas.
 */
class PublicComponentSpecsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(SpecsFijas::paraApi());
    }
}
