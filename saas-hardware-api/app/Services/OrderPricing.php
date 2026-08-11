<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Arma las lineas de un pedido y calcula su total (OWN-3).
 *
 * Vive aparte porque lo usan dos caminos distintos: el checkout publico
 * (PublicCatalogController::storeOrder) y la venta de mostrador del panel
 * (OrderController::store). Lo que NO puede diferir entre ambos es que el
 * precio y el total se calculan en el servidor: lo que mande el cliente sobre
 * cuanto cuesta algo se ignora.
 */
class OrderPricing
{
    /**
     * @param  array<int, array{product_id: string, quantity: int}>  $items
     * @param  bool  $soloVisibles  true en el catalogo publico (solo productos
     *                              activos); false en el panel, donde el dueño
     *                              vende lo que tenga fisicamente aunque lo
     *                              haya despublicado.
     * @return array{lines: array<int, array<string, mixed>>, total: float}
     *
     * @throws ValidationException si algun producto no pertenece al tenant o ya no esta disponible
     */
    public function build(Tenant $tenant, array $items, bool $soloVisibles): array
    {
        // Una sola consulta para todos los productos, siempre scopeada al tenant.
        $productIds = collect($items)->pluck('product_id')->unique();

        $products = Product::where('tenant_id', $tenant->id)
            ->when($soloVisibles, fn ($q) => $q->where('is_active', true))
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lines = [];
        $total = 0;

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => [$soloVisibles
                        ? 'Uno de los productos ya no está disponible. Actualiza tu carrito.'
                        : 'Uno de los productos seleccionados ya no existe en tu inventario.'],
                ]);
            }

            // El precio que vale es el de oferta cuando existe: es el que ve el
            // comprador en el catalogo.
            $unitPrice = $product->sale_price !== null ? (float) $product->sale_price : (float) $product->price;
            $subtotal = round($unitPrice * $item['quantity'], 2);
            $total += $subtotal;

            $lines[] = [
                'product_id'   => $product->id,
                // Snapshot del nombre: el producto puede renombrarse o borrarse
                // despues y el historial debe seguir contando lo que se vendio.
                'product_name' => $product->name,
                'unit_price'   => $unitPrice,
                'quantity'     => $item['quantity'],
                'subtotal'     => $subtotal,
            ];
        }

        return ['lines' => $lines, 'total' => round($total, 2)];
    }
}
