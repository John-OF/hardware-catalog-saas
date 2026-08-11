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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublicCatalogController extends Controller
{
    public function resolveDomain(Request $request): JsonResponse
    {
        $domain = $request->query('domain') ?? $request->getHost();

        $tenant = Tenant::where('custom_domain', $domain)
            ->where('is_active', true)
            ->first();

        if (!$tenant) {
            return response()->json(['message' => 'No se encontró ninguna tienda asociada a este dominio.'], 404);
        }

        return response()->json($tenant);
    }

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

        // Increment catalog views count
        $tenant->increment('views_count');

        // Obtener la versión de caché actual para el tenant (soporte de invalidación en driver file)
        $version = Cache::remember("tenant:{$slug}:cache_version", 86400, fn() => 1);

        // IMPORTANTE: usar only() para prevenir cache flooding con parámetros arbitrarios
        $cacheKey = "catalog:{$slug}:v{$version}:" . md5(json_encode($request->only([
            'category_id', 'search', 'in_stock', 'specs', 'page', 'sort'
        ])));

        $products = Cache::remember($cacheKey, 300, function () use ($tenant, $request) {
            return Product::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where('status', 'published')
                ->with(['category:id,name,icon', 'images'])
                ->withAvg(['reviews' => fn($q) => $q->where('is_approved', true)], 'rating')
                ->withCount(['reviews' => fn($q) => $q->where('is_approved', true)])
                ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
                // El comprador busca tanto por modelo como por marca ("Kingston"),
                // asi que el termino se prueba contra name, brand y sku.
                // Los OR van agrupados en su propio closure a proposito: sueltos se
                // mezclarian con los where de tenant_id/is_active/status y la busqueda
                // acabaria mostrando productos de otras tiendas o despublicados.
                ->when($request->search, function ($q) use ($request) {
                    $termino = '%' . $request->search . '%';

                    $q->where(function ($sub) use ($termino) {
                        $sub->where('name', 'like', $termino)
                            ->orWhere('brand', 'like', $termino)
                            ->orWhere('sku', 'like', $termino);
                    });
                })
                ->when($request->in_stock, fn($q) => $q->where('stock', '>', 0))
                ->when($request->specs, function ($q) use ($request) {
                    foreach ($request->specs as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $q->where('specs->' . $key, $value);
                        }
                    }
                })
                ->tap(fn ($q) => $this->applyCatalogSort($q, $request->query('sort')))
                ->paginate(24)
                ->toArray();
        });

        return response()->json($products);
    }

    /**
     * Ordena el catálogo público según la preferencia del comprador (PUB-1).
     *
     * El valor llega por query string, así que se resuelve contra una whitelist:
     * nada de lo que escriba el visitante entra en el SQL.
     *
     * Sin `sort` (o con uno desconocido) se mantiene el orden manual que el dueño
     * definió arrastrando productos (`sort_order`), que es el de siempre.
     */
    private function applyCatalogSort(\Illuminate\Database\Eloquent\Builder $query, ?string $sort): void
    {
        // El precio que ve el comprador es el de oferta cuando existe, así que se
        // ordena por ese mismo valor y no por `price` a secas.
        $precioVisible = 'COALESCE(sale_price, price)';

        match ($sort) {
            'price_asc'  => $query->orderByRaw("{$precioVisible} ASC"),
            'price_desc' => $query->orderByRaw("{$precioVisible} DESC"),
            'newest'     => $query->orderByDesc('created_at'),
            'name'       => $query->orderBy('name'),
            default      => $query->orderBy('sort_order')->orderByDesc('created_at'),
        };

        // Desempate estable: sin esto, dos productos al mismo precio pueden
        // intercambiarse entre páginas y aparecer repetidos o desaparecer al paginar.
        // Los id son UUID v7 (cronológicos), así que en "más recientes" se desempata
        // al revés: un lote importado por CSV comparte created_at al segundo y sin
        // esto saldría del más antiguo al más nuevo.
        if ($sort !== null && $sort !== '') {
            $sort === 'newest' ? $query->orderByDesc('id') : $query->orderBy('id');
        }
    }

    public function product(Request $request, string $slug, string $productId): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $product = Product::where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->where('is_active', true)
            ->where('status', 'published')
            ->with(['category', 'images', 'reviews' => fn($q) => $q->where('is_approved', true)->orderByDesc('created_at')])
            ->withAvg(['reviews' => fn($q) => $q->where('is_approved', true)], 'rating')
            ->withCount(['reviews' => fn($q) => $q->where('is_approved', true)])
            ->firstOrFail();

        // Increment product views count
        $product->increment('views_count');

        // Check if the current user or visitor has already reviewed this product
        $user = $request->user('sanctum');
        $visitorId = $request->header('X-Visitor-Id') ?? $request->query('visitor_id');
        $userReview = null;

        if ($user || $visitorId) {
            $userReview = \App\Models\Review::where('product_id', $product->id)
                ->where(function ($q) use ($user, $visitorId) {
                    if ($user) {
                        $q->where('user_id', $user->id);
                    }
                    if ($visitorId) {
                        $q->orWhere('visitor_id', $visitorId);
                    }
                })
                ->first();
        }

        // Algoritmo básico de productos relacionados (Cross-selling)
        $relatedQuery = Product::where('tenant_id', $tenant->id)
            ->where('id', '!=', $product->id)
            ->where('is_active', true)
            ->where('status', 'published')
            ->with(['category', 'images']);

        $categoryName = strtolower($product->category?->name ?? '');

        // Identificar palabras clave en el nombre o specs para compatibilidad
        $specsString = json_encode($product->specs ?? []);
        $productString = strtolower($product->name . ' ' . $specsString);

        $socket = null;
        if (preg_match('/\b(am5|am4|lga1700|1700|lga1200|1200|lga1151|1151)\b/i', $productString, $matches)) {
            $socket = $matches[1];
        }

        $ramType = null;
        if (preg_match('/\b(ddr5|ddr4)\b/i', $productString, $matches)) {
            $ramType = $matches[1];
        }

        // Definir categorías sugeridas complementarias según el tipo de producto
        $targetCategoryNames = [];
        if (str_contains($categoryName, 'procesador') || str_contains($categoryName, 'processor')) {
            $targetCategoryNames = ['placas madre', 'memoria ram'];
        } elseif (str_contains($categoryName, 'placa') || str_contains($categoryName, 'madre') || str_contains($categoryName, 'motherboard')) {
            $targetCategoryNames = ['procesadores', 'memoria ram'];
        } elseif (str_contains($categoryName, 'tarjeta') || str_contains($categoryName, 'video') || str_contains($categoryName, 'gpu')) {
            $targetCategoryNames = ['fuentes de poder', 'gabinetes'];
        } elseif (str_contains($categoryName, 'fuente') || str_contains($categoryName, 'poder') || str_contains($categoryName, 'power')) {
            $targetCategoryNames = ['tarjetas de video', 'procesadores'];
        } elseif (str_contains($categoryName, 'gabinete') || str_contains($categoryName, 'case')) {
            $targetCategoryNames = ['fuentes de poder', 'enfriamiento'];
        } elseif (str_contains($categoryName, 'ram') || str_contains($categoryName, 'memoria')) {
            $targetCategoryNames = ['placas madre', 'procesadores'];
        } elseif (str_contains($categoryName, 'almacenamiento') || str_contains($categoryName, 'ssd') || str_contains($categoryName, 'disco')) {
            $targetCategoryNames = ['placas madre', 'procesadores'];
        }

        // Obtener sugerencias complementarias
        $complementaryProducts = collect();
        if (!empty($targetCategoryNames)) {
            $complementaryProducts = (clone $relatedQuery)
                ->whereHas('category', function ($q) use ($targetCategoryNames) {
                    $q->where(function ($sub) use ($targetCategoryNames) {
                        foreach ($targetCategoryNames as $name) {
                            $sub->orWhere('name', 'like', "%{$name}%");
                        }
                    });
                })
                ->get();
        }

        // Ordenar complementarios que compartan socket o tipo de memoria al inicio
        if ($socket || $ramType) {
            $complementaryProducts = $complementaryProducts->sortByDesc(function ($p) use ($socket, $ramType) {
                $pSpecs = json_encode($p->specs ?? []);
                $pString = strtolower($p->name . ' ' . $pSpecs);
                $score = 0;
                if ($socket && str_contains($pString, strtolower($socket))) {
                    $score += 10;
                }
                if ($ramType && str_contains($pString, strtolower($ramType))) {
                    $score += 5;
                }
                return $score;
            });
        }

        // Obtener sugerencias de la misma categoría (alternativos)
        $alternativeProducts = (clone $relatedQuery)
            ->where('category_id', $product->category_id)
            ->limit(4)
            ->get();

        // Mezclar ambos y rellenar con destacados (más vistos) si falta cubrir la cuota de 6
        $related = $complementaryProducts->merge($alternativeProducts);

        if ($related->count() < 6) {
            $fallbacks = (clone $relatedQuery)
                ->orderByDesc('views_count')
                ->limit(6)
                ->get();
            $related = $related->merge($fallbacks);
        }

        $relatedList = $related->unique('id')->take(6)->values();

        $productArray = $product->toArray();
        $productArray['user_review'] = $userReview;
        $productArray['related_products'] = $relatedList->toArray();

        return response()->json($productArray);
    }

    public function storeReview(Request $request, string $slug, string $productId): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $product = Product::where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->where('is_active', true)
            ->where('status', 'published')
            ->firstOrFail();

        $data = $request->validate([
            'customer_name'   => 'required|string|max:150',
            'customer_email'  => 'nullable|email|max:150',
            'customer_phone'  => 'nullable|string|max:30',
            'rating'          => 'required|integer|min:1|max:5',
            'comment'         => 'nullable|string|max:1000',
            'visitor_id'      => 'nullable|string|max:100',
            'turnstile_token' => 'required|string',
        ]);

        // Capa 1: Validación de Turnstile
        if ($fallo = $this->verifyTurnstile($data['turnstile_token'], $request->ip())) {
            return $fallo;
        }

        // Capa 2: Control de identidad (evitar dobles reseñas)
        $user = $request->user('sanctum');
        $visitorId = $data['visitor_id'] ?? $request->header('X-Visitor-Id');

        $existingReview = \App\Models\Review::where('product_id', $product->id)
            ->where(function ($q) use ($user, $visitorId) {
                if ($user) {
                    $q->where('user_id', $user->id);
                }
                if ($visitorId) {
                    $q->orWhere('visitor_id', $visitorId);
                }
            })
            ->first();

        if ($existingReview) {
            return response()->json(['message' => 'Ya has enviado una reseña para este producto.'], 422);
        }

        // Capa 3: Estado Inteligente (Compra Verificada & Auto-aprobación)
        $verifiedPurchase = false;
        $customerPhone = $data['customer_phone'] ?? null;

        if ($customerPhone) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $customerPhone);
            
            if (!empty($cleanPhone)) {
                $verifiedPurchase = \App\Models\Order::where('tenant_id', $tenant->id)
                    ->where('status', 'attended')
                    ->where(function ($q) use ($customerPhone, $cleanPhone) {
                        $q->where('customer_phone', $customerPhone)
                          ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ?", ["%{$cleanPhone}"]);
                    })
                    ->whereHas('items', function ($iq) use ($product) {
                        $iq->where('product_id', $product->id);
                    })
                    ->exists();
            }
        }

        if ($user) {
            // Caso A: Usuario Logueado
            $isApproved = true;
        } elseif ($verifiedPurchase) {
            // Caso B: Compra Verificada (Anónimo)
            $isApproved = true;
        } else {
            // Caso C: Anónimo Puro (Sin historial de compra)
            $isApproved = false;
        }

        $review = $product->reviews()->create([
            'tenant_id'         => $tenant->id,
            'user_id'           => $user ? $user->id : null,
            'visitor_id'        => $user ? null : $visitorId,
            'customer_name'     => $data['customer_name'],
            'customer_email'    => $data['customer_email'] ?? null,
            'rating'            => $data['rating'],
            'comment'           => $data['comment'] ?? null,
            'verified_purchase' => $verifiedPurchase,
            'is_approved'       => $isApproved,
        ]);

        $message = $isApproved
            ? '¡Reseña publicada con éxito!'
            : 'Tu reseña ha sido enviada. Se mostrará en el catálogo una vez aprobada por la tienda.';

        return response()->json([
            'review'      => $review,
            'message'     => $message,
            'is_approved' => $isApproved
        ], 201);
    }

    /**
     * Verifica el token anti-bot de Turnstile contra Cloudflare (SEC-5).
     *
     * Devuelve null si la resenia puede continuar, o la respuesta de error a
     * devolver al cliente. La regla de fondo es no aprobar nunca por defecto:
     * fuera de local, cualquier duda (sin clave, error de red) se rechaza.
     */
    private function verifyTurnstile(string $token, ?string $ip): ?JsonResponse
    {
        // config() y no env(): con config:cache activo, env() devuelve null en
        // runtime y antes se caia a la clave de prueba de Cloudflare, que aprueba
        // cualquier token; es decir, produccion se quedaba sin anti-bot.
        $secretKey = config('services.turnstile.secret');
        $esLocal = app()->isLocal();

        if (blank($secretKey)) {
            if (!$esLocal) {
                Log::error('TURNSTILE_SECRET_KEY no configurada; se rechaza la reseña.');

                return response()->json(['message' => 'Error al verificar protección anti-bot.'], 502);
            }

            Log::info('Turnstile sin clave configurada: se omite la verificación en local.');

            return null;
        }

        try {
            $http = Http::asForm();

            // La verificacion TLS solo se relaja en local, donde Laragon/Windows
            // suele no traer configurado el bundle de CA. En produccion tiene que
            // quedar activa o el token viaja interceptable.
            if ($esLocal) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            if (!$response->json('success')) {
                Log::warning('Turnstile verification failed', [
                    'response' => $response->json(),
                    'ip'       => $ip,
                ]);

                return response()->json(['message' => 'Validación anti-bot (Turnstile) fallida. Recarga e inténtalo de nuevo.'], 422);
            }
        } catch (\Exception $e) {
            Log::error('Turnstile connection exception', ['error' => $e->getMessage()]);

            // En local dejamos pasar ante un fallo de red para no trabar las pruebas.
            if (!$esLocal) {
                return response()->json(['message' => 'Error al verificar protección anti-bot.'], 502);
            }

            Log::info('Bypassing Turnstile in local environment due to connection error.');
        }

        return null;
    }

    /**
     * "Avísame cuando llegue": el cliente deja su contacto para un producto agotado.
     * Al reponer stock, el modelo Product notifica a los interesados.
     */
    public function storeStockNotification(Request $request, string $slug, string $productId): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $product = Product::where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->where('is_active', true)
            ->where('status', 'published')
            ->firstOrFail();

        $data = $request->validate([
            'customer_name'    => 'required|string|max:150',
            'customer_contact' => 'required|string|max:150', // teléfono o email
        ]);

        // Solo tiene sentido si el producto está agotado
        if ($product->stock > 0) {
            return response()->json([
                'message' => 'Este producto ya está disponible. ¡Puedes pedirlo ahora!',
            ], 422);
        }

        // Registro idempotente: si ya estaba anotado (y aún sin avisar), no duplicar
        $notification = \App\Models\StockNotification::firstOrCreate(
            [
                'product_id'       => $product->id,
                'customer_contact' => $data['customer_contact'],
            ],
            [
                'tenant_id'     => $tenant->id,
                'customer_name' => $data['customer_name'],
            ]
        );

        // Si un aviso previo ya fue enviado y el cliente se reinscribe, reabrir el interés
        if (! $notification->wasRecentlyCreated && $notification->notified_at !== null) {
            $notification->update([
                'customer_name' => $data['customer_name'],
                'notified_at'   => null,
            ]);
        }

        return response()->json([
            'message' => '¡Listo! Te avisaremos cuando este producto vuelva a estar disponible.',
        ], 201);
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

        $userId = auth('sanctum')->id();

        $order = DB::transaction(function () use ($tenant, $data, $lineItems, $total, $userId) {
            $order = Order::create([
                'tenant_id'      => $tenant->id,
                'user_id'        => $userId,
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

    public function pages(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $pages = \App\Models\Page::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->select(['id', 'title', 'slug'])
            ->get();

        return response()->json($pages);
    }

    public function pageDetail(string $slug, string $pageSlug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $page = \App\Models\Page::where('tenant_id', $tenant->id)
            ->where('slug', $pageSlug)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json($page);
    }
}
