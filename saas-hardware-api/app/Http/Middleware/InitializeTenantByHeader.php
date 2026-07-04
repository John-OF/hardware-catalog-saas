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
        $tenant = null;

        if ($slug) {
            $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();
        } else {
            // Resolver por dominio personalizado
            $host = $request->getHost();
            $tenant = Tenant::where('custom_domain', $host)->where('is_active', true)->first();
        }

        if (!$tenant) {
            return response()->json(['message' => 'Tienda no encontrada, inactiva o no especificada.'], 404);
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
