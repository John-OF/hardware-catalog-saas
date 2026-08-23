<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\Category;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
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
        if ($request->has('deleted_image_ids')) {
            $deletedIds = is_array($request->deleted_image_ids) 
                ? $request->deleted_image_ids 
                : json_decode($request->deleted_image_ids, true) ?? [];

            foreach ($deletedIds as $imgId) {
                $imgModel = $product->images()->find($imgId);
                if ($imgModel) {
                    $this->imageService->deleteProductImages($imgModel->image_url, $imgModel->thumbnail_url);
                    $imgModel->delete();
                }
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
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowCount++;

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
                    $category = Category::firstOrCreate(
                        ['name' => $categoryName],
                        ['icon' => 'folder', 'sort_order' => 0, 'is_active' => true]
                    );
                    $categoryId = $category->id;
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

                Product::create([
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
            $products = $query->get();
            foreach ($products as $prod) {
                $this->imageService->deleteProductImages($prod->image_url, $prod->thumbnail_url);
                foreach ($prod->images as $img) {
                    $this->imageService->deleteProductImages($img->image_url, $img->thumbnail_url);
                }
                $prod->delete();
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

        foreach ($data['ids'] as $index => $id) {
            Product::where('id', $id)
                ->where('tenant_id', $tenant->id)
                ->update(['sort_order' => $index]);
        }

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
}
