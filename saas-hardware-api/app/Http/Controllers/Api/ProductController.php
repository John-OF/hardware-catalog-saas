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
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->active_only, fn($q) => $q->where('is_active', true))
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

        // Normalizar cabecera (minúsculas, sin espacios)
        $header = array_map(fn($col) => trim(strtolower($col)), $header);

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
                    $parts = explode(';', $rowSpecs);
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
            return response()->json([
                'message' => 'Ocurrió un error inesperado al procesar el archivo.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        fclose($handle);

        return response()->json([
            'message'       => "Proceso completado. Se importaron {$successCount} productos con éxito.",
            'success_count' => $successCount,
            'errors'        => $errors,
        ]);
    }
}
