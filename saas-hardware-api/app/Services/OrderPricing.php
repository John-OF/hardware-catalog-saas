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
     * @param  array<int, array{product_id: string, variant_id?: string|null, quantity: int}>  $items
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

        // AUD-5: `status` va junto a `is_active` porque el catalogo publico exige
        // los dos para mostrar un producto, y aqui solo se miraba el primero. El
        // carrito persiste en el navegador: sin esto, el dueno pasa un producto a
        // borrador para dejar de venderlo y le sigue entrando el pedido de quien
        // lo tenia guardado, al precio viejo. En el panel se deja como estaba: el
        // dueno vende de mostrador lo que tenga fisicamente aunque lo haya
        // despublicado, que es justo para lo que existe `soloVisibles`.
        $products = Product::where('tenant_id', $tenant->id)
            ->when($soloVisibles, fn ($q) => $q->where('is_active', true)->where('status', 'published'))
            ->whereIn('id', $productIds)
            ->with('variants')
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

            $variantId = $item['variant_id'] ?? null;
            $variante = null;

            // MOD-5: con variantes, lo que se vende es una de ellas, y precio y
            // stock son suyos. El precio del producto es solo el resumen ("desde"),
            // asi que cobrarlo seria cobrar la variante mas barata por cualquiera.
            if ($product->variants->isNotEmpty()) {
                if (! $variantId) {
                    throw ValidationException::withMessages([
                        'items' => ["Elige una opción de \"{$product->name}\" antes de pedirlo."],
                    ]);
                }

                // Solo entre las variantes de ESTE producto: un id de variante de
                // otro producto -o de otra tienda- no se acepta aunque exista.
                $variante = $product->variants->firstWhere('id', $variantId);

                if (! $variante) {
                    throw ValidationException::withMessages([
                        'items' => ["La opción elegida de \"{$product->name}\" ya no está disponible. Actualiza tu carrito."],
                    ]);
                }
            } elseif ($variantId) {
                // El carrito guardo una variante que ya no existe porque el dueño
                // le quito las variantes al producto: el precio que vio ya no es
                // el de nada. Mejor pedir que lo revise que cobrar otro.
                throw ValidationException::withMessages([
                    'items' => ["\"{$product->name}\" cambió desde que lo agregaste. Actualiza tu carrito."],
                ]);
            }

            // El precio que vale es el de oferta cuando existe: es el que ve el
            // comprador en el catalogo.
            $unitPrice = $variante
                ? $variante->precioVisible()
                : ($product->sale_price !== null ? (float) $product->sale_price : (float) $product->price);
            $subtotal = round($unitPrice * $item['quantity'], 2);
            $total += $subtotal;

            $lines[] = [
                'product_id'   => $product->id,
                'variant_id'   => $variante?->id,
                // Snapshot del nombre: el producto puede renombrarse o borrarse
                // despues y el historial debe seguir contando lo que se vendio.
                'product_name' => $product->name,
                'variant_name' => $variante?->nombre,
                'unit_price'   => $unitPrice,
                'quantity'     => $item['quantity'],
                'subtotal'     => $subtotal,
            ];
        }

        return ['lines' => $lines, 'total' => round($total, 2)];
    }
}
