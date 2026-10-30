<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Actividad del panel de la tienda: quién cambió qué y cuándo (INF-3).
 *
 * Con el permiso `actividad.ver` (MOD-21), que el rol de fábrica «Colaborador»
 * no trae: es lo que hace el resto del equipo, y dárselo a todos sería que cada
 * colaborador vigile a los demás. Un admin decide si un rol de confianza lo
 * tiene.
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

        // MOD-21: quiénes forman el equipo hoy, para el filtro por persona. Antes
        // la pantalla lo pedía a `GET /users`, que es del admin; desde que ver la
        // actividad es un permiso que puede tener un rol del equipo, esa
        // petición le daría 403. Solo nombre y correo: lo que pinta el filtro.
        $personas = User::where('tenant_id', app('currentTenant')->id)
            ->whereIn('role', User::ROLES_DE_PANEL)
            ->orderBy('name')
            ->get(['name', 'email'])
            ->map(fn (User $u) => ['name' => $u->name, 'email' => $u->email])
            ->values();

        // INF-20: cuánto se guarda, para que la pantalla lo diga en vez de dejar
        // que alguien busque algo de hace dos años y crea que no pasó.
        return response()->json([...$lineas->toArray(), 'retencion_dias' => ActivityLog::DIAS_EN_TIENDA, 'personas' => $personas]);
    }
}
