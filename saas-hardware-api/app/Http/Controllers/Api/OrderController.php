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
        if ($request->has('status') && in_array($request->status, ['pending', 'processing', 'attended', 'cancelled'])) {
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
     * Actualiza el estado de un pedido (pending -> attended / cancelled) y ajusta stock.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:pending,processing,attended,cancelled',
        ]);

        $oldStatus = $order->status;
        $newStatus = $data['status'];

        if ($oldStatus !== $newStatus) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($order, $oldStatus, $newStatus) {
                // Actualizar estado del pedido
                $order->update([
                    'status' => $newStatus,
                ]);

                // Si pasa a "attended" (atendido) desde cualquier otro estado -> descontar stock
                if ($newStatus === 'attended' && $oldStatus !== 'attended') {
                    foreach ($order->items as $item) {
                        if ($item->product_id) {
                            \App\Models\Product::where('id', $item->product_id)
                                ->decrement('stock', $item->quantity);
                        }
                    }
                }

                // Si sale de "attended" hacia cualquier otro estado (ej: cancelado o revertido) -> devolver stock
                if ($oldStatus === 'attended' && $newStatus !== 'attended') {
                    foreach ($order->items as $item) {
                        if ($item->product_id) {
                            \App\Models\Product::where('id', $item->product_id)
                                ->increment('stock', $item->quantity);
                        }
                    }
                }
            });
        }

        return response()->json($order->load('items'));
    }

    /**
     * Elimina un pedido del historial y devuelve stock si estaba atendido.
     */
    public function destroy(Order $order): JsonResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($order) {
            if ($order->status === 'attended') {
                foreach ($order->items as $item) {
                    if ($item->product_id) {
                        \App\Models\Product::where('id', $item->product_id)
                            ->increment('stock', $item->quantity);
                    }
                }
            }
            $order->delete();
        });
        return response()->json(null, 204);
    }
}
