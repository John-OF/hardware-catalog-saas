<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Actividad del panel de la tienda: quién cambió qué y cuándo (INF-3).
 *
 * Solo admin (va en el subgrupo `admin` de `routes/api.php`): es lo que hace el
 * resto del equipo, y dárselo a staff sería que cada colaborador vigile a los
 * demás.
 *
 * `ActivityLog` no lleva `BelongsToTenant` (ver su cabecera), así que el filtro
 * por tienda de aquí abajo **es** el aislamiento, no una ayuda: sin
 * `deTienda()` este listado enseñaría la actividad de todas las tiendas. Lo
 * vigila `BitacoraDeTiendaTest`.
 */
class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'area'  => ['nullable', Rule::in(ActivityLog::AREAS_DE_TIENDA)],
            'actor' => ['nullable', 'string', 'max:150'],
        ]);

        $lineas = ActivityLog::deTienda(app('currentTenant')->id)
            ->delPanelDeTienda()
            ->with('actor:id,name')
            ->when($filtros['area'] ?? null, fn ($q, $area) => $q->where('action', 'like', "{$area}.%"))
            // Por correo y no por id: así también se encuentra lo que hizo
            // alguien a quien ya se quitó del equipo.
            ->when($filtros['actor'] ?? null, fn ($q, $correo) => $q->where('actor_email', $correo))
            ->orderByDesc('created_at')
            // Sin la IP: la guarda la tabla, pero el admin no necesita la de sus
            // compañeros para saber quién hizo algo.
            ->select(['id', 'actor_id', 'actor_email', 'actor_role', 'action', 'description', 'context', 'created_at'])
            ->paginate(Paginacion::porPagina($request, 30));

        return response()->json($lineas);
    }
}
