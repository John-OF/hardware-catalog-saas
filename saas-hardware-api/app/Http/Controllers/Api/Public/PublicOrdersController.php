<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicOrdersController extends Controller
{
    /**
     * Listar el historial de pedidos del cliente autenticado
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $orders = $request->user()
            ->orders()
            ->where('tenant_id', $tenant->id)
            ->with(['items'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($orders);
    }
}
