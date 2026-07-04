<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Devuelve las estadísticas globales del panel de administración.
     */
    public function stats(): JsonResponse
    {
        $tenant = app('currentTenant');

        // 1. Ventas Totales (Pedidos atendidos/completados)
        $totalSales = Order::where('tenant_id', $tenant->id)
            ->where('status', 'attended')
            ->sum('total');

        // 2. Cantidad de Pedidos Totales
        $totalOrders = Order::where('tenant_id', $tenant->id)->count();

        // 3. Cantidad de Productos Activos
        $totalProducts = Product::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->count();

        // 4. Visitas al catálogo
        $catalogViews = $tenant->views_count ?? 0;

        // 5. Productos Más Vistos (Top 5)
        $mostViewedProducts = Product::where('tenant_id', $tenant->id)
            ->with('category')
            ->orderByDesc('views_count')
            ->orderByDesc('created_at')
            ->take(5)
            ->get();

        // 6. Pedidos Recientes (Top 5)
        $recentOrders = Order::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->take(5)
            ->get();

        // 7. Pedidos por Estado
        $ordersByStatus = Order::where('tenant_id', $tenant->id)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->all();

        // Asegurar que todos los estados posibles existan en la respuesta
        $statusCounts = [
            'pending'    => $ordersByStatus['pending'] ?? 0,
            'processing' => $ordersByStatus['processing'] ?? 0,
            'attended'   => $ordersByStatus['attended'] ?? 0,
            'cancelled'  => $ordersByStatus['cancelled'] ?? 0,
        ];

        return response()->json([
            'total_sales'          => (float) $totalSales,
            'total_orders'         => $totalOrders,
            'total_products'       => $totalProducts,
            'catalog_views'        => $catalogViews,
            'most_viewed_products' => $mostViewedProducts,
            'recent_orders'        => $recentOrders,
            'orders_by_status'     => $statusCounts,
        ]);
    }
}
