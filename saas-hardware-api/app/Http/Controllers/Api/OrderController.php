<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * Registra una venta de mostrador desde el panel (OWN-3).
     *
     * El README prometía "registro de pedidos" pero este método no existía,
     * así que la ruta POST que registra `apiResource` devolvía un 500. Las
     * ventas presenciales no descontaban stock por ninguna vía y el inventario
     * del sistema se iba separando del real.
     */
    public function store(Request $request, OrderPricing $pricing): JsonResponse
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'customer_name'  => 'required|string|max:200',
            // Opcional, al revés que en el checkout público: en el mostrador el
            // cliente paga y se va, y a menudo no deja teléfono.
            'customer_phone' => 'nullable|string|max:30',
            'customer_note'  => 'nullable|string|max:1000',
            // 'cancelled' no tiene sentido al crear.
            'status'              => 'required|string|in:pending,processing,attended',
            'items'               => 'required|array|min:1|max:100',
            'items.*.product_id'  => 'required|uuid',
            'items.*.quantity'    => 'required|integer|min:1|max:999',
        ], [
            'customer_name.required' => 'Escribe a nombre de quién va la venta.',
            'items.required'         => 'Agrega al menos un producto.',
            'items.min'              => 'Agrega al menos un producto.',
        ]);

        // `soloVisibles: false`: el dueño vende lo que tiene físicamente, aunque
        // el producto esté despublicado o desactivado en el catálogo.
        ['lines' => $lineItems, 'total' => $total] = $pricing->build($tenant, $data['items'], soloVisibles: false);

        $order = DB::transaction(function () use ($tenant, $data, $lineItems, $total) {
            $order = Order::create([
                'tenant_id'      => $tenant->id,
                'customer_name'  => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_note'  => $data['customer_note'] ?? null,
                'status'         => $data['status'],
                'total'          => $total,
            ]);

            $order->items()->createMany($lineItems);

            // Una venta de mostrador ya salió del almacén, así que si se crea
            // como atendida el stock se descuenta aquí mismo. Es el mismo
            // criterio que aplica `update` al pasar un pedido a 'attended'.
            if ($data['status'] === 'attended') {
                $this->applyStockDelta($order, decrement: true);
            }

            return $order;
        });

        return response()->json($order->load('items'), 201);
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
            DB::transaction(function () use ($order, $oldStatus, $newStatus) {
                // Actualizar estado del pedido
                $order->update([
                    'status' => $newStatus,
                ]);

                // Si pasa a "attended" (atendido) desde cualquier otro estado -> descontar stock
                if ($newStatus === 'attended' && $oldStatus !== 'attended') {
                    $this->applyStockDelta($order, decrement: true);
                }

                // Si sale de "attended" hacia cualquier otro estado (ej: cancelado o revertido) -> devolver stock
                if ($oldStatus === 'attended' && $newStatus !== 'attended') {
                    $this->applyStockDelta($order, decrement: false);
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
        DB::transaction(function () use ($order) {
            if ($order->status === 'attended') {
                $this->applyStockDelta($order, decrement: false);
            }
            $order->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * Descuenta o devuelve el stock de las líneas de un pedido.
     *
     * Las líneas guardan un snapshot del producto, así que `product_id` puede
     * ser null si el artículo se borró del catálogo después de venderse: en ese
     * caso no hay stock que mover y la línea se salta.
     */
    private function applyStockDelta(Order $order, bool $decrement): void
    {
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $query = Product::where('id', $item->product_id);

            $decrement
                ? $query->decrement('stock', $item->quantity)
                : $query->increment('stock', $item->quantity);
        }
    }
}
