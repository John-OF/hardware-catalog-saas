<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Services\OrderPricing;
use App\Services\ViewCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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
                ->select(['id', 'slug', 'name', 'logo_url', 'primary_color', 'theme', 'whatsapp_number', 'currency'])
                ->firstOrFail()
                ->toArray();
        });

        return response()->json($tenant);
    }

    public function products(Request $request, string $slug, ViewCounter $vistas): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        // Visita del catalogo. Antes esto era un increment() directo, es decir un
        // UPDATE por peticion sobre la misma fila y ademas antes de mirar la
        // cache; ahora se acumula y se vuelca cada pocos minutos (AUD-2).
        $vistas->record('tenants', $tenant->id);

        // Obtener la versión de caché actual para el tenant (soporte de invalidación en driver file)
        $version = Cache::remember("tenant:{$slug}:cache_version", 86400, fn() => 1);

        // AUD-7: los filtros de spec se contrastan contra las specs que existen
        // de verdad ANTES de tocar nada más. Ver `specsFiltrables()`.
        $specs = $this->specsFiltrables($tenant, $slug, $version, $request->input('specs'));

        // IMPORTANTE: usar only() para prevenir cache flooding con parámetros
        // arbitrarios. `specs` va aparte y ya saneado: si entrara en crudo, la
        // clave de caché seguiría siendo libre y el flooding también.
        $criterios = $request->only(['category_id', 'search', 'in_stock', 'page', 'sort']);
        $criterios['specs'] = $specs;

        $cacheKey = "catalog:{$slug}:v{$version}:" . md5(json_encode($criterios));

        $products = Cache::remember($cacheKey, 300, function () use ($tenant, $request, $specs) {
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
                ->when($specs, function ($q) use ($specs) {
                    foreach ($specs as $key => $value) {
                        $q->where('specs->' . $key, $value);
                    }
                })
                ->tap(fn ($q) => $this->applyCatalogSort($q, $request->query('sort')))
                ->paginate(24)
                ->toArray();
        });

        return response()->json($products);
    }

    /**
     * Valores de especificación disponibles en todo el catálogo (PUB-2).
     *
     * El frontend armaba los filtros con los 24 productos de la página visible,
     * así que las opciones cambiaban al paginar y no representaban el
     * inventario: justo el filtro estrella de una tienda de componentes.
     *
     * La agregación se hace en PHP y no en SQL a propósito: `specs` es una
     * columna JSON y sacar sus claves con SQL portable entre MySQL y SQLite
     * (los tests) obliga a funciones distintas en cada motor. Se trae una sola
     * columna y el resultado va cacheado con la misma versión que el catálogo,
     * que ya se incrementa al guardar cualquier producto.
     */
    public function facets(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $version = Cache::remember("tenant:{$slug}:cache_version", 86400, fn () => 1);
        $categoryId = $request->query('category_id');

        return response()->json(['specs' => $this->facetsDelCatalogo($tenant, $slug, $version, $categoryId)]);
    }

    /**
     * Specs reales del catálogo, cacheadas. Las usan dos sitios: la respuesta de
     * `facets()` y la validación del filtro en `products()` (AUD-7).
     *
     * @return array<string, array<int, string>>
     */
    private function facetsDelCatalogo(Tenant $tenant, string $slug, int $version, ?string $categoryId): array
    {
        $cacheKey = "facets:{$slug}:v{$version}:".md5((string) $categoryId);

        return Cache::remember($cacheKey, 300, function () use ($tenant, $categoryId) {
            $listas = Product::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where('status', 'published')
                ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
                ->whereNotNull('specs')
                ->pluck('specs');

            $agrupadas = [];

            foreach ($listas as $specs) {
                if (! is_array($specs)) {
                    continue;
                }

                foreach ($specs as $clave => $valor) {
                    $clave = trim((string) $clave);
                    $valor = trim((string) $valor);

                    if ($clave === '' || $valor === '') {
                        continue;
                    }

                    // Se acumula como VALOR y no como clave: PHP convierte las
                    // claves numéricas a int, así que una spec como
                    // "Cores: 24" volvería como número y dejaría de casar con
                    // el filtro, que compara contra texto.
                    $agrupadas[$clave][] = $valor;
                }
            }

            $resultado = [];

            foreach ($agrupadas as $clave => $valores) {
                $valores = array_unique($valores);

                // Orden natural para que "8GB, 16GB, 32GB" no salga como
                // "16GB, 32GB, 8GB", que es lo que hace un sort alfabético.
                natcasesort($valores);

                // Tope defensivo: una spec de texto libre (una descripción
                // metida como spec) podría traer miles de valores distintos y
                // reventar la respuesta.
                $resultado[$clave] = array_slice(array_values($valores), 0, 60);
            }

            ksort($resultado);

            return $resultado;
        });
    }

    /**
     * Deja pasar solo los filtros de spec que existen de verdad en el catálogo
     * (AUD-7).
     *
     * Antes, la clave que mandara el visitante entraba tal cual en la ruta JSON
     * de la consulta: `?specs[a"]=x` devolvía un **500** (`Invalid JSON path
     * expression`) sin autenticación y llenando el log. No había inyección —el
     * valor va parametrizado y Laravel escapa lo demás—, pero un 500 gratis es
     * un 500 gratis.
     *
     * Y hay un segundo efecto menos visible: la clave de caché es un `md5` de los
     * parámetros, `specs` incluido. Con claves y valores libres se pueden crear
     * entradas de caché ilimitadas, y en producción `CACHE_STORE=database`: eso
     * engorda una tabla de MySQL con blobs del tamaño de una página de catálogo.
     * Por eso no basta con validar la FORMA de la clave con una expresión
     * regular: hay que acotar el conjunto, y el conjunto real son las facetas.
     *
     * Se contrastan clave **y** valor. Lo que no cuadra se descarta en silencio y
     * la petición sigue: un filtro que ya no existe porque el dueño cambió el
     * catálogo no es motivo para darle un error a quien está comprando.
     *
     * Nota: `facetsDelCatalogo()` corta a 60 valores por spec, así que un valor
     * más allá del 60 se descarta aquí. Es coherente con la interfaz, que arma
     * sus filtros con esa misma lista y tampoco lo ofrece.
     *
     * @return array<string, string>
     */
    private function specsFiltrables(Tenant $tenant, string $slug, int $version, mixed $specs): array
    {
        if (! is_array($specs) || $specs === []) {
            return [];
        }

        $reales = $this->facetsDelCatalogo($tenant, $slug, $version, null);
        $validas = [];

        foreach ($specs as $clave => $valor) {
            if (! is_string($clave) || ! is_string($valor) || $valor === '') {
                continue;
            }

            if (isset($reales[$clave]) && in_array($valor, $reales[$clave], true)) {
                $validas[$clave] = $valor;
            }
        }

        // Ordenadas para que el mismo filtro en distinto orden no genere dos
        // entradas de caché distintas.
        ksort($validas);

        return $validas;
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

    public function product(Request $request, string $slug, string $productId, ViewCounter $vistas): JsonResponse
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

        // Misma historia que en el catalogo: acumulado en cache, no un UPDATE
        // por visita (AUD-2). Aqui la fila caliente es la del producto de moda.
        $vistas->record('products', $product->id);

        // ¿Ya ha reseñado este producto quien lo está mirando? Se resuelve con la
        // misma regla que al publicar (AUD-3): un token de otra tienda cuenta
        // como visitante anónimo, no como el autor de nada.
        $cliente = $this->clienteDeLaTienda($request, $tenant);
        $visitorId = $request->header('X-Visitor-Id') ?? $request->query('visitor_id');
        $userReview = null;

        if ($cliente || $visitorId) {
            $userReview = \App\Models\Review::where('product_id', $product->id)
                ->where(function ($q) use ($cliente, $visitorId) {
                    if ($cliente) {
                        $q->where('user_id', $cliente->id);
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

    /**
     * El usuario del token SOLO si es cliente de esta tienda; null en cualquier
     * otro caso (AUD-3).
     *
     * Las rutas de cliente con sesion obligatoria cierran esto con el middleware
     * 'customer', pero estas dos aceptan visitante anonimo, asi que aqui no se
     * puede rechazar la peticion: hay que decidir a quien se le cree. El registro
     * de clientes es abierto, o sea que un token de "algun usuario" no acredita
     * nada; lo que acredita es un token de ESTA tienda.
     *
     * @return \App\Models\User|null
     */
    private function clienteDeLaTienda(Request $request, Tenant $tenant)
    {
        $user = $request->user('sanctum');

        if (!$user || $user->tenant_id !== $tenant->id || $user->role !== 'customer' || !$user->is_active) {
            return null;
        }

        return $user;
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
        //
        // AUD-3: aquí la sesión es OPCIONAL —una reseña anónima es legítima—, así
        // que esta ruta no puede llevar el middleware 'customer' y la comprobación
        // va a mano. Lo que importa es que el token sea de ESTA tienda: el
        // registro de clientes es abierto, así que cualquiera se daba de alta en
        // su propia tienda y con ese token publicaba reseñas ya aprobadas en el
        // catálogo de la competencia, saltándose la moderación del dueño, que es
        // la única barrera contra el spam y la difamación. Turnstile no lo
        // impide: es un humano con cuenta.
        //
        // El rol también se exige: un admin de la tienda reseñando sus propios
        // productos con sello de cliente es justamente el patrón de reseña falsa.
        // Puede publicar igual, pero pasando por su propia moderación.
        //
        // A partir de aquí manda $cliente, no $user: quien no cumple las dos
        // condiciones se trata como anónimo, no como error.
        $cliente = $this->clienteDeLaTienda($request, $tenant);

        $visitorId = $data['visitor_id'] ?? $request->header('X-Visitor-Id');

        $existingReview = \App\Models\Review::where('product_id', $product->id)
            ->where(function ($q) use ($cliente, $visitorId) {
                if ($cliente) {
                    $q->where('user_id', $cliente->id);
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

        if ($cliente) {
            // Caso A: Cliente registrado en ESTA tienda
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
            // Un usuario de otra tienda se guarda como anónimo: colgar la reseña
            // de su user_id dejaría una fila de esta tienda apuntando a un
            // usuario de otra, que es la referencia cruzada que se quiere evitar.
            'user_id'           => $cliente?->id,
            'visitor_id'        => $cliente ? null : $visitorId,
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
    public function storeOrder(Request $request, string $slug, OrderPricing $pricing): JsonResponse
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

        // Precios y total se calculan en el servidor: no se confía en lo que
        // manda el cliente. `soloVisibles: true` porque desde el catálogo solo
        // se puede comprar lo que está publicado.
        ['lines' => $lineItems, 'total' => $total] = $pricing->build($tenant, $data['items'], soloVisibles: true);

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

        $order->load('items');

        $this->notifyOwnerOfNewOrder($tenant, $order);

        return response()->json($order, 201);
    }

    /**
     * Avisar por correo a los admins de la tienda de que entro un pedido (OWN-2).
     *
     * Va fuera de la transaccion y con el fallo tragado a proposito: el pedido
     * ya esta guardado, asi que un mailer caido no puede devolverle un error al
     * comprador ni hacerle creer que su pedido no entro. Se registra en el log
     * para que el operador lo vea.
     */
    private function notifyOwnerOfNewOrder(Tenant $tenant, Order $order): void
    {
        try {
            $admins = User::where('tenant_id', $tenant->id)
                ->where('role', 'admin')
                ->where('is_active', true)
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new NewOrderNotification($order));
        } catch (\Throwable $e) {
            Log::error('No se pudo avisar del pedido nuevo', [
                'order_id'  => $order->id,
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
        }
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
