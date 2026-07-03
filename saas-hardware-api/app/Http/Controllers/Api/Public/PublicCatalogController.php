<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PublicCatalogController extends Controller
{
    public function tenant(string $slug): JsonResponse
    {
        $tenant = Cache::remember("tenant:{$slug}", 300, function () use ($slug) {
            return Tenant::where('slug', $slug)
                ->where('is_active', true)
                ->select(['id', 'slug', 'name', 'logo_url', 'primary_color', 'theme', 'whatsapp_number'])
                ->firstOrFail()
                ->toArray();
        });

        return response()->json($tenant);
    }

    public function products(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        // Obtener la versión de caché actual para el tenant (soporte de invalidación en driver file)
        $version = Cache::remember("tenant:{$slug}:cache_version", 86400, fn() => 1);

        // IMPORTANTE: usar only() para prevenir cache flooding con parámetros arbitrarios
        $cacheKey = "catalog:{$slug}:v{$version}:" . md5(json_encode($request->only([
            'category_id', 'search', 'in_stock', 'specs', 'page'
        ])));

        $products = Cache::remember($cacheKey, 300, function () use ($tenant, $request) {
            return Product::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->with(['category:id,name,icon', 'images'])
                ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
                ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
                ->when($request->in_stock, fn($q) => $q->where('stock', '>', 0))
                ->when($request->specs, function ($q) use ($request) {
                    foreach ($request->specs as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $q->where('specs->' . $key, $value);
                        }
                    }
                })
                ->orderByDesc('created_at')
                ->paginate(24)
                ->toArray();
        });

        return response()->json($products);
    }

    public function product(string $slug, string $productId): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $product = Product::where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->where('is_active', true)
            ->with(['category', 'images'])
            ->firstOrFail();

        return response()->json($product);
    }

    public function categories(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $categories = Cache::remember("tenant:{$slug}:public_categories", 300, function () use ($tenant) {
            return \App\Models\Category::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->toArray();
        });

        return response()->json($categories);
    }

    /**
     * Crea una solicitud de pedido desde el catálogo público.
     * El cierre real se hace por WhatsApp; aquí solo se registra el pedido.
     */
    public function storeOrder(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $data = $request->validate([
            'customer_name'      => 'required|string|max:200',
            'customer_phone'     => 'required|string|max:30',
            'customer_note'      => 'nullable|string|max:1000',
            'items'              => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|uuid',
            'items.*.quantity'   => 'required|integer|min:1|max:999',
        ]);

        // Cargar todos los productos solicitados de una vez, scopeados al tenant
        // y solo activos. NO confiamos en el precio que envía el cliente.
        $productIds = collect($data['items'])->pluck('product_id')->unique();
        $products = Product::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lineItems = [];
        $total = 0;
        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']);
            if (!$product) {
                // Algún producto ya no existe o fue desactivado.
                return response()->json([
                    'message' => 'Uno de los productos ya no está disponible. Actualiza tu carrito.',
                ], 422);
            }

            $actualPrice = $product->sale_price !== null ? (float) $product->sale_price : (float) $product->price;
            $subtotal = round($actualPrice * $item['quantity'], 2);
            $total += $subtotal;

            $lineItems[] = [
                'product_id'   => $product->id,
                'product_name' => $product->name,
                'unit_price'   => $actualPrice,
                'quantity'     => $item['quantity'],
                'subtotal'     => $subtotal,
            ];
        }

        $order = DB::transaction(function () use ($tenant, $data, $lineItems, $total) {
            $order = Order::create([
                'tenant_id'      => $tenant->id,
                'customer_name'  => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_note'  => $data['customer_note'] ?? null,
                'status'         => 'pending',
                'total'          => $total,
            ]);

            $order->items()->createMany($lineItems);

            return $order;
        });

        return response()->json($order->load('items'), 201);
    }
}
