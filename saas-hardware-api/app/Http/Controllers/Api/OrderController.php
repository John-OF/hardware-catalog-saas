<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Notifications\OrderStatusChangedNotification;
use App\Services\OrderPricing;
use App\Support\Bitacora;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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

        // Búsqueda por cliente o por número de pedido
        if ($request->filled('search')) {
            $search = $request->search;

            // FUN-3: el número solo sirve si se puede buscar por él, que es lo
            // que hace el dueño cuando el cliente le dice "mi pedido es el 1042".
            // Se acepta con y sin almohadilla porque es como está escrito en el
            // mensaje de WhatsApp que el propio cliente reenvía.
            $numero = ltrim(trim($search), '#');
            $esNumero = ctype_digit($numero) && $numero !== '';

            $query->where(function ($q) use ($search, $numero, $esNumero) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");

                if ($esNumero) {
                    $q->orWhere('number', (int) $numero);
                }
            });
        }

        // Paginación
        $orders = $query->paginate(Paginacion::porPagina($request, 15));

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
            // FUN-2: también opcional aquí. La venta de mostrador NO manda correo
            // de confirmación —el cliente estaba delante y ya se lleva lo suyo,
            // así que un "recibimos tu pedido" sobra—, pero guardar el correo sirve
            // para lo que viene después: un encargo que se deja pendiente avisa
            // solo cuando pasa a listo.
            'customer_email' => 'nullable|email|max:200',
            'customer_note'  => 'nullable|string|max:1000',
            // 'cancelled' no tiene sentido al crear.
            'status'              => 'required|string|in:pending,processing,attended',
            'items'               => 'required|array|min:1|max:100',
            'items.*.product_id'  => 'required|uuid',
            'items.*.variant_id'  => 'nullable|uuid',
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
                'customer_email' => $data['customer_email'] ?? null,
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

        Bitacora::anotar(
            ActivityLog::PEDIDO_MOSTRADOR,
            "Registró la venta de mostrador #{$order->number} a nombre de {$order->customer_name} ({$this->etiquetaDeEstado($order->status)}).",
            ['pedido_id' => $order->id, 'numero' => $order->number, 'total' => $order->total],
        );

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

            // Fuera de la transacción a propósito: el cambio de estado ya está
            // guardado y no puede deshacerse porque falle un correo.
            $this->notifyCustomerOfStatusChange($order);

            Bitacora::anotar(
                ActivityLog::PEDIDO_ESTADO,
                "Pasó el pedido #{$order->number} de {$this->etiquetaDeEstado($oldStatus)} a {$this->etiquetaDeEstado($newStatus)}.",
                ['pedido_id' => $order->id, 'numero' => $order->number, 'antes' => $oldStatus, 'despues' => $newStatus],
            );
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

        Bitacora::anotar(
            ActivityLog::PEDIDO_BORRADO,
            "Borró el pedido #{$order->number} de {$order->customer_name} ({$this->etiquetaDeEstado($order->status)}"
                .($order->status === 'attended' ? ', se devolvió su stock' : '').').',
            ['pedido_id' => $order->id, 'numero' => $order->number, 'total' => $order->total, 'estado' => $order->status],
        );

        return response()->json(null, 204);
    }

    /** Cómo se llama cada estado en la bitácora (INF-3): lo lee el dueño, no el código. */
    private function etiquetaDeEstado(string $estado): string
    {
        return match ($estado) {
            'pending'    => 'pendiente',
            'processing' => 'en proceso',
            'attended'   => 'atendido',
            'cancelled'  => 'cancelado',
            default      => $estado,
        };
    }

    /**
     * Avisa al comprador de que su pedido cambió de estado (FUN-2).
     *
     * Tres condiciones, y ninguna es un detalle:
     *
     * 1. **Que haya dejado correo**, que es opcional en el checkout.
     * 2. **Que el estado sea de los que se avisan.** Volver a "pendiente" es una
     *    corrección interna del dueño; avisar de eso le diría al cliente que su
     *    pedido, que ya estaba listo, vuelve a estar pendiente.
     * 3. **Que el fallo no rompa nada.** El estado ya está guardado, así que un
     *    mailer caído no puede devolverle un error al dueño ni hacerle creer que
     *    el cambio no se aplicó. Mismo criterio que el aviso de pedido nuevo.
     */
    private function notifyCustomerOfStatusChange(Order $order): void
    {
        if (blank($order->customer_email)) {
            return;
        }

        $avisable = OrderStatusChangedNotification::mensajePorEstado(
            $order->status,
            '#'.$order->number,
            (string) ($order->tenant?->name ?? ''),
            (string) $order->total,
        );

        if ($avisable === null) {
            return;
        }

        try {
            Notification::route('mail', $order->customer_email)
                ->notify(new OrderStatusChangedNotification($order));
        } catch (\Throwable $e) {
            Log::error('No se pudo avisar al comprador del cambio de estado', [
                'order_id'  => $order->id,
                'tenant_id' => $order->tenant_id,
                'estado'    => $order->status,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Descuenta o devuelve el stock de las líneas de un pedido.
     *
     * Las líneas guardan un snapshot del producto, así que `product_id` puede
     * ser null si el artículo se borró del catálogo después de venderse: en ese
     * caso no hay stock que mover y la línea se salta.
     *
     * MOD-5: una línea de una variante mueve el stock de ESA variante y después
     * recalcula el resumen del producto. Se salta —sin mover nada— en dos casos
     * en los que no hay dónde devolverlo con sentido: la variante se borró
     * (queda su nombre pero no su id), o la línea es de antes de que el producto
     * tuviera variantes (el stock del producto ya es solo un resumen, y el
     * próximo cálculo pisaría lo que se le sumara a mano).
     *
     * Con el modelo (`increment()` de una instancia) y no con una consulta suelta,
     * para que salten los eventos: el aviso de "ya llegó" al devolver stock de un
     * pedido cancelado.
     */
    private function applyStockDelta(Order $order, bool $decrement): void
    {
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            if ($item->variant_id) {
                $variante = ProductVariant::find($item->variant_id);

                if (! $variante) {
                    continue;
                }

                $decrement
                    ? $variante->decrement('stock', $item->quantity)
                    : $variante->increment('stock', $item->quantity);

                $variante->product?->sincronizarResumenDeVariantes();

                continue;
            }

            if ($item->variant_name !== null) {
                continue;
            }

            $producto = Product::withCount('variants')->find($item->product_id);

            if (! $producto || $producto->variants_count > 0) {
                continue;
            }

            $decrement
                ? $producto->decrement('stock', $item->quantity)
                : $producto->increment('stock', $item->quantity);
        }
    }
}
