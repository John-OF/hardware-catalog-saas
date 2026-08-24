<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\Category;
use App\Services\ImageService;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Tope de filas del import CSV (AUD-10).
     *
     * No es un numero magico: con 2.000 filas el import tarda un par de segundos
     * y cabe de sobra en el tiempo de ejecucion por defecto. Un catalogo mas
     * grande que eso no es una carga puntual sino una migracion, y se hace por
     * partes o por consola.
     */
    private const MAX_FILAS_CSV = 2000;

    /** Filas por INSERT en el import. */
    private const LOTE_CSV = 500;

    public function __construct(private ImageService $imageService) {}

    public function index(Request $request): JsonResponse
    {
        $products = Product::with(['category', 'images'])
            ->withCount(['stockNotifications as waitlist_count' => fn($q) => $q->whereNull('notified_at')])
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->search, fn($q) => $q->where(function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->search}%")
                      ->orWhere('sku', 'like', "%{$request->search}%");
            }))
            ->when($request->active_only, fn($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        // SAAS-3: los dos topes se comprueban antes de subir nada. Al reves, un
        // producto rechazado dejaria sus imagenes ya escritas en disco sin
        // ninguna fila que las referencie.
        PlanGate::ensureCanCreate('products');

        if ($request->hasFile('gallery')) {
            PlanGate::ensureCanAdd('images_per_product', 0, count($request->file('gallery')));
        }

        $data = $request->validated();

        if ($request->hasFile('image')) {
            $tenant = app('currentTenant');
            $urls   = $this->imageService->uploadProductImage($request->file('image'), $tenant->slug);
            $data   = array_merge($data, $urls);
        }

        // No pasar 'id' — HasUuids genera UUID v7 automáticamente
        $product = Product::create($data);

        // Subir imágenes de la galería si existen
        if ($request->hasFile('gallery')) {
            $tenant = app('currentTenant');
            $sortOrder = 0;
            foreach ($request->file('gallery') as $galleryFile) {
                $urls = $this->imageService->uploadProductImage($galleryFile, $tenant->slug);
                $product->images()->create([
                    'image_url'     => $urls['image_url'],
                    'thumbnail_url' => $urls['thumbnail_url'],
                    'sort_order'    => $sortOrder++,
                ]);
            }
        }

        return response()->json($product->load(['category', 'images']), 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product->load(['category', 'images']));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        // Las imagenes marcadas para borrar se leen aqui arriba y no en su bloque
        // de mas abajo porque el tope de galeria del plan (SAAS-3) tiene que
        // contar el saldo: quien cambia tres fotos por otras tres no esta
        // añadiendo ninguna.
        $deletedIds = [];

        if ($request->has('deleted_image_ids')) {
            $deletedIds = is_array($request->deleted_image_ids)
                ? $request->deleted_image_ids
                : json_decode($request->deleted_image_ids, true) ?? [];
        }

        if ($request->hasFile('gallery')) {
            $actual = $product->images()->count();

            // Solo descuentan las que existen y son de este producto: la lista
            // llega del navegador y puede traer ids repetidos o ajenos.
            if ($deletedIds !== []) {
                $actual -= $product->images()->whereIn('id', $deletedIds)->count();
            }

            PlanGate::ensureCanAdd('images_per_product', $actual, count($request->file('gallery')));
        }

        $data = $request->validated();

        if ($request->hasFile('image')) {
            // Eliminar imágenes anteriores
            $this->imageService->deleteProductImages($product->image_url, $product->thumbnail_url);

            $tenant = app('currentTenant');
            $urls   = $this->imageService->uploadProductImage($request->file('image'), $tenant->slug);
            $data   = array_merge($data, $urls);
        }

        $product->update($data);

        // Eliminar imágenes de galería seleccionadas
        foreach ($deletedIds as $imgId) {
            $imgModel = $product->images()->find($imgId);
            if ($imgModel) {
                $this->imageService->deleteProductImages($imgModel->image_url, $imgModel->thumbnail_url);
                $imgModel->delete();
            }
        }

        // Subir nuevas imágenes a la galería
        if ($request->hasFile('gallery')) {
            $tenant = app('currentTenant');
            $maxSort = $product->images()->max('sort_order') ?? -1;
            $sortOrder = $maxSort + 1;

            foreach ($request->file('gallery') as $galleryFile) {
                $urls = $this->imageService->uploadProductImage($galleryFile, $tenant->slug);
                $product->images()->create([
                    'image_url'     => $urls['image_url'],
                    'thumbnail_url' => $urls['thumbnail_url'],
                    'sort_order'    => $sortOrder++,
                ]);
            }
        }

        return response()->json($product->load(['category', 'images']));
    }

    public function destroy(Product $product): JsonResponse
    {
        // Eliminar imagen principal
        $this->imageService->deleteProductImages($product->image_url, $product->thumbnail_url);

        // Eliminar imágenes de la galería
        foreach ($product->images as $imgModel) {
            $this->imageService->deleteProductImages($imgModel->image_url, $imgModel->thumbnail_url);
        }

        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * Importa masivamente productos desde un archivo CSV.
     */
    public function import(Request $request): JsonResponse
    {
        // SAAS-3: el import es una funcion del plan, no un tope. Va lo primero,
        // antes incluso de mirar el archivo: si no esta incluido, no hay nada
        // que procesar.
        PlanGate::ensureAllows('csv_import');

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:4096',
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();

        // Detectar delimitador: , o ;
        $handle = fopen($path, 'r');
        $firstLine = fgets($handle);
        fclose($handle);
        $delimiter = strpos($firstLine, ';') !== false ? ';' : ',';

        // AUD-10: el tope se comprueba ANTES de abrir la transaccion. Sin el, un
        // CSV de 50.000 filas agotaba el tiempo de ejecucion con la transaccion
        // abierta, y el dueno se quedaba con un 504 sin saber si se habia
        // importado algo o no. La pasada de conteo no toca la base y corta en
        // cuanto pasa del tope, asi que no recorre el archivo entero.
        if ($this->excedeElTope($path, $delimiter)) {
            return response()->json([
                'message' => 'El archivo tiene más de ' . self::MAX_FILAS_CSV . ' productos. Divídelo en varios archivos e impórtalos por partes.',
            ], 422);
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, $delimiter);

        if (!$header) {
            fclose($handle);
            return response()->json(['message' => 'El archivo CSV está vacío o es inválido.'], 422);
        }

        // Normalizar cabecera (minúsculas, sin espacios).
        // El str_replace quita el BOM de UTF-8: Excel lo escribe al guardar y sin
        // esto la primera columna no casa con 'nombre' y el mapeo cae a los
        // fallbacks por posición (OWN-5).
        $header = array_map(
            fn ($col) => trim(strtolower(str_replace("\xEF\xBB\xBF", '', $col))),
            $header
        );

        $successCount = 0;
        $errors = [];
        $rowCount = 1;
        $lote = [];
        $categoriasPorNombre = [];

        $categoriasCreadas  = 0;
        $categoriasOmitidas = false;

        // Mapear índices
        $map = [
            'nombre'           => array_search('nombre', $header),
            'marca'            => array_search('marca', $header),
            'precio'           => array_search('precio', $header),
            'precio_oferta'    => array_search('precio_oferta', $header),
            'stock'            => array_search('stock', $header),
            'categoria'        => array_search('categoria', $header),
            'descripcion'      => array_search('descripcion', $header),
            'especificaciones' => array_search('especificaciones', $header),
        ];

        // Alternativas en inglés
        if ($map['nombre'] === false) $map['nombre'] = array_search('name', $header);
        if ($map['marca'] === false) $map['marca'] = array_search('brand', $header);
        if ($map['precio'] === false) $map['precio'] = array_search('price', $header);
        if ($map['precio_oferta'] === false) $map['precio_oferta'] = array_search('sale_price', $header);
        if ($map['stock'] === false) $map['stock'] = array_search('stock', $header);
        if ($map['categoria'] === false) $map['categoria'] = array_search('category', $header);
        if ($map['descripcion'] === false) $map['descripcion'] = array_search('description', $header);
        if ($map['especificaciones'] === false) $map['especificaciones'] = array_search('specs', $header);

        // Fallbacks por posición si fallan cabeceras
        if ($map['nombre'] === false) $map['nombre'] = 0;
        if ($map['precio'] === false) $map['precio'] = 1;
        if ($map['stock'] === false) $map['stock'] = 2;

        \DB::beginTransaction();
        try {
            // Hueco que deja el plan (SAAS-3). `null` en cualquiera de los dos es
            // "sin tope". Se piden una sola vez y no por fila, que serian dos
            // COUNT por cada linea del CSV.
            //
            // Van DENTRO del try aunque no escriban nada: son consultas, y si la
            // base se cae justo aqui el fallo tiene que salir por el mismo sitio
            // que el resto -mensaje generico y traza al log- en vez de devolver
            // el SQL crudo al navegador (AUD-8).
            $huecoProductos  = PlanGate::hueco('products');
            $huecoCategorias = PlanGate::hueco('categories');

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowCount++;

                // El tope del plan corta el import, no lo rechaza entero: entra
                // lo que cabe y el resto se reporta como aviso. Rechazar un
                // archivo de 300 filas porque las 10 ultimas no caben obligaria
                // al dueño a editar el CSV para no perder las 290 que si.
                if ($huecoProductos !== null && $successCount >= $huecoProductos) {
                    $errors[] = 'Tu plan ' . PlanGate::label() . " admite {$huecoProductos} productos más: el resto del archivo no se importó.";
                    break;
                }

                // Ignorar filas vacías
                if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                    continue;
                }

                $name = $map['nombre'] !== false && isset($row[$map['nombre']]) ? trim($row[$map['nombre']]) : '';

                if (empty($name)) {
                    $errors[] = "Fila {$rowCount}: El nombre del producto es obligatorio.";
                    continue;
                }

                $brand = $map['marca'] !== false && isset($row[$map['marca']]) ? trim($row[$map['marca']]) : null;

                $priceStr = $map['precio'] !== false && isset($row[$map['precio']]) ? trim($row[$map['precio']]) : '';
                $price = is_numeric($priceStr) ? (float) $priceStr : null;

                if ($price === null || $price < 0) {
                    $errors[] = "Fila {$rowCount}: El precio '{$priceStr}' no es válido (debe ser un número >= 0).";
                    continue;
                }

                $salePriceStr = $map['precio_oferta'] !== false && isset($row[$map['precio_oferta']]) ? trim($row[$map['precio_oferta']]) : '';
                $salePrice = is_numeric($salePriceStr) ? (float) $salePriceStr : null;

                if ($salePrice !== null && $salePrice >= $price) {
                    $errors[] = "Fila {$rowCount}: El precio de oferta ($salePrice) debe ser menor que el precio regular ($price).";
                    continue;
                }

                $stockStr = $map['stock'] !== false && isset($row[$map['stock']]) ? trim($row[$map['stock']]) : '';
                $stock = is_numeric($stockStr) ? (int) $stockStr : null;

                if ($stock === null || $stock < 0) {
                    $errors[] = "Fila {$rowCount}: El stock '{$stockStr}' no es válido (debe ser un entero >= 0).";
                    continue;
                }

                $categoryName = $map['categoria'] !== false && isset($row[$map['categoria']]) ? trim($row[$map['categoria']]) : '';
                $categoryId = null;

                if (!empty($categoryName)) {
                    // Un CSV repite la misma categoria en decenas de filas: sin
                    // este memo, cada una de ellas era un SELECT.
                    if (!array_key_exists($categoryName, $categoriasPorNombre)) {
                        // firstOrCreate partido en dos porque hace falta saber si
                        // la categoria es nueva: las que ya existen no gastan
                        // hueco del plan por mucho que el CSV las nombre.
                        $categoria = Category::where('name', $categoryName)->first();

                        if (! $categoria && ($huecoCategorias === null || $categoriasCreadas < $huecoCategorias)) {
                            $categoria = Category::create([
                                'name'       => $categoryName,
                                'icon'       => 'folder',
                                'sort_order' => 0,
                                'is_active'  => true,
                            ]);
                            $categoriasCreadas++;
                        }

                        // Sin hueco, el producto entra SIN categoria en vez de no
                        // entrar: perder la clasificacion se arregla desde el
                        // panel, perder el producto no.
                        if (! $categoria) {
                            $categoriasOmitidas = true;
                        }

                        $categoriasPorNombre[$categoryName] = $categoria?->id;
                    }
                    $categoryId = $categoriasPorNombre[$categoryName];
                }

                $description = $map['descripcion'] !== false && isset($row[$map['descripcion']]) ? trim($row[$map['descripcion']]) : null;
                $rowSpecs = $map['especificaciones'] !== false && isset($row[$map['especificaciones']]) ? trim($row[$map['especificaciones']]) : '';

                $specs = null;
                if (!empty($rowSpecs)) {
                    $specs = [];
                    // Se aceptan '|' y ';' como separador de specs (OWN-5). La
                    // plantilla usa '|' porque ';' es tambien el delimitador de
                    // columnas; el ';' se mantiene para no romper los archivos
                    // que ya usaba la gente (que funcionan si la columna va
                    // entrecomillada).
                    $parts = preg_split('/[|;]/', $rowSpecs);
                    foreach ($parts as $part) {
                        $pair = explode(':', $part, 2);
                        if (count($pair) === 2) {
                            $key = trim($pair[0]);
                            $val = trim($pair[1]);
                            if ($key !== '' && $val !== '') {
                                $specs[$key] = $val;
                            }
                        }
                    }
                }

                $lote[] = $this->filaAAtributos([
                    'name'        => $name,
                    'brand'       => $brand,
                    'price'       => $price,
                    'sale_price'  => $salePrice,
                    'stock'       => $stock,
                    'category_id' => $categoryId,
                    'description' => $description,
                    'specs'       => $specs,
                    'is_active'   => true,
                ]);

                $successCount++;

                if (count($lote) >= self::LOTE_CSV) {
                    Product::insert($lote);
                    $lote = [];
                }
            }

            if ($lote !== []) {
                Product::insert($lote);
                $lote = [];
            }

            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            fclose($handle);
            // AUD-8: el mensaje de la excepción NO viaja al navegador. Filtraba
            // SQL, nombres de columnas y rutas del servidor, y además lo hacía
            // pasara lo que pasara con APP_DEBUG. Al log, que es donde sirve.
            \Illuminate\Support\Facades\Log::error('Fallo al importar productos por CSV', [
                'tenant_id' => app()->bound('currentTenant') ? app('currentTenant')->id : null,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Ocurrió un error inesperado al procesar el archivo.',
            ], 500);
        }

        fclose($handle);

        if ($categoriasOmitidas) {
            $errors[] = 'Algunas categorías nuevas no se crearon porque tu plan ' . PlanGate::label() . ' no admite más: esos productos se importaron sin categoría.';
        }

        // El insert en lote no pasa por el hook `saved` del modelo, que es quien
        // sube la version de cache (AUD-6). Se sube una sola vez al terminar, lo
        // que ademas ahorra las N escrituras en cache que hacia el import cuando
        // guardaba fila a fila.
        if ($successCount > 0) {
            $this->invalidarCachePublica();
        }

        return response()->json([
            'message'       => "Proceso completado. Se importaron {$successCount} productos con éxito.",
            'success_count' => $successCount,
            'errors'        => $errors,
        ]);
    }

    /**
     * Aplica acciones masivas (activar, desactivar, borrar, ajustar precios) en lote.
     */
    public function bulkAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids'      => 'nullable|array',
            'product_ids.*'    => 'uuid|exists:products,id',
            'category_id'      => 'nullable|uuid|exists:categories,id',
            'bulk_action'      => 'required|string|in:activate,deactivate,delete,adjust_price',
            'price_adjustment' => 'nullable|numeric',
        ]);

        $action = $data['bulk_action'];
        $query = Product::query();

        if (!empty($data['product_ids'])) {
            $query->whereIn('id', $data['product_ids']);
        } elseif (!empty($data['category_id'])) {
            $query->where('category_id', $data['category_id']);
        } else {
            return response()->json([
                'message' => 'Debes seleccionar productos o una categoría para aplicar la acción.',
            ], 422);
        }

        if ($action === 'delete') {
            // AUD-23: la galeria se trae de una vez. Antes `$prod->images` era
            // una consulta por producto, asi que borrar 50 productos costaba 50
            // consultas solo para averiguar que imagenes tenian.
            $products = $query->with('images')->get();

            foreach ($products as $prod) {
                $this->imageService->deleteProductImages($prod->image_url, $prod->thumbnail_url);

                foreach ($prod->images as $img) {
                    $this->imageService->deleteProductImages($img->image_url, $img->thumbnail_url);
                }
            }

            if ($products->isNotEmpty()) {
                // Y un solo DELETE en vez de uno por producto. Las filas hijas
                // -galeria, resenias, favoritos, avisos de stock- caen por
                // ON DELETE CASCADE y las lineas de pedido se quedan con
                // `product_id` a null, exactamente igual que borrando de uno en
                // uno: el historial de ventas no se toca.
                Product::whereIn('id', $products->pluck('id'))->delete();

                // El DELETE masivo no pasa por el hook `deleted` del modelo, que
                // es quien sube la version de cache (AUD-6).
                $this->invalidarCachePublica();
            }

            return response()->json(['message' => 'Productos eliminados en lote con éxito.']);
        }

        // AUD-6: un `update()` masivo NO dispara los eventos del modelo, y la
        // invalidación de la caché pública vive en el hook `saved` de `Product`.
        // Sin esto, ocultar 30 productos de golpe para una liquidación los dejaba
        // visibles en el catálogo hasta 5 minutos, mientras que ocultar uno suelto
        // se veía al instante: desde el panel no había forma de entender por qué.
        // Se hace igual que en `reorder`, que ya lo resolvía así.
        if ($action === 'activate') {
            $query->update(['is_active' => true]);
            $this->invalidarCachePublica();

            return response()->json(['message' => 'Productos publicados en lote con éxito.']);
        }

        if ($action === 'deactivate') {
            $query->update(['is_active' => false]);
            $this->invalidarCachePublica();

            return response()->json(['message' => 'Productos ocultados en lote con éxito.']);
        }

        if ($action === 'adjust_price') {
            $percentage = (float) ($data['price_adjustment'] ?? 0);
            if ($percentage === 0.0) {
                return response()->json(['message' => 'El porcentaje de ajuste de precio debe ser distinto de cero.'], 422);
            }

            $products = $query->get();
            foreach ($products as $prod) {
                $multiplier = 1 + ($percentage / 100);
                $prod->price = round($prod->price * $multiplier, 2);
                if ($prod->sale_price !== null) {
                    $prod->sale_price = round($prod->sale_price * $multiplier, 2);
                }
                $prod->save();
            }

            return response()->json(['message' => "Precios ajustados un {$percentage}% con éxito en lote."]);
        }

        return response()->json(['message' => 'Acción no válida.'], 400);
    }

    /**
     * Replica un producto existente junto con su galería.
     */
    public function duplicate(Product $product): JsonResponse
    {
        // SAAS-3: duplicar crea un producto, asi que gasta tope. La galeria que
        // se copia NO se recorta al tope de imagenes del plan: son las que el
        // original ya tenia, y recortarlas seria perder contenido en silencio.
        // Los limites topan lo que se añade, no lo que ya existe.
        PlanGate::ensureCanCreate('products');

        $newProduct = $product->replicate();
        $newProduct->name = $product->name . ' (Copia)';
        // replicate() copia los atributos crudos sin pasar por los casts. Reasignamos
        // la descripcion para que vuelva a cruzar SanitizedHtml (SEC-3): si el original
        // se guardo antes de ese cast, la copia heredaria el HTML sin limpiar.
        $newProduct->description = $product->getRawOriginal('description');
        $newProduct->is_active = false;
        $newProduct->save();

        foreach ($product->images as $img) {
            $newProduct->images()->create([
                'image_url'     => $img->image_url,
                'thumbnail_url' => $img->thumbnail_url,
                'sort_order'    => $img->sort_order,
            ]);
        }

        return response()->json($newProduct->load(['category', 'images']), 201);
    }

    /**
     * Reordena los productos según el orden especificado.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|uuid|exists:products,id',
        ]);

        $tenant = app('currentTenant');

        // AUD-22: un solo UPDATE con CASE en vez de uno por producto. Reordenar
        // 200 productos eran 200 consultas -y 200 viajes a la base- para escribir
        // un entero en cada fila, y el panel guarda el orden en cada arrastre.
        //
        // `array_values` no es cosmetico: la posicion en la lista es lo que acaba
        // en `sort_order`, y si `ids` llega como objeto en vez de como lista las
        // claves vienen siendo texto.
        $ids = array_values($data['ids']);

        $casos = '';
        $bindings = [];

        foreach ($ids as $posicion => $id) {
            $casos .= ' WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $posicion;
        }

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $tabla = (new Product)->getTable();

        // Va en SQL a mano porque un `update()` de Eloquent con `DB::raw` no
        // liga los `?` del CASE: se mezclarian con los del WHERE. El filtro por
        // `tenant_id` sigue donde estaba, que es lo que impide reordenar los
        // productos de otra tienda pasando sus ids.
        \DB::update(
            "UPDATE {$tabla} SET sort_order = CASE id{$casos} END, updated_at = ? WHERE id IN ({$marcas}) AND tenant_id = ?",
            array_merge($bindings, [now()], $ids, [$tenant->id])
        );

        $this->invalidarCachePublica();

        return response()->json(['message' => 'Productos reordenados correctamente']);
    }

    /**
     * Sube la versión de caché de la tienda para que el catálogo público se vea
     * al instante (AUD-6).
     *
     * Hace falta solo cuando se escribe SIN pasar por el modelo —un `update()`
     * masivo, que no dispara eventos—. Lo que guarda producto a producto ya lo
     * hace el hook `saved` de `Product`.
     */
    private function invalidarCachePublica(): void
    {
        $tenant = app('currentTenant');

        \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
    }

    /**
     * Si el CSV trae mas filas de datos que el tope (AUD-10).
     *
     * Cuenta con `fgetcsv` y no por lineas porque un campo entrecomillado puede
     * llevar saltos de linea dentro, y corta en cuanto pasa del tope: de un
     * archivo enorme solo se leen las primeras MAX_FILAS_CSV + 1 filas.
     */
    private function excedeElTope(string $path, string $delimiter): bool
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return false;
        }

        // La cabecera no cuenta como fila de datos.
        fgetcsv($handle, 0, $delimiter);

        $filas = 0;

        while (fgetcsv($handle, 0, $delimiter) !== false) {
            $filas++;

            if ($filas > self::MAX_FILAS_CSV) {
                fclose($handle);

                return true;
            }
        }

        fclose($handle);

        return false;
    }

    /**
     * Fila del CSV -> fila lista para un INSERT en lote.
     *
     * Se arma con un modelo de verdad en vez de a mano para que los casts sigan
     * aplicandose igual que antes: `description` cruza SanitizedHtml (SEC-3) y
     * `specs` se serializa a JSON. A cambio, lo que el `insert()` masivo se
     * salta -el uuid de HasUuids, el `tenant_id` de BelongsToTenant y los
     * timestamps- hay que ponerlo aqui a mano.
     */
    private function filaAAtributos(array $datos): array
    {
        $producto = new Product($datos);
        $ahora = now();

        return array_merge($producto->getAttributes(), [
            'id'         => $producto->newUniqueId(),
            'tenant_id'  => app('currentTenant')->id,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

}
