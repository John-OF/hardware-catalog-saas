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

        if (!$slug) {
            return response()->json(['message' => 'Tenant no especificado.'], 400);
        }

        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tienda no encontrada o inactiva.'], 404);
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
}
