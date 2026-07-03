<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Devuelve la lista de pedidos del tenant actual.
     * Admite búsqueda por nombre de cliente y filtro por estado.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with('items')
            ->withCount('items')
            ->orderBy('created_at', 'desc');

        // Filtro por estado
        if ($request->has('status') && in_array($request->status, ['pending', 'attended', 'cancelled'])) {
            $query->where('status', $request->status);
        }

        // Búsqueda por cliente
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        // Paginación
        $orders = $query->paginate($request->integer('per_page', 15));

        return response()->json($orders);
    }

    /**
     * Devuelve el detalle completo de un pedido con sus productos.
     */
    public function show(Order $order): JsonResponse
    {
        return response()->json($order->load('items'));
    }

    /**
     * Actualiza el estado de un pedido (pending -> attended / cancelled).
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:pending,attended,cancelled',
        ]);

        $order->update([
            'status' => $data['status'],
        ]);

        return response()->json($order->load('items'));
    }

    /**
     * Elimina un pedido del historial.
     */
    public function destroy(Order $order): JsonResponse
    {
        $order->delete();
        return response()->json(null, 204);
    }
}
