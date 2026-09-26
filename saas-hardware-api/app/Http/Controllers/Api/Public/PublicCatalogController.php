<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ComponentType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Models\StockNotification;
use App\Models\Tenant;
use App\Support\Busqueda;
use App\Support\Cupones;
use App\Support\Impuesto;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Services\OrderPricing;
use App\Services\ViewCounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class PublicCatalogController extends Controller
{
    /**
     * Lo unico de una tienda que se publica sin autenticacion (AUD-9).
     *
     * En una constante y no repetido en cada consulta a proposito: si manana se
     * anade una columna a `tenants`, lo que NO puede pasar es que se publique
     * sola por estar en dos sitios y haberse actualizado uno.
     */
    /**
     * Dígitos mínimos para que un teléfono cuente como el de un pedido (ACC-2).
     * Ocho deja fuera coincidencias de azar y cabe en los números más cortos de
     * la región sin prefijo.
     */
    private const MIN_DIGITOS_TELEFONO = 8;

    private const COLUMNAS_PUBLICAS_TENANT = [
        'id', 'slug', 'name', 'logo_url', 'primary_color', 'theme', 'whatsapp_number', 'currency',
        'payment_methods', 'delivery_enabled', 'delivery_cost',
        // MOD-2: el checkout enseña el desglose antes de confirmar, y con el
        // impuesto sumándose al final el comprador TIENE que ver que el total no
        // es la suma del carrito.
        'tax_enabled', 'tax_name', 'tax_rate', 'tax_included',
    ];

    public function resolveDomain(Request $request): JsonResponse
    {
        $domain = $request->query('domain') ?? $request->getHost();

        // AUD-9: las MISMAS columnas que `tenant()`, ni una más. Esto es un
        // endpoint sin autenticación y antes devolvía la fila entera: `plan`,
        // `views_count`, `is_active`, `custom_domain` y los timestamps. O sea que
        // cualquiera podía ver qué plan tienes contratado y cuánto tráfico
        // mueves. El frontend usa esta respuesta y la de `tenant()` para lo
        // mismo, así que además de tapar la fuga las deja coherentes.
        // FUN-5: `publica()` en vez de `is_active` a secas. Este endpoint es la
        // otra puerta de entrada al catalogo (la del dominio propio), asi que sin
        // esto una tienda sin verificar seguiria abierta por su dominio aunque el
        // slug la cerrara.
        //
        // FUN-6: `conDominioVerificado()`, ademas. `custom_domain` es una columna
        // que cualquiera con acceso al panel puede escribir; sin este scope,
        // pedir aqui el dominio de OTRA tienda -antes de demostrar que es tuyo-
        // servia igual el catalogo ajeno.
        $tenant = Tenant::publica()
            ->conDominioVerificado()
            ->where('custom_domain', $domain)
            ->select(self::COLUMNAS_PUBLICAS_TENANT)
            ->first();

        if (! $tenant) {
            return response()->json(['message' => 'No se encontró ninguna tienda asociada a este dominio.'], 404);
        }

        // MOD-3: solo los métodos ENCENDIDOS, y con sus datos. Un método
        // apagado puede tener campos a medio llenar de una vez que el dueño
        // lo probó y se arrepintió; no es asunto de nadie fuera del panel.
        $tenant->payment_methods = $tenant->metodosDePagoActivos();

        return response()->json($tenant);
    }

    public function tenant(string $slug): JsonResponse
    {
        $tenant = Cache::remember("tenant:{$slug}", 300, function () use ($slug) {
            $modelo = Tenant::where('slug', $slug)
                ->where('is_active', true)
                ->select(self::COLUMNAS_PUBLICAS_TENANT)
                ->firstOrFail();

            $modelo->payment_methods = $modelo->metodosDePagoActivos();

            return $modelo->toArray();
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
        $version = Cache::remember("tenant:{$slug}:cache_version", 86400, fn () => 1);

        // AUD-7: los filtros de spec se contrastan contra las specs que existen
        // de verdad ANTES de tocar nada más. Ver `specsFiltrables()`.
        $specs = $this->specsFiltrables($tenant, $slug, $version, $request->input('specs'));

        // IMPORTANTE: usar only() para prevenir cache flooding con parámetros
        // arbitrarios. `specs` va aparte y ya saneado: si entrara en crudo, la
        // clave de caché seguiría siendo libre y el flooding también.
        $criterios = $request->only(['category_id', 'search', 'in_stock', 'page', 'sort']);
        $criterios['specs'] = $specs;

        // FUN-8: el armador pide "procesadores", no "la categoria tal". Se
        // filtra por el tipo de la categoria y no por su id porque una tienda
        // puede tener dos categorias del mismo tipo ("Procesadores Intel" y
        // "Procesadores AMD"): con un solo `category_id` la mitad del stock se
        // quedaba fuera del paso sin que el comprador lo supiera.
        //
        // Se normaliza contra el enum antes de tocar la clave de cache: un
        // valor libre aqui seria una entrada de cache nueva por cada cadena
        // inventada, la misma fuga que AUD-7 tapo en el filtro de specs.
        $componentType = ComponentType::tryFrom((string) $request->query('component_type'));
        $criterios['component_type'] = $componentType?->value;

        $cacheKey = "catalog:{$slug}:v{$version}:".md5(json_encode($criterios));

        $products = Cache::remember($cacheKey, 300, function () use ($tenant, $request, $specs, $componentType) {
            return Product::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where('status', 'published')
                ->with(['category:id,name,icon', 'images', 'variants'])
                ->withAvg(['reviews' => fn ($q) => $q->where('is_approved', true)], 'rating')
                ->withCount(['reviews' => fn ($q) => $q->where('is_approved', true)])
                ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
                ->when($componentType, fn ($q) => $q->whereHas(
                    'category',
                    fn ($c) => $c->where('component_type', $componentType->value)
                ))
                // INF-6: nombre, marca, SKU y el SKU de cualquier variante, con
                // cada palabra buscada por separado. El porque de que siga siendo
                // un LIKE -y no FULLTEXT, que es lo que proponia AUD-21- esta en
                // `App\Support\Busqueda`, con el resto de la logica: la misma
                // busqueda la ofrecen tambien el panel y la exportacion, y antes
                // cada uno miraba columnas distintas.
                ->tap(fn ($q) => Busqueda::aplicar($q, $request->search))
                ->when($request->in_stock, fn ($q) => $q->where('stock', '>', 0))
                ->when($specs, function ($q) use ($specs) {
                    foreach ($specs as $key => $value) {
                        $q->where('specs->'.$key, $value);
                    }
                })
                ->tap(fn ($q) => $this->applyCatalogSort($q, $request->query('sort'), $request->search))
                ->paginate(24)
                ->toArray();
        });

        return response()->json($products);
    }

    /**
     * Comprueba un código de cupón desde el carrito, antes de confirmar (MOD-4).
     *
     * Existe para que el comprador vea el descuento aplicado y decida con el
     * número delante, no para decidir nada: **lo que se cobra lo vuelve a
     * calcular `storeOrder`** con el mismo helper. Si el cupón se agota entre que
     * se comprueba y se confirma, manda el segundo cálculo.
     *
     * El subtotal llega del navegador y aquí eso no importa: solo sirve para
     * comprobar la compra mínima y enseñar cuánto descontaría. Mentir en él no
     * consigue un descuento mayor, porque el del pedido se calcula sobre los
     * precios que pone el servidor.
     *
     * **Ritmo propio y más estrecho que el resto** (`throttle:cupon`): es el
     * único sitio del catálogo donde adivinar a ciegas tiene premio.
     */
    public function checkCoupon(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $data = $request->validate([
            'code' => 'required|string|max:40',
            'subtotal' => 'required|numeric|min:0',
        ]);

        // `resolver()` lanza ValidationException con el motivo legible, que es
        // justo lo que el carrito enseña debajo del campo.
        $cupon = Cupones::resolver($tenant, $data['code'], (float) $data['subtotal']);

        return response()->json([
            'code'     => $cupon['coupon']->code,
            'type'     => $cupon['coupon']->type,
            'value'    => $cupon['coupon']->value,
            'discount' => $cupon['discount'],
        ]);
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
     * definió arrastrando productos (`sort_order`), que es el de siempre — salvo
     * que haya una búsqueda puesta: entonces manda la relevancia (`INF-6`).
     * Arrastrar productos ordena el escaparate, y un escaparate ordenado a mano
     * no dice nada sobre lo que alguien acaba de escribir en la caja de buscar.
     * Si el comprador eligió un orden (precio, novedad, nombre) se respeta el
     * suyo: lo ha pedido él, y es más explícito que cualquier heurística.
     */
    private function applyCatalogSort(Builder $query, ?string $sort, ?string $busqueda = null): void
    {
        // El precio que ve el comprador es el de oferta cuando existe, así que se
        // ordena por ese mismo valor y no por `price` a secas.
        $precioVisible = 'COALESCE(sale_price, price)';

        if ($sort === null || $sort === '') {
            Busqueda::ordenarPorRelevancia($query, $busqueda);
        }

        match ($sort) {
            'price_asc' => $query->orderByRaw("{$precioVisible} ASC"),
            'price_desc' => $query->orderByRaw("{$precioVisible} DESC"),
            'newest' => $query->orderByDesc('created_at'),
            'name' => $query->orderBy('name'),
            default => $query->orderBy('sort_order')->orderByDesc('created_at'),
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
            ->with(['category', 'images', 'variants', 'reviews' => fn ($q) => $q->where('is_approved', true)->orderByDesc('created_at')])
            ->withAvg(['reviews' => fn ($q) => $q->where('is_approved', true)], 'rating')
            ->withCount(['reviews' => fn ($q) => $q->where('is_approved', true)])
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
            $userReview = Review::where('product_id', $product->id)
                ->where(function ($q) use ($cliente, $visitorId) {
                    if ($cliente) {
                        $q->where('user_id', $cliente->id);
                    }
                    if ($visitorId) {
                        $q->orWhere('visitor_id', $visitorId);
                    }
                })
                ->first();

            // ACC-2: mismo motivo que en `storeReview()`. La reseña propia se
            // pide con un `visitor_id` que pone el navegador, así que mientras
            // esté pendiente no dice si el teléfono coincidió.
            if ($userReview && ! $userReview->is_approved) {
                $userReview->makeHidden('verified_purchase');
            }
        }

        // Algoritmo básico de productos relacionados (Cross-selling)
        $relatedQuery = Product::where('tenant_id', $tenant->id)
            ->where('id', '!=', $product->id)
            ->where('is_active', true)
            ->where('status', 'published')
            ->with(['category', 'images', 'variants']);

        // FUN-8: los complementarios salen del TIPO de la categoría, no de su
        // nombre. Antes esto era `str_contains($categoryName, 'procesador')` y
        // una lista de nombres esperados, así que una tienda que llamara "CPU" a
        // sus procesadores se quedaba sin cross-selling y sin ningún aviso — el
        // mismo fallo silencioso que el enum cerró en el armador.
        $tipo = $product->category?->component_type;
        $tiposComplementarios = $tipo ? $tipo->complementarios() : [];

        $complementaryProducts = collect();

        if ($tiposComplementarios !== []) {
            $valores = array_map(fn (ComponentType $t) => $t->value, $tiposComplementarios);

            $complementaryProducts = (clone $relatedQuery)
                ->whereHas('category', fn ($q) => $q->whereIn('component_type', $valores))
                // Con tope: antes esta consulta se traía TODOS los productos de
                // las categorías complementarias -un catálogo entero de placas y
                // memorias- para acabar quedándose con seis. El orden por
                // afinidad de abajo se hace en PHP, así que necesita un montón
                // de candidatos, no el catálogo: 24 da de sobra para llenar los
                // seis huecos.
                ->orderByDesc('views_count')
                ->limit(24)
                ->get();
        }

        // Ordenar al inicio los que comparten socket o tipo de memoria.
        //
        // OJO: esto sigue leyendo el nombre y las specs con una expresión
        // regular, que es la mitad de FUN-8 que sigue abierta. Se deja porque
        // aquí sólo REORDENA una lista que ya está bien elegida: si no encuentra
        // el dato, las sugerencias siguen siendo las correctas, sólo que sin
        // priorizar. No decide qué se enseña, a diferencia de lo de arriba.
        $specsString = json_encode($product->specs ?? []);
        $productString = strtolower($product->name.' '.$specsString);

        // FUN-22: los dos `\b` de abajo fueron durante dos semanas un byte de
        // retroceso invisible que dejó una edición automática, y con él el reorden
        // no encajaba nunca y nadie lo notó. Lo vigilan dos tests de
        // `CategoryComponentTypeTest` y `SinCaracteresDeControlTest`.
        $socket = null;
        if (preg_match('/\b(am5|am4|lga1700|1700|lga1200|1200|lga1151|1151)\b/i', $productString, $matches)) {
            $socket = $matches[1];
        }

        $ramType = null;
        if (preg_match('/\b(ddr5|ddr4)\b/i', $productString, $matches)) {
            $ramType = $matches[1];
        }

        if ($socket || $ramType) {
            $complementaryProducts = $complementaryProducts->sortByDesc(function ($p) use ($socket, $ramType) {
                $pSpecs = json_encode($p->specs ?? []);
                $pString = strtolower($p->name.' '.$pSpecs);
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
     * @return User|null
     */
    private function clienteDeLaTienda(Request $request, Tenant $tenant)
    {
        $user = $request->user('sanctum');

        return $user?->esClienteDe($tenant) ? $user : null;
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
            'customer_name' => 'required|string|max:150',
            'customer_email' => 'nullable|email|max:150',
            'customer_phone' => 'nullable|string|max:30',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'visitor_id' => 'nullable|string|max:100',
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

        $existingReview = Review::where('product_id', $product->id)
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

        // Capa 3: compra verificada y aprobación (ACC-2).
        //
        // Antes se publicaban solas dos cosas que no acreditaban nada: la de
        // cualquier cliente registrado -el registro es abierto, así que "tener
        // cuenta" cuesta un formulario- y la de un anónimo cuyo teléfono
        // coincidiera POR SUFIJO con un pedido atendido: con `customer_phone: "7"`
        // bastaba con que algún comprador acabara en 7. Las dos se saltaban la
        // moderación del dueño, que es la única barrera contra la difamación.
        //
        // Ahora sólo se publica sola la reseña de quien DE VERDAD compró: un
        // cliente con sesión de esta tienda con un pedido atendido de este
        // producto a su nombre. El teléfono de un anónimo se sigue comparando
        // -para la insignia, que el dueño ve al moderar-, pero ya no aprueba.
        $compraConCuenta = $cliente && Order::where('tenant_id', $tenant->id)
            ->where('user_id', $cliente->id)
            ->where('status', 'attended')
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->exists();

        $verifiedPurchase = $compraConCuenta
            || (! $cliente && $this->telefonoCompro($tenant, $product, $data['customer_phone'] ?? null));

        $isApproved = $compraConCuenta;

        $review = $product->reviews()->create([
            'tenant_id' => $tenant->id,
            // Un usuario de otra tienda se guarda como anónimo: colgar la reseña
            // de su user_id dejaría una fila de esta tienda apuntando a un
            // usuario de otra, que es la referencia cruzada que se quiere evitar.
            'user_id' => $cliente?->id,
            'visitor_id' => $cliente ? null : $visitorId,
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'] ?? null,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'verified_purchase' => $verifiedPurchase,
            'is_approved' => $isApproved,
        ]);

        $message = $isApproved
            ? '¡Reseña publicada con éxito!'
            : 'Tu reseña ha sido enviada. Se mostrará en el catálogo una vez aprobada por la tienda.';

        // ACC-2: una reseña pendiente responde IGUAL haya coincidido el teléfono
        // o no. Si no, la respuesta diría si ese número compró este producto en
        // esta tienda, a quien escriba el número de otro.
        if (! $isApproved) {
            $review->makeHidden('verified_purchase');
        }

        return response()->json([
            'review' => $review,
            'message' => $message,
            'is_approved' => $isApproved,
        ], 201);
    }

    /**
     * Si el teléfono que escribió un anónimo es el de un pedido atendido de este
     * producto (ACC-2).
     *
     * Sólo pone la insignia, que el dueño ve al moderar: no aprueba nada, porque
     * nadie comprueba que el teléfono sea de quien lo escribe. Se compara por
     * dígitos y con un mínimo de `MIN_DIGITOS_TELEFONO`, admitiendo que uno de
     * los dos lleve el prefijo del país y el otro no -el checkout lo guarda con
     * prefijo y la venta de mostrador como lo escriba el dueño-. Antes era un
     * `LIKE '%{dígitos}'` sin mínimo, y un solo dígito coincidía con cualquiera.
     */
    private function telefonoCompro(Tenant $tenant, Product $product, ?string $telefono): bool
    {
        $escrito = preg_replace('/\D/', '', (string) $telefono);

        if (strlen($escrito) < self::MIN_DIGITOS_TELEFONO) {
            return false;
        }

        // El `LIKE` sólo acota candidatos en la base; quien decide es la
        // comparación de abajo, en PHP.
        $candidatos = Order::where('tenant_id', $tenant->id)
            ->where('status', 'attended')
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', '') LIKE ?",
                ['%'.substr($escrito, -self::MIN_DIGITOS_TELEFONO)]
            )
            ->pluck('customer_phone');

        foreach ($candidatos as $delPedido) {
            $guardado = preg_replace('/\D/', '', (string) $delPedido);
            [$corto, $largo] = strlen($guardado) <= strlen($escrito) ? [$guardado, $escrito] : [$escrito, $guardado];

            if (strlen($corto) >= self::MIN_DIGITOS_TELEFONO && str_ends_with($largo, $corto)) {
                return true;
            }
        }

        return false;
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
            if (! $esLocal) {
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
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            if (! $response->json('success')) {
                Log::warning('Turnstile verification failed', [
                    'response' => $response->json(),
                    'ip' => $ip,
                ]);

                return response()->json(['message' => 'Validación anti-bot (Turnstile) fallida. Recarga e inténtalo de nuevo.'], 422);
            }
        } catch (\Exception $e) {
            Log::error('Turnstile connection exception', ['error' => $e->getMessage()]);

            // En local dejamos pasar ante un fallo de red para no trabar las pruebas.
            if (! $esLocal) {
                return response()->json(['message' => 'Error al verificar protección anti-bot.'], 502);
            }

            Log::info('Bypassing Turnstile in local environment due to connection error.');
        }

        return null;
    }

    /**
     * "Avísame cuando llegue": el cliente deja su contacto para un producto agotado.
     *
     * El aviso al reponer stock ya se envía (FUN-1b, cerrado el 2026-09-07), pero
     * **sólo por correo y sólo a quien dejó un correo**: `customer_contact` es un
     * campo libre y los teléfonos se quedan esperando a propósito, para que el dueño
     * escriba por WhatsApp desde la lista de espera del panel. El porqué está en
     * `Product::notifyStockSubscribers()`.
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
            'customer_name' => 'required|string|max:150',
            'customer_contact' => 'required|string|max:150', // teléfono o email
            'variant_id' => 'nullable|uuid',
        ]);

        // MOD-5: con variantes, el cliente espera UNA de ellas (la agotada que
        // quiere), no el producto: el aviso llega cuando se repone esa.
        $variante = null;

        if ($product->variants()->exists()) {
            $variante = $product->variants()->where('id', $data['variant_id'] ?? null)->first();

            if (! $variante) {
                return response()->json([
                    'message' => 'Elige qué opción del producto quieres que te avisemos.',
                ], 422);
            }
        }

        // Solo tiene sentido si lo que espera está agotado
        if (($variante ? $variante->stock : $product->stock) > 0) {
            return response()->json([
                'message' => $variante
                    ? 'Esa opción ya está disponible. ¡Puedes pedirla ahora!'
                    : 'Este producto ya está disponible. ¡Puedes pedirlo ahora!',
            ], 422);
        }

        // Registro idempotente: si ya estaba anotado (y aún sin avisar), no duplicar
        $notification = StockNotification::firstOrCreate(
            [
                'product_id' => $product->id,
                'variant_id' => $variante?->id,
                'customer_contact' => $data['customer_contact'],
            ],
            [
                'tenant_id' => $tenant->id,
                'customer_name' => $data['customer_name'],
            ]
        );

        // Si un aviso previo ya fue enviado y el cliente se reinscribe, reabrir el interés
        if (! $notification->wasRecentlyCreated && $notification->notified_at !== null) {
            $notification->update([
                'customer_name' => $data['customer_name'],
                'notified_at' => null,
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
            return Category::where('tenant_id', $tenant->id)
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
            'customer_name' => 'required|string|max:200',
            'customer_phone' => 'required|string|max:30',
            // FUN-2: opcional. Obligarlo garantizaría la confirmación pero añade
            // fricción en el único paso donde se pierden ventas; quien lo deja
            // recibe correo y quien no, se queda como estaba.
            'customer_email' => 'nullable|email|max:200',
            'customer_note' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|uuid',
            'items.*.variant_id' => 'nullable|uuid',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            // MOD-1: solo el MÉTODO viaja desde el navegador. El costo nunca —
            // se lee de `tenant.delivery_cost` aquí abajo, o un comprador podría
            // mandar "delivery" con costo 0 y pedir gratis lo que la tienda cobra.
            'delivery_method' => 'nullable|in:pickup,delivery',
            // MOD-4: solo el CÓDIGO viaja desde el navegador, nunca el descuento
            // —mismo motivo que el costo del envío en MOD-1—: si no, cualquiera
            // mandaría su propio descuento.
            'coupon_code' => 'nullable|string|max:40',
        ]);

        // Precios y total se calculan en el servidor: no se confía en lo que
        // manda el cliente. `soloVisibles: true` porque desde el catálogo solo
        // se puede comprar lo que está publicado.
        ['lines' => $lineItems, 'total' => $itemsTotal] = $pricing->build($tenant, $data['items'], soloVisibles: true);

        // `delivery` solo cuenta si la tienda de verdad tiene el envío
        // encendido; si lo apagó después de que el comprador cargara la
        // página, un `delivery_method: 'delivery'` suelto no debe cobrar nada
        // ni quedar anotado como si la tienda lo ofreciera.
        $entrega = null;
        $costoEnvio = 0.0;

        if ($tenant->delivery_enabled) {
            $entrega = ($data['delivery_method'] ?? null) === 'delivery' ? 'delivery' : 'pickup';
            $costoEnvio = $entrega === 'delivery' ? (float) $tenant->delivery_cost : 0.0;
        } elseif (($data['delivery_method'] ?? null) === 'pickup') {
            // Sin envío activado no hay nada que elegir, pero "recojo en
            // tienda" es un dato honesto igual: no cobra nada.
            $entrega = 'pickup';
        }

        // MOD-4: el descuento se calcula sobre los productos y NO sobre el envío,
        // por lo mismo que el envío queda fuera del margen: lo que la tienda le
        // paga al repartidor no baja porque el comprador tenga un código.
        $cupon = Cupones::resolver($tenant, $data['coupon_code'] ?? null, $itemsTotal);

        // MOD-2: el impuesto entra sobre TODO lo que se cobra, envío incluido, y
        // DESPUÉS del descuento: al revés, el cupón descontaría también de la
        // parte que es del fisco, que la tienda paga igual.
        $impuesto = Impuesto::paraVenta($tenant, round($itemsTotal - $cupon['discount'] + $costoEnvio, 2));

        // ACC-4: el pedido queda a nombre de alguien sólo si es un cliente de ESTA
        // tienda, con la misma regla que las reseñas. Antes valía cualquier token
        // que Sanctum resolviera, y el del panel dejaba al dueño como cliente de
        // su propia tienda. El de otra tienda ya no llegaba -el scope de `User`
        // filtra por la tienda resuelta-, pero eso era un efecto de rebote, no
        // una regla de aquí. Comprar sin cuenta sigue siendo legítimo: en esos
        // casos el pedido entra, sólo que sin dueño.
        $userId = $this->clienteDeLaTienda($request, $tenant)?->id;

        $order = DB::transaction(function () use ($tenant, $data, $lineItems, $impuesto, $entrega, $costoEnvio, $userId, $cupon, $itemsTotal) {
            if ($cupon['coupon']) {
                Cupones::consumir($cupon['coupon']);
            }

            $order = Order::create([
                'tenant_id' => $tenant->id,
                'user_id' => $userId,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
                'status' => 'pending',
                'total' => $impuesto['total'],
                'items_subtotal' => $itemsTotal,
                'coupon_id' => $cupon['coupon']?->id,
                'coupon_code' => $cupon['coupon']?->code,
                'discount_amount' => $cupon['coupon'] ? $cupon['discount'] : null,
                'delivery_method' => $entrega,
                'delivery_cost' => $costoEnvio,
                'tax_name' => $impuesto['tax_name'],
                'tax_rate' => $impuesto['tax_rate'],
                'tax_included' => $impuesto['tax_included'],
                'tax_amount' => $impuesto['tax_amount'],
            ]);

            $order->items()->createMany($lineItems);

            return $order;
        });

        $order->load('items');

        $this->notifyOwnerOfNewOrder($tenant, $order);
        $this->notifyCustomerOfNewOrder($order);

        return response()->json($order, 201);
    }

    /**
     * Avisar por correo al equipo de la tienda de que entro un pedido (OWN-2).
     *
     * Va fuera de la transaccion y con el fallo tragado a proposito: el pedido
     * ya esta guardado, asi que un mailer caido no puede devolverle un error al
     * comprador ni hacerle creer que su pedido no entro. Se registra en el log
     * para que el operador lo vea.
     */
    private function notifyOwnerOfNewOrder(Tenant $tenant, Order $order): void
    {
        try {
            // Todo el equipo activo, no solo los admins (FUN-4): quien atiende
            // los pedidos suele ser staff, y es quien necesita enterarse.
            $equipo = User::where('tenant_id', $tenant->id)
                ->whereIn('role', User::ROLES_DE_PANEL)
                ->where('is_active', true)
                ->get();

            if ($equipo->isEmpty()) {
                return;
            }

            Notification::send($equipo, new NewOrderNotification($order));
        } catch (\Throwable $e) {
            Log::error('No se pudo avisar del pedido nuevo', [
                'order_id' => $order->id,
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Confirmar al comprador que su pedido entro (FUN-2).
     *
     * `routes('mail')` y no `Notification::send($user)`: el checkout no exige
     * cuenta, asi que el caso normal es que no haya ningun `User` detras de este
     * correo. Se envia a la direccion que dejo, exista o no cuenta de cliente.
     *
     * Mismo criterio que el aviso al dueno: fuera de la transaccion y con el
     * fallo tragado. El pedido ya esta guardado y no depende de este correo.
     */
    private function notifyCustomerOfNewOrder(Order $order): void
    {
        if (blank($order->customer_email)) {
            return;
        }

        try {
            Notification::route('mail', $order->customer_email)
                ->notify(new OrderPlacedNotification($order));
        } catch (\Throwable $e) {
            Log::error('No se pudo confirmar el pedido al comprador', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function pages(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $pages = Page::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->select(['id', 'title', 'slug'])
            ->get();

        return response()->json($pages);
    }

    public function pageDetail(string $slug, string $pageSlug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $page = Page::where('tenant_id', $tenant->id)
            ->where('slug', $pageSlug)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json($page);
    }
}
