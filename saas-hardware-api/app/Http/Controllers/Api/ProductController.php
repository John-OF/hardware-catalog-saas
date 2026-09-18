<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Category;
use App\Services\ImageService;
use App\Support\Bitacora;
use App\Support\Busqueda;
use App\Support\Costos;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    /**
     * Ejes que admite una variante del CSV (MOD-12): el mismo tope que el
     * formulario del panel (`ValidaVariantes`). Mas de tres ya no es una variante
     * sino otro producto.
     */
    private const MAX_OPCIONES_POR_VARIANTE = 3;

    /**
     * Qué hace el import con un producto del archivo que ya existe en la tienda
     * (FUN-17). "Existe" es que haya otro con el mismo nombre, sin mirar
     * mayúsculas, tildes ni espacios de más: es la misma clave con la que el
     * archivo agrupa las variantes (MOD-12).
     *
     * - `omitir`: lo deja como está y solo crea lo que falta. Por defecto, porque
     *   es el único que se puede repetir sin consecuencias: subir dos veces el
     *   mismo archivo no cambia nada la segunda.
     * - `actualizar`: le pone los datos del archivo. Una celda vacía NO borra.
     * - `duplicar`: crea otro igual, que es lo que hacía siempre antes de esto.
     */
    private const MODOS_DE_IMPORT = ['omitir', 'actualizar', 'duplicar'];

    /** Lo que el informe del import cuenta de una ficha: campo => cómo se llama (FUN-19). */
    private const CAMPOS_EN_INFORME = [
        'brand' => 'marca', 'sku' => 'SKU', 'price' => 'precio', 'sale_price' => 'oferta',
        'cost' => 'costo', 'stock' => 'stock', 'category' => 'categoría',
    ];

    private const CAMPOS_DE_VARIANTE_EN_INFORME = [
        'sku' => 'SKU', 'price' => 'precio', 'sale_price' => 'oferta', 'cost' => 'costo', 'stock' => 'stock',
    ];

    public function __construct(private ImageService $imageService) {}

    public function index(Request $request): JsonResponse
    {
        $products = Product::with(['category', 'images', 'variants'])
            ->withCount(['stockNotifications as waitlist_count' => fn($q) => $q->whereNull('notified_at')])
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            // INF-6: la misma busqueda que el catalogo publico y que la
            // exportacion. Antes esta no miraba la marca, asi que buscar
            // "Kingston" en el panel no encontraba lo que si encontraba un
            // comprador en la tienda.
            ->tap(fn ($q) => Busqueda::aplicar($q, $request->search))
            ->when($request->active_only, fn($q) => $q->where('is_active', true))
            ->tap(fn ($q) => Busqueda::ordenarPorRelevancia($q, $request->search))
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(Costos::mostrar($products));
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

        [$data, $variantes] = $this->separarVariantes($request->validated());

        if ($variantes) {
            // Provisional: `price` no admite null y el resumen de las variantes
            // lo pisa en cuanto se guarden, unas lineas mas abajo.
            $data += ['price' => 0, 'stock' => 0];
        }

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

        if ($variantes !== null) {
            $this->sincronizarVariantes($product, $variantes, $request);
        }

        Bitacora::anotar(
            ActivityLog::PRODUCTO_CREADO,
            "Creó el producto «{$product->name}».",
            ['producto_id' => $product->id],
        );

        return response()->json(Costos::mostrar($product->fresh()->load(['category', 'images', 'variants'])), 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(Costos::mostrar($product->load(['category', 'images', 'variants'])));
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

        [$data, $variantes] = $this->separarVariantes($request->validated());

        // INF-3: foto de antes, sobre una copia recién leída para no dejarle al
        // `$product` de este método relaciones cargadas que luego quedarían viejas.
        $antes = $this->fotoParaBitacora($product->fresh());

        // TEC-14: las fotos que dejan de usarse se juntan aquí y se borran al
        // final, cuando la base ya no les apunta, y solo si nadie más las usa.
        $sueltas = [];

        if ($request->hasFile('image')) {
            $sueltas[] = $product->image_url;
            $sueltas[] = $product->thumbnail_url;

            $tenant = app('currentTenant');
            $urls   = $this->imageService->uploadProductImage($request->file('image'), $tenant->slug);
            $data   = array_merge($data, $urls);
        }

        $product->update($data);

        // Eliminar imágenes de galería seleccionadas
        foreach ($deletedIds as $imgId) {
            $imgModel = $product->images()->find($imgId);
            if ($imgModel) {
                $sueltas[] = $imgModel->image_url;
                $sueltas[] = $imgModel->thumbnail_url;
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

        if ($variantes !== null) {
            $this->sincronizarVariantes($product, $variantes, $request);
        }

        $this->imageService->borrarSiNadieLasUsa($sueltas);

        $this->anotarEdicion($antes, $product->fresh());

        return response()->json(Costos::mostrar($product->fresh()->load(['category', 'images', 'variants'])));
    }

    public function destroy(Product $product): JsonResponse
    {
        // MOD-8: esto ya no borra nada, lo manda a la papelera. **Las fotos se
        // quedan en disco a propósito**: borrarlas aquí haría que restaurar
        // devolviera un producto sin imágenes, que es media restauración y la
        // peor mitad. Los archivos los borra `TrashController` cuando la
        // eliminación es definitiva, con las mismas reglas de TEC-14.
        $product->delete();

        Bitacora::anotar(
            ActivityLog::PRODUCTO_BORRADO,
            "Envió a la papelera el producto «{$product->name}».",
            ['producto_id' => $product->id, 'sku' => $product->sku],
        );

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

        $validado = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:4096',
            'modo' => 'nullable|in:'.implode(',', self::MODOS_DE_IMPORT),
        ]);

        $modo = $validado['modo'] ?? 'omitir';

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

        $creados = 0;
        $actualizados = 0;
        $sinCambios = 0;
        $omitidos = 0;
        $errors = [];
        // Lo que paso con cada producto del archivo, para el informe (FUN-19):
        // una entrada por ficha, igual que `$errors` lleva una por fila mala.
        $cambios = [];
        $rowCount = 1;
        $lote = [];
        $loteVariantes = [];

        // MOD-12: el archivo se agrupa en fichas antes de escribir nada. Una fila
        // sin `variante` es una ficha suelta; las que la traen se juntan por
        // `nombre`. Cabe en memoria porque el archivo ya esta topado a
        // MAX_FILAS_CSV filas, que es la razon por la que ese tope existe.
        $fichas = [];

        // Las fichas que van a CREAR un producto, que son las unicas que gastan
        // hueco del plan: actualizar u omitir uno que ya existe no ocupa nada.
        $fichasQueCrean = 0;
        // Productos nuevos que no cupieron en el plan, por ficha.
        $sinHueco = [];
        // FUN-18: grupos de variantes cuya primera fila se rechazo por la
        // categoria. Sin esto, la segunda fila del grupo pasaria a describir la
        // ficha y, con su categoria vacia, el producto entraria sin clasificar.
        $gruposRechazados = [];

        // Mapear índices
        $map = [
            'nombre'           => array_search('nombre', $header),
            'marca'            => array_search('marca', $header),
            'sku'              => array_search('sku', $header),
            'precio'           => array_search('precio', $header),
            'precio_oferta'    => array_search('precio_oferta', $header),
            // MOD-6 / MOD-7: el costo de compra, para que lo exportado se pueda
            // volver a importar sin perderlo. Solo lo lee un admin; ver mas abajo.
            'costo'            => array_search('costo', $header),
            'stock'            => array_search('stock', $header),
            'categoria'        => array_search('categoria', $header),
            'descripcion'      => array_search('descripcion', $header),
            'especificaciones' => array_search('especificaciones', $header),
            // MOD-12: la columna que convierte una fila en una variante. Vacia o
            // ausente, todo se comporta como antes; por eso no hay fallback por
            // posicion para ella.
            'variante'         => array_search('variante', $header),
        ];

        // Alternativas en inglés
        if ($map['nombre'] === false) $map['nombre'] = array_search('name', $header);
        if ($map['marca'] === false) $map['marca'] = array_search('brand', $header);
        if ($map['precio'] === false) $map['precio'] = array_search('price', $header);
        if ($map['precio_oferta'] === false) $map['precio_oferta'] = array_search('sale_price', $header);
        if ($map['costo'] === false) $map['costo'] = array_search('cost', $header);
        if ($map['stock'] === false) $map['stock'] = array_search('stock', $header);
        if ($map['categoria'] === false) $map['categoria'] = array_search('category', $header);
        if ($map['descripcion'] === false) $map['descripcion'] = array_search('description', $header);
        if ($map['especificaciones'] === false) $map['especificaciones'] = array_search('specs', $header);
        if ($map['variante'] === false) $map['variante'] = array_search('variant', $header);

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
            $huecoProductos = PlanGate::hueco('products');

            // FUN-17: lo que ya hay en la tienda, por nombre normalizado, leido una
            // sola vez. Una lista de ids y no uno: si la tienda ya tiene dos
            // productos con el mismo nombre, actualizar no sabria cual.
            //
            // Lo que esta en la papelera no cuenta: ya no esta en el catalogo, y
            // omitir un producto porque existe uno borrado dejaria al dueño sin
            // el producto y sin saber por que.
            $existentes = [];

            foreach (Product::query()->get(['id', 'name']) as $producto) {
                $existentes[$this->claveDeNombre($producto->name)][] = $producto->id;
            }

            // FUN-18: el import ya no crea categorias; solo usa las que hay. Se
            // leen todas de una vez —una tienda tiene decenas, no miles— y se
            // comparan con la misma normalizacion que los productos, para que
            // "procesadores" o "Tarjetas graficas" encuentren la suya.
            $categorias = [];
            // Y al reves, para poder decir "categoría Procesadores → Memorias"
            // en el informe de lo que se actualizo.
            $nombresDeCategoria = [];

            foreach (Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']) as $categoria) {
                $categorias[$this->claveDeNombre($categoria->name)] ??= $categoria->id;
                $nombresDeCategoria[$categoria->id] = $categoria->name;
            }

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

                $sku = $map['sku'] !== false && isset($row[$map['sku']]) ? trim($row[$map['sku']]) : null;

                // El costo solo entra si quien importa es un admin: si no, la
                // columna se ignora en silencio, como el formulario ignora el
                // campo (MOD-6).
                $costoStr = $map['costo'] !== false && isset($row[$map['costo']]) ? trim($row[$map['costo']]) : '';
                $costo = Costos::usuarioPuedeVerlos() && is_numeric($costoStr) && (float) $costoStr >= 0
                    ? (float) $costoStr
                    : null;

                $stockStr = $map['stock'] !== false && isset($row[$map['stock']]) ? trim($row[$map['stock']]) : '';
                $stock = is_numeric($stockStr) ? (int) $stockStr : null;

                if ($stock === null || $stock < 0) {
                    $errors[] = "Fila {$rowCount}: El stock '{$stockStr}' no es válido (debe ser un entero >= 0).";
                    continue;
                }

                // MOD-12: aqui se decide si la fila es una ficha o una variante.
                // Sin la columna `variante` (o vacia) cada fila es su propia
                // ficha aunque se repita el nombre, que es lo que hacia el
                // importador antes de MOD-12 y lo que sigue leyendo todo CSV ya
                // escrito. Con ella, la fila se cuelga de la ficha que se llame
                // igual, exista ya en este archivo o la estrene.
                $textoVariante = $map['variante'] !== false && isset($row[$map['variante']]) ? trim($row[$map['variante']]) : '';
                $claveDeFicha  = $textoVariante === '' ? "fila:{$rowCount}" : 'grupo:'.$this->claveDeNombre($name);
                $fichaEsNueva  = ! isset($fichas[$claveDeFicha]);

                // El resto de un grupo que no cupo en el plan ya esta contado
                // en el aviso del tope: repetirlo por cada variante no informa.
                if (isset($sinHueco[$claveDeFicha])) {
                    continue;
                }

                if (isset($gruposRechazados[$claveDeFicha])) {
                    $errors[] = "Fila {$rowCount}: No se importó porque «{$name}» se describe en la fila {$gruposRechazados[$claveDeFicha]}, que tiene una categoría que no existe.";
                    continue;
                }

                // FUN-17: si la ficha ya existe en la tienda, y si por tanto va a
                // crear algo o no. Solo lo que crea gasta hueco del plan.
                $idsExistentes = $existentes[$this->claveDeNombre($name)] ?? [];
                $creaProducto  = $idsExistentes === [] || $modo === 'duplicar';

                $opciones = [];

                if ($textoVariante !== '') {
                    $opciones = $this->opcionesDeVariante($textoVariante);

                    if ($opciones === []) {
                        $errors[] = "Fila {$rowCount}: La variante '{$textoVariante}' no tiene ninguna opción legible (por ejemplo, 'Capacidad: 16 GB').";
                        continue;
                    }

                    if (count($opciones) > self::MAX_OPCIONES_POR_VARIANTE) {
                        $errors[] = "Fila {$rowCount}: Una variante admite como mucho ".self::MAX_OPCIONES_POR_VARIANTE.' opciones.';
                        continue;
                    }

                    if (! $fichaEsNueva) {
                        if (count($fichas[$claveDeFicha]['variantes']) >= ProductVariant::MAXIMO_POR_PRODUCTO) {
                            $errors[] = "Fila {$rowCount}: «{$name}» ya tiene ".ProductVariant::MAXIMO_POR_PRODUCTO.' variantes, el máximo por producto.';
                            continue;
                        }

                        // Dos variantes con las mismas opciones serian dos botones
                        // iguales con precios distintos en la ficha, igual que en
                        // el formulario del panel (`ValidaVariantes`).
                        if (isset($fichas[$claveDeFicha]['vistas'][$this->claveDeOpciones($opciones)])) {
                            $errors[] = "Fila {$rowCount}: «{$name}» ya tiene una variante con esas mismas opciones.";
                            continue;
                        }
                    }
                }

                // El tope del plan corta el import, no lo rechaza entero: entra
                // lo que cabe y el resto se reporta como aviso. Rechazar un
                // archivo de 300 filas porque las 10 ultimas no caben obligaria
                // al dueño a editar el CSV para no perder las 290 que si.
                //
                // Cuenta fichas, no filas: un producto de ocho variantes ocupa un
                // hueco del plan, no ocho. Por eso una fila que se cuelga de una
                // ficha ya empezada nunca choca con el tope.
                //
                // Salta la ficha y no corta el archivo (FUN-17): despues puede
                // venir un producto que ya existe, y actualizarlo u omitirlo no
                // gasta hueco.
                if ($fichaEsNueva && $creaProducto && $huecoProductos !== null && $fichasQueCrean >= $huecoProductos) {
                    $sinHueco[$claveDeFicha] = true;
                    continue;
                }

                // Lo que describe la ficha —marca, categoria, descripcion, specs—
                // se lee de la PRIMERA fila del grupo; en las siguientes se ignora.
                // Repetirlo en cada fila y quedarse con lo ultimo haria que el
                // orden de las filas cambiara el resultado sin que se note.
                if (! $fichaEsNueva) {
                    $variante = $this->varianteDeFila($opciones, $sku, $price, $salePrice, $costo, $stock);
                    $fichas[$claveDeFicha]['variantes'][] = $variante;
                    $fichas[$claveDeFicha]['vistas'][$this->claveDeOpciones($opciones)] = true;
                    continue;
                }

                $categoryName = $map['categoria'] !== false && isset($row[$map['categoria']]) ? trim($row[$map['categoria']]) : '';
                $categoryId = null;

                // FUN-18: una categoria que no existe rechaza la fila. Antes se
                // creaba sola, y asi es como una tienda acababa con
                // "Procesadores" y "Processors" a la vez: la nueva nacia sin tipo
                // de componente, asi que el armador (FUN-8) no veia nada de lo
                // que entraba en ella. Se rechaza en vez de importar sin
                // categoria porque eso esconderia el mismo error en silencio, y
                // con `omitir` volver a subir el archivo corregido es inocuo.
                if ($categoryName !== '') {
                    $categoryId = $categorias[$this->claveDeNombre($categoryName)] ?? null;

                    if ($categoryId === null) {
                        $errors[] = "Fila {$rowCount}: La categoría «{$categoryName}» no existe en tu tienda. Créala en Categorías o usa una de las que ya tienes.";

                        if ($textoVariante !== '') {
                            $gruposRechazados[$claveDeFicha] = $rowCount;
                        }

                        continue;
                    }
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

                if ($creaProducto) {
                    $fichasQueCrean++;
                }

                $fichas[$claveDeFicha] = [
                    'fila'       => $rowCount,
                    // Vacio si la ficha crea un producto; si no, a quien se
                    // parece en la tienda (FUN-17).
                    'existentes' => $creaProducto ? [] : $idsExistentes,
                    'atributos'  => [
                        'name'        => $name,
                        'brand'       => $brand,
                        // Con variantes, estas cuatro columnas describen a la
                        // variante de la fila y no a la ficha: el resumen de la
                        // ficha lo escribe `Product::resumenDeVariantes()` al
                        // cerrar el archivo (MOD-5).
                        'sku'         => $textoVariante === '' ? ($sku ?: null) : null,
                        'price'       => $price,
                        'sale_price'  => $salePrice,
                        'cost'        => $costo,
                        'stock'       => $stock,
                        'category_id' => $categoryId,
                        'description' => $description,
                        'specs'       => $specs,
                        'is_active'   => true,
                    ],
                    'variantes'  => [],
                    'vistas'     => [],
                ];

                if ($opciones !== []) {
                    $fichas[$claveDeFicha]['variantes'][] = $this->varianteDeFila($opciones, $sku, $price, $salePrice, $costo, $stock);
                    $fichas[$claveDeFicha]['vistas'][$this->claveDeOpciones($opciones)] = true;
                }
            }

            // FUN-17: las que ya existen se omiten o se apartan para actualizar;
            // el resto se crea en lote como siempre.
            $porActualizar = [];

            foreach ($fichas as $ficha) {
                if ($ficha['existentes'] !== []) {
                    if ($modo === 'omitir') {
                        $omitidos++;
                        $cambios[] = $this->entradaDelInforme($ficha, 'omitido');
                    } elseif (count($ficha['existentes']) > 1) {
                        $errors[] = "Fila {$ficha['fila']}: Hay ".count($ficha['existentes'])." productos llamados «{$ficha['atributos']['name']}» en tu tienda y no se sabe cuál actualizar. Deja uno solo y vuelve a importar.";
                    } else {
                        $porActualizar[] = $ficha;
                    }

                    continue;
                }

                $creados++;
                $cambios[] = $this->entradaDelInforme(
                    $ficha,
                    'creado',
                    $ficha['variantes'] === [] ? null : 'con '.count($ficha['variantes']).(count($ficha['variantes']) === 1 ? ' variante' : ' variantes'),
                );
                $atributos = $ficha['atributos'];

                if ($ficha['variantes'] !== []) {
                    $atributos = array_merge($atributos, Product::resumenDeVariantes($ficha['variantes']));
                }

                $fila   = $this->filaAAtributos($atributos);
                $lote[] = $fila;

                foreach ($ficha['variantes'] as $orden => $variante) {
                    $loteVariantes[] = $this->varianteAAtributos($fila['id'], $variante, $orden);
                }

                // Los productos se escriben SIEMPRE antes que sus variantes: al
                // reves la clave ajena de `product_id` apunta a una fila que
                // todavia esta en `$lote`.
                if (count($lote) >= self::LOTE_CSV) {
                    Product::insert($lote);
                    $lote = [];

                    if ($loteVariantes !== []) {
                        ProductVariant::insert($loteVariantes);
                        $loteVariantes = [];
                    }
                }
            }

            if ($lote !== []) {
                Product::insert($lote);
                $lote = [];
            }

            if ($loteVariantes !== []) {
                ProductVariant::insert($loteVariantes);
                $loteVariantes = [];
            }

            // Actualizar va producto a producto y por el modelo, no en lote: cada
            // uno cambia columnas distintas, y asi los hooks hacen su trabajo
            // —la cache publica y el aviso de "ya llegó" si el stock vuelve—.
            if ($porActualizar !== []) {
                $productos = Product::with('variants')
                    ->whereIn('id', array_map(fn (array $f) => $f['existentes'][0], $porActualizar))
                    ->get()
                    ->keyBy('id');

                foreach ($porActualizar as $ficha) {
                    // Solo falta si alguien lo borro mientras se leia el archivo.
                    $producto = $productos->get($ficha['existentes'][0]);

                    if (! $producto) {
                        continue;
                    }

                    $queCambio = $this->actualizarDesdeCsv($producto, $ficha, $nombresDeCategoria, $errors);

                    // null: la fila se rechazo entera y ya esta en `$errors`.
                    if ($queCambio === null) {
                        continue;
                    }

                    // FUN-19: "actualizado" solo si algo cambio de verdad. Subir el
                    // catalogo recien exportado en este modo no toca nada, y el
                    // informe tiene que decir eso y no "se actualizaron 28".
                    if ($queCambio === []) {
                        $sinCambios++;
                        $cambios[] = $this->entradaDelInforme($ficha, 'sin_cambios');
                    } else {
                        $actualizados++;
                        $cambios[] = $this->entradaDelInforme($ficha, 'actualizado', implode('; ', $queCambio));
                    }
                }
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

        if ($sinHueco !== []) {
            $noCaben = count($sinHueco);

            $errors[] = 'Tu plan '.PlanGate::label()
                .($huecoProductos > 0 ? " admite {$huecoProductos} productos más" : ' no admite más productos').': '
                .($noCaben === 1 ? '1 producto nuevo del archivo no se importó.' : "{$noCaben} productos nuevos del archivo no se importaron.");
        }

        $resumen = $this->resumenDelImport($creados, $actualizados, $sinCambios, $omitidos);

        // En el orden del archivo: se crean en lote y se actualizan despues, asi
        // que sin esto el informe no se leeria de arriba abajo como el CSV.
        usort($cambios, fn (array $a, array $b) => $a['fila'] <=> $b['fila']);

        // El insert en lote no pasa por el hook `saved` del modelo, que es quien
        // sube la version de cache (AUD-6). Se sube una sola vez al terminar, lo
        // que ademas ahorra las N escrituras en cache que hacia el import cuando
        // guardaba fila a fila.
        if ($creados > 0) {
            $this->invalidarCachePublica();
        }

        if ($creados + $actualizados > 0) {
            Bitacora::anotar(
                ActivityLog::PRODUCTO_IMPORTADOS,
                "Importó un CSV: {$resumen}.",
                [
                    'modo'         => $modo,
                    'creados'      => $creados,
                    'actualizados' => $actualizados,
                    'sin_cambios'  => $sinCambios,
                    'omitidos'     => $omitidos,
                    'avisos'       => count($errors),
                    // FUN-19: que quede guardado QUÉ cambió, no solo cuántos: el
                    // mismo precio cambiado a mano deja su "de → a" en la
                    // actividad (INF-3). Con tope, como la lista de nombres del
                    // borrado en lote. Ojo: la pantalla de Actividad todavia no
                    // pinta el `context`, solo la descripcion.
                    'cambios'      => array_slice(
                        array_values(array_filter($cambios, fn (array $c) => in_array($c['accion'], ['creado', 'actualizado'], true))),
                        0,
                        100,
                    ),
                ],
            );
        }

        return response()->json([
            'message'         => $cambios !== []
                ? 'Proceso completado: '.$resumen.'.'
                : 'No se importó ningún producto.',
            // Lo que se escribio: creados mas actualizados. Los omitidos no, que
            // es justo lo que el dueño pidio que no se tocara.
            'success_count'   => $creados + $actualizados,
            'created_count'   => $creados,
            'updated_count'   => $actualizados,
            'unchanged_count' => $sinCambios,
            'skipped_count'   => $omitidos,
            'changes'         => $cambios,
            'errors'          => $errors,
        ]);
    }

    /**
     * Una línea del informe: qué pasó con un producto del archivo (FUN-19).
     *
     * @param  array<string, mixed>  $ficha
     * @return array{fila: int, accion: string, producto: string, detalle: string|null}
     */
    private function entradaDelInforme(array $ficha, string $accion, ?string $detalle = null): array
    {
        return [
            'fila'     => $ficha['fila'],
            'accion'   => $accion,
            'producto' => $ficha['atributos']['name'],
            'detalle'  => $detalle,
        ];
    }

    /**
     * "se crearon 3 productos, se actualizaron 2, 20 ya estaban al día y se
     * omitieron 5 que ya existían".
     */
    private function resumenDelImport(int $creados, int $actualizados, int $sinCambios, int $omitidos): string
    {
        $partes = [];

        if ($creados > 0) {
            $partes[] = $creados === 1 ? 'se creó 1 producto' : "se crearon {$creados} productos";
        }

        if ($actualizados > 0) {
            $partes[] = $actualizados === 1 ? 'se actualizó 1' : "se actualizaron {$actualizados}";
        }

        if ($sinCambios > 0) {
            $partes[] = $sinCambios === 1 ? '1 ya estaba al día' : "{$sinCambios} ya estaban al día";
        }

        if ($omitidos > 0) {
            $partes[] = $omitidos === 1
                ? 'se omitió 1 que ya existía'
                : "se omitieron {$omitidos} que ya existían";
        }

        if ($partes === []) {
            return 'no se importó ningún producto';
        }

        $ultima = array_pop($partes);

        return $partes === [] ? $ultima : implode(', ', $partes).' y '.$ultima;
    }

    /**
     * Pone los datos de una ficha del CSV en un producto que ya existe (FUN-17).
     *
     * **Una celda vacía no borra nada.** Es la trampa que dejó escrita MOD-12 al
     * aplazar esto: en un archivo hecho a mano, vacío casi siempre es "no lo sé"
     * o "no lo toques", y si significara "bórralo", una columna de descripciones
     * a medio rellenar vaciaría las demás sin avisar. Es el mismo criterio que el
     * costo de MOD-6 (ausente es "no lo toques"). El precio con su oferta es el
     * único cruce que hay que mirar: una oferta que se conserva tiene que seguir
     * siendo menor que el precio nuevo.
     *
     * Las variantes se emparejan por sus opciones, sin mayúsculas ni orden
     * (`claveDeOpciones`, la misma huella que detecta repetidas). Las que el
     * archivo no nombra se quedan como están: tampoco se borran.
     *
     * Devuelve qué cambió, en frases para el informe (FUN-19): una lista vacía
     * si el archivo traía lo mismo que ya había —y entonces no se guarda nada—,
     * y `null` si la ficha se rechazó entera, con el motivo ya en `$errors`.
     *
     * @param  array<string, mixed>  $ficha
     * @param  array<string, string>  $nombresDeCategoria  id => nombre
     * @param  array<int, string>  $errors
     * @return array<int, string>|null
     */
    private function actualizarDesdeCsv(Product $producto, array $ficha, array $nombresDeCategoria, array &$errors): ?array
    {
        $fila = $ficha['fila'];
        $nombre = $producto->name;

        // El nombre es la clave con la que se encontró, y `is_active` el import
        // no lo lee: los dos se quedan como estaban.
        $cambios = $this->soloLoQueTrae(array_diff_key($ficha['atributos'], ['name' => true, 'is_active' => true]));

        if ($ficha['variantes'] === []) {
            // Con variantes, precio y stock de la ficha son un resumen que no se
            // cobra (MOD-5): ponerle un numero a mano lo desalinearia de lo que
            // de verdad se vende.
            if ($producto->variants->isNotEmpty()) {
                $errors[] = "Fila {$fila}: «{$nombre}» tiene variantes en tu tienda. Para actualizarlo, pon una fila por variante con la columna variante.";

                return null;
            }

            if (! $this->ofertaSigueSiendoMenor($cambios, $producto->sale_price)) {
                $errors[] = "Fila {$fila}: El precio nuevo de «{$nombre}» ({$cambios['price']}) no es mayor que la oferta que ya tiene ({$producto->sale_price}). Pon la oferta en el archivo o quítala desde el panel.";

                return null;
            }

            return array_filter([$this->guardarYDescribir($producto, $cambios, $nombresDeCategoria)]);
        }

        // Con variantes, el precio, la oferta, el costo, el stock y el SKU de la
        // fila son de la variante, no de la ficha.
        $deLaFicha = array_diff_key($cambios, array_flip(['sku', 'price', 'sale_price', 'cost', 'stock']));

        $porOpciones = $producto->variants->keyBy(fn (ProductVariant $v) => $this->claveDeOpciones($v->options));
        $total = $producto->variants->count();
        $orden = $producto->variants->isEmpty() ? -1 : (int) $producto->variants->max('sort_order');
        $deVariantes = [];
        $rechazadas = 0;

        foreach ($ficha['variantes'] as $variante) {
            $opciones = $this->nombreDeVariante($variante['options']);
            $existente = $porOpciones->get($this->claveDeOpciones($variante['options']));

            $datos = $this->soloLoQueTrae(array_diff_key($variante, ['options' => true]));

            if (! $this->ofertaSigueSiendoMenor($datos, $existente?->sale_price)) {
                $errors[] = "Fila {$fila}: El precio nuevo de «{$nombre}» ({$opciones}) no es mayor que la oferta que ya tiene ({$existente->sale_price}). Pon la oferta en el archivo o quítala desde el panel.";
                $rechazadas++;

                continue;
            }

            if ($existente) {
                $antes = $this->fotoDeVariante($existente);
                $existente->fill($datos);

                if ($existente->isDirty()) {
                    $existente->save();
                    $queCambioEnLaVariante = Bitacora::resumirCambios(
                        Bitacora::cambios($antes, $this->fotoDeVariante($existente), self::CAMPOS_DE_VARIANTE_EN_INFORME),
                    );
                    $deVariantes[] = "variante {$opciones}".($queCambioEnLaVariante !== '' ? " ({$queCambioEnLaVariante})" : '');
                }

                continue;
            }

            if ($total >= ProductVariant::MAXIMO_POR_PRODUCTO) {
                $errors[] = "Fila {$fila}: «{$nombre}» ya tiene ".ProductVariant::MAXIMO_POR_PRODUCTO." variantes, el máximo por producto: {$opciones} no se añadió.";
                $rechazadas++;

                continue;
            }

            $producto->variants()->create($datos + [
                'options'    => $variante['options'],
                'sort_order' => ++$orden,
            ]);

            $total++;
            $deVariantes[] = "variante nueva {$opciones}";
        }

        $queCambio = array_values(array_filter([
            $this->guardarYDescribir($producto, $deLaFicha, $nombresDeCategoria),
            ...$deVariantes,
        ]));

        if ($deVariantes !== []) {
            $producto->sincronizarResumenDeVariantes();
        }

        if ($queCambio === [] && $rechazadas === count($ficha['variantes'])) {
            return null;
        }

        return $queCambio;
    }

    /**
     * Guarda lo que el archivo cambia de una ficha y dice qué fue: "precio 900 →
     * 950, stock 1 → 12, descripción". Vacío si no cambió nada, y entonces no
     * se guarda: un `save()` sin cambios subiría la versión de la caché pública
     * de la tienda por nada.
     *
     * Mismo formato que la actividad de una edición a mano (`anotarEdicion`,
     * INF-3), incluido que de la descripción y las especificaciones solo se dice
     * que cambiaron: un texto largo no cabe en una línea.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $nombresDeCategoria
     */
    private function guardarYDescribir(Product $producto, array $datos, array $nombresDeCategoria): string
    {
        if ($datos === []) {
            return '';
        }

        $antes = $this->fotoDeImport($producto, $nombresDeCategoria);
        $producto->fill($datos);

        if (! $producto->isDirty()) {
            return '';
        }

        $despues = $this->fotoDeImport($producto, $nombresDeCategoria);
        $producto->save();

        $partes = [Bitacora::resumirCambios(Bitacora::cambios($antes, $despues, self::CAMPOS_EN_INFORME))];

        foreach (['description' => 'descripción', 'specs' => 'especificaciones'] as $campo => $etiqueta) {
            if ($antes[$campo] !== $despues[$campo]) {
                $partes[] = $etiqueta;
            }
        }

        return implode(', ', array_filter($partes));
    }

    /**
     * @param  array<string, string>  $nombresDeCategoria
     * @return array<string, mixed>
     */
    private function fotoDeImport(Product $producto, array $nombresDeCategoria): array
    {
        return [
            'brand'       => $producto->brand,
            'sku'         => $producto->sku,
            'price'       => $producto->price,
            'sale_price'  => $producto->sale_price,
            'cost'        => $producto->cost,
            'stock'       => $producto->stock,
            'category'    => $nombresDeCategoria[$producto->category_id] ?? null,
            // Crudos: la descripción que devuelve el modelo ya pasó por su cast.
            'description' => (string) ($producto->getAttributes()['description'] ?? ''),
            'specs'       => json_encode($producto->specs),
        ];
    }

    /** @return array<string, mixed> */
    private function fotoDeVariante(ProductVariant $variante): array
    {
        return $variante->only(['sku', 'price', 'sale_price', 'cost', 'stock']);
    }

    /**
     * Lo que la fila sí trae: fuera las celdas vacías, que al actualizar
     * significan "no lo toques". Unas specs que no se pudieron leer (`[]`)
     * tampoco cuentan: si no, una columna mal escrita borraría las que había.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function soloLoQueTrae(array $datos): array
    {
        return array_filter($datos, fn ($valor) => $valor !== null && $valor !== '' && $valor !== []);
    }

    /**
     * Si la oferta con la que queda el producto sigue por debajo del precio.
     *
     * La fila ya se validó sola al leerla (oferta < precio cuando trae las dos).
     * Lo que falta es el cruce con lo guardado: si la fila no trae oferta, se
     * conserva la que había, y un precio nuevo puede haberla dejado por encima.
     *
     * @param  array<string, mixed>  $datos
     */
    private function ofertaSigueSiendoMenor(array $datos, mixed $ofertaGuardada): bool
    {
        $oferta = $datos['sale_price'] ?? $ofertaGuardada;

        return $oferta === null || (float) $oferta < (float) $datos['price'];
    }

    /**
     * "Capacidad: 1 TB / Color: Negro", para nombrar una variante en un aviso.
     *
     * @param  array<int, array{name: string, value: string}>  $opciones
     */
    private function nombreDeVariante(array $opciones): string
    {
        return collect($opciones)->map(fn (array $o) => "{$o['name']}: {$o['value']}")->implode(' / ');
    }

    /**
     * La clave con la que el import reconoce un producto o una categoría por su
     * nombre (FUN-17, FUN-18): sin mayúsculas, sin tildes y con los espacios
     * colapsados, para que "Tarjetas  gráficas" encuentre "tarjetas graficas".
     *
     * Se normaliza en PHP y no se le deja a la base porque la suite corre sobre
     * SQLite, que compara distinguiendo mayúsculas y tildes, y producción sobre
     * MySQL, que no: la misma consulta encontraría cosas distintas en cada una.
     */
    private function claveDeNombre(string $nombre): string
    {
        $limpio = mb_strtolower(preg_replace('/\s+/u', ' ', trim($nombre)));
        $sinTildes = Str::ascii($limpio);

        // Un nombre sin ningún carácter latino se quedaría vacío al pasarlo a
        // ASCII, y todos los así chocarían en la misma clave.
        return $sinTildes !== '' ? $sinTildes : $limpio;
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
            $products = $query->get(['id', 'name']);

            if ($products->isNotEmpty()) {
                // MOD-8: un solo UPDATE marcando `deleted_at`, en vez de un
                // DELETE. Las filas hijas -galeria, variantes, resenias,
                // favoritos, avisos de stock- se quedan intactas: el
                // ON DELETE CASCADE solo salta cuando la papelera se vacia de
                // verdad. Las fotos tampoco se tocan, por lo mismo que en
                // `destroy`.
                Product::whereIn('id', $products->pluck('id'))->delete();

                // El UPDATE masivo no pasa por el hook `deleted` del modelo, que
                // es quien sube la version de cache (AUD-6).
                $this->invalidarCachePublica();

                $this->anotarLote(
                    "Envió a la papelera en lote {$products->count()} productos",
                    $data,
                    ['accion' => 'delete', 'cantidad' => $products->count(), 'productos' => $products->pluck('name')->take(50)->all()],
                );
            }

            return response()->json(['message' => 'Productos enviados a la papelera con éxito.']);
        }

        // AUD-6: un `update()` masivo NO dispara los eventos del modelo, y la
        // invalidación de la caché pública vive en el hook `saved` de `Product`.
        // Sin esto, ocultar 30 productos de golpe para una liquidación los dejaba
        // visibles en el catálogo hasta 5 minutos, mientras que ocultar uno suelto
        // se veía al instante: desde el panel no había forma de entender por qué.
        // Se hace igual que en `reorder`, que ya lo resolvía así.
        if ($action === 'activate') {
            $afectados = $query->update(['is_active' => true]);
            $this->invalidarCachePublica();

            $this->anotarLote("Publicó en lote {$afectados} productos", $data, ['accion' => 'activate', 'cantidad' => $afectados]);

            return response()->json(['message' => 'Productos publicados en lote con éxito.']);
        }

        if ($action === 'deactivate') {
            $afectados = $query->update(['is_active' => false]);
            $this->invalidarCachePublica();

            $this->anotarLote("Ocultó en lote {$afectados} productos", $data, ['accion' => 'deactivate', 'cantidad' => $afectados]);

            return response()->json(['message' => 'Productos ocultados en lote con éxito.']);
        }

        if ($action === 'adjust_price') {
            $percentage = (float) ($data['price_adjustment'] ?? 0);
            if ($percentage === 0.0) {
                return response()->json(['message' => 'El porcentaje de ajuste de precio debe ser distinto de cero.'], 422);
            }

            $products = $query->with('variants')->get();
            $multiplier = 1 + ($percentage / 100);

            foreach ($products as $prod) {
                // MOD-5: con variantes se ajusta cada una y la ficha se recalcula;
                // ajustar solo el resumen lo dejaria desalineado de lo que se cobra.
                if ($prod->variants->isNotEmpty()) {
                    foreach ($prod->variants as $variante) {
                        $variante->price = round($variante->price * $multiplier, 2);
                        if ($variante->sale_price !== null) {
                            $variante->sale_price = round($variante->sale_price * $multiplier, 2);
                        }
                        $variante->save();
                    }

                    $prod->sincronizarResumenDeVariantes();

                    continue;
                }

                $prod->price = round($prod->price * $multiplier, 2);
                if ($prod->sale_price !== null) {
                    $prod->sale_price = round($prod->sale_price * $multiplier, 2);
                }
                $prod->save();
            }

            $signo = $percentage > 0 ? '+' : '';
            $this->anotarLote(
                "Ajustó los precios un {$signo}{$percentage}% en {$products->count()} productos",
                $data,
                ['accion' => 'adjust_price', 'porcentaje' => $percentage, 'cantidad' => $products->count()],
            );

            return response()->json(['message' => "Precios ajustados un {$percentage}% con éxito en lote."]);
        }

        return response()->json(['message' => 'Acción no válida.'], 400);
    }

    /**
     * Replica un producto existente junto con su galería.
     *
     * La copia apunta a los MISMOS archivos que el original —foto principal,
     * galería y variantes—; no se copian. Es seguro porque ningún borrado quita un
     * archivo que otra fila siga usando (`ImageService::borrarSiNadieLasUsa`,
     * TEC-14): antes de eso, borrar uno de los dos rompía las fotos del otro.
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

        // MOD-5: la copia lleva las mismas variantes, con su stock. Las fotos se
        // comparten igual que las de la galeria (misma URL).
        foreach ($product->variants as $variante) {
            $copia = $variante->replicate(['nombre']);
            $copia->product_id = $newProduct->id;
            $copia->save();
        }

        Bitacora::anotar(
            ActivityLog::PRODUCTO_DUPLICADO,
            "Duplicó «{$product->name}» (la copia queda oculta).",
            ['producto_id' => $product->id, 'copia_id' => $newProduct->id],
        );

        return response()->json(Costos::mostrar($newProduct->load(['category', 'images', 'variants'])), 201);
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
    /**
     * Saca las variantes de lo validado (MOD-5).
     *
     * Devuelve los atributos del producto y la lista de variantes: `null` si el
     * formulario no las mando (no se tocan), un array —vacio incluido— si las
     * mando. Con variantes, el precio y el stock de la ficha no se aceptan del
     * formulario: son el resumen que calcula `sincronizarResumenDeVariantes()`.
     *
     * @param  array<string, mixed>  $validado
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>|null}
     */
    private function separarVariantes(array $validado): array
    {
        $variantes = array_key_exists('variants', $validado) ? ($validado['variants'] ?? []) : null;

        unset($validado['variants'], $validado['variant_images']);

        if ($variantes) {
            // MOD-6: el costo de la ficha es, como el precio, el resumen de la
            // variante mas barata, y lo escribe `sincronizarResumenDeVariantes()`.
            unset($validado['price'], $validado['sale_price'], $validado['stock'], $validado['cost']);
        }

        // MOD-6: staff no ve el costo, asi que su formulario no lo manda. Que no
        // llegue tiene que significar "no lo toques" y no "borralo": si esto no
        // estuviera, el vendedor que corrige una falta de ortografia en el nombre
        // dejaria el producto sin costo y sin que nadie se enterara.
        if (! Costos::usuarioPuedeVerlos()) {
            unset($validado['cost']);
        }

        return [$validado, $variantes];
    }

    /**
     * Deja las variantes del producto tal como vienen del formulario (MOD-5).
     *
     * La lista manda: las que traen un `id` de ESTE producto se actualizan, las
     * que no se crean, y las que tenia y ya no vienen se borran —con sus fotos—.
     * Un `id` de otro producto u otra tienda no se adopta: se trata como nueva,
     * porque solo se busca entre las variantes que ya tenia este.
     *
     * La foto de cada una llega como `variant_images[<posicion>]`. No cuenta para
     * el tope de imagenes del plan: es una por variante y las variantes ya tienen
     * su techo fijo (`ProductVariant::MAXIMO_POR_PRODUCTO`).
     *
     * @param  array<int, array<string, mixed>>  $entrada
     */
    /**
     * Lo que se compara de un producto antes y después de editarlo (INF-3).
     *
     * @return array<string, mixed>
     */
    private function fotoParaBitacora(Product $product): array
    {
        $product->load(['category', 'images', 'variants']);

        return [
            'name'        => $product->name,
            'brand'       => $product->brand,
            'sku'         => $product->sku,
            'price'       => $product->price,
            'sale_price'  => $product->sale_price,
            // MOD-6: el costo cambia el margen de todo lo que se venda despues,
            // asi que quien lo toca queda escrito como con el precio. La bitacora
            // solo la lee un admin, que es quien puede verlo.
            'cost'        => $product->cost,
            'stock'       => $product->stock,
            'is_active'   => (bool) $product->is_active,
            'status'      => $product->status === 'draft' ? 'borrador' : 'publicado',
            'category'    => $product->category?->name,
            // De estos tres solo se dice que cambiaron, no de qué a qué: un texto
            // largo o una lista de fotos no caben en una línea de bitácora.
            'description' => md5((string) $product->getRawOriginal('description')),
            'specs'       => md5((string) json_encode($product->specs)),
            'fotos'       => md5($product->image_url.'|'.$product->images->pluck('image_url')->implode('|')),
            'variantes'   => $product->variants->mapWithKeys(fn (ProductVariant $v) => [$v->id => [
                'nombre'     => $v->nombre,
                'price'      => $v->price,
                'sale_price' => $v->sale_price,
                'cost'       => $v->cost,
                'stock'      => $v->stock,
            ]])->all(),
        ];
    }

    /**
     * Una línea por edición, con lo que cambió. Guardar sin tocar nada no anota.
     *
     * Con variantes, el precio y el stock de la ficha son un resumen que se
     * recalcula solo (MOD-5): se anotan los de cada variante, que es lo que cambió
     * de verdad, y no el resumen, que diría dos veces lo mismo.
     *
     * @param  array<string, mixed>  $antes
     */
    private function anotarEdicion(array $antes, Product $product): void
    {
        $despues = $this->fotoParaBitacora($product);
        $conVariantes = $antes['variantes'] !== [] || $despues['variantes'] !== [];

        $campos = [
            'name' => 'nombre', 'brand' => 'marca', 'sku' => 'SKU', 'category' => 'categoría',
            'is_active' => 'visible', 'status' => 'estado',
        ];

        if (! $conVariantes) {
            $campos += ['price' => 'precio', 'sale_price' => 'oferta', 'cost' => 'costo', 'stock' => 'stock'];
        }

        $cambios = Bitacora::cambios($antes, $despues, $campos);
        // Lo que se dice sin "de → a": variantes que entran o salen y campos largos.
        $sinValor = [];

        foreach ($despues['variantes'] as $id => $variante) {
            if (! isset($antes['variantes'][$id])) {
                $sinValor[] = "añadió la variante {$variante['nombre']}";

                continue;
            }

            $deVariante = Bitacora::cambios($antes['variantes'][$id], $variante, [
                'price' => 'precio', 'sale_price' => 'oferta', 'cost' => 'costo', 'stock' => 'stock',
            ]);

            foreach ($deVariante as $etiqueta => $par) {
                $cambios["{$etiqueta} de {$variante['nombre']}"] = $par;
            }
        }

        foreach (array_diff_key($antes['variantes'], $despues['variantes']) as $variante) {
            $sinValor[] = "quitó la variante {$variante['nombre']}";
        }

        foreach (['description' => 'descripción', 'specs' => 'especificaciones', 'fotos' => 'fotos'] as $campo => $etiqueta) {
            if ($antes[$campo] !== $despues[$campo]) {
                $sinValor[] = $etiqueta;
            }
        }

        if ($cambios === [] && $sinValor === []) {
            return;
        }

        $resumen = collect([Bitacora::resumirCambios($cambios)])
            ->merge($sinValor)
            ->filter()
            ->implode(', ');

        Bitacora::anotar(
            ActivityLog::PRODUCTO_EDITADO,
            "Editó «{$product->name}»: {$resumen}.",
            ['producto_id' => $product->id, 'cambios' => $cambios, 'otros' => $sinValor],
        );
    }

    /**
     * Las acciones en lote dicen a qué se aplicaron: una selección o una categoría entera.
     *
     * @param  array<string, mixed>  $data  lo validado en `bulkAction`
     * @param  array<string, mixed>  $contexto
     */
    private function anotarLote(string $descripcion, array $data, array $contexto): void
    {
        if (empty($data['product_ids']) && ! empty($data['category_id'])) {
            $categoria = Category::find($data['category_id']);
            $descripcion .= $categoria ? " de la categoría «{$categoria->name}»" : '';
            $contexto['categoria_id'] = $data['category_id'];
        }

        Bitacora::anotar(ActivityLog::PRODUCTO_LOTE, $descripcion.'.', $contexto);
    }

    private function sincronizarVariantes(Product $product, array $entrada, Request $request): void
    {
        $tenant = app('currentTenant');
        $existentes = $product->variants()->get()->keyBy('id');
        $conservadas = [];
        // TEC-14: se borran al final, y solo si ninguna otra fila las usa.
        $sueltas = [];

        foreach (array_values($entrada) as $posicion => $datos) {
            $variante = isset($datos['id']) ? $existentes->get($datos['id']) : null;
            $variante ??= new ProductVariant(['product_id' => $product->id]);

            $variante->fill([
                'options' => array_values(array_map(
                    fn (array $opcion) => ['name' => trim($opcion['name']), 'value' => trim($opcion['value'])],
                    $datos['options'],
                )),
                'sku'                 => $datos['sku'] ?? null,
                'price'               => $datos['price'],
                'sale_price'          => $datos['sale_price'] ?? null,
                'stock'               => $datos['stock'],
                'low_stock_threshold' => $datos['low_stock_threshold'] ?? 5,
                'sort_order'          => $posicion,
            ]);

            // MOD-6: mismo criterio que en la ficha. Ausente es "no lo toques"
            // -el formulario de staff no lo manda- y null explicito es "borralo".
            if (Costos::usuarioPuedeVerlos() && array_key_exists('cost', $datos)) {
                $variante->cost = $datos['cost'];
            }

            $foto = $request->file("variant_images.{$posicion}");

            if (($foto || ! empty($datos['remove_image'])) && $variante->image_url) {
                $sueltas[] = $variante->image_url;
                $sueltas[] = $variante->thumbnail_url;
                $variante->image_url = null;
                $variante->thumbnail_url = null;
            }

            if ($foto) {
                $variante->fill($this->imageService->uploadProductImage($foto, $tenant->slug));
            }

            $variante->save();
            $conservadas[] = $variante->id;
        }

        foreach ($existentes as $id => $variante) {
            if (! in_array($id, $conservadas, true)) {
                $sueltas[] = $variante->image_url;
                $sueltas[] = $variante->thumbnail_url;
                $variante->delete();
            }
        }

        $this->imageService->borrarSiNadieLasUsa($sueltas);

        $product->sincronizarResumenDeVariantes();
    }

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
    /**
     * "Capacidad: 1 TB | Color: Negro" -> [['name' => 'Capacidad', 'value' => '1 TB'], ...].
     *
     * Mismo separador que las specs —'|' y ';'— por la misma razon: la plantilla
     * usa '|' porque ';' separa tambien las columnas, y el ';' se acepta para no
     * romper lo que ya escribio la gente (OWN-5).
     *
     * Un trozo sin ':' se toma como el valor de un eje llamado "Variante": un
     * CSV escrito a mano suele poner "1 TB" a secas, y rechazarlo obligaria a
     * reescribir el archivo entero para no ganar nada.
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function opcionesDeVariante(string $texto): array
    {
        $opciones = [];

        foreach (preg_split('/[|;]/', $texto) as $parte) {
            $parte = trim($parte);

            if ($parte === '') {
                continue;
            }

            $par    = explode(':', $parte, 2);
            $nombre = count($par) === 2 ? trim($par[0]) : '';
            $valor  = count($par) === 2 ? trim($par[1]) : $parte;

            if ($nombre === '' || $valor === '') {
                $nombre = 'Variante';
                $valor  = $parte;
            }

            // Recortado a lo que aguanta la columna en vez de rechazar la fila:
            // una etiqueta larga es un problema de la etiqueta, y hacer saltar la
            // excepcion dentro de la transaccion tiraria el import entero.
            $opciones[] = [
                'name'  => mb_substr($nombre, 0, 50),
                'value' => mb_substr($valor, 0, 100),
            ];
        }

        return $opciones;
    }

    /**
     * La huella de unas opciones, para detectar dos variantes iguales dentro del
     * mismo archivo. Mismo criterio que `ValidaVariantes`: sin mayusculas y sin
     * que importe el orden de los ejes.
     *
     * @param  array<int, array{name: string, value: string}>  $opciones
     */
    private function claveDeOpciones(array $opciones): string
    {
        return collect($opciones)
            ->map(fn (array $o) => mb_strtolower($o['name']).'='.mb_strtolower($o['value']))
            ->sort()
            ->implode('|');
    }

    /**
     * Los datos de una variante tal como los deja una fila del CSV (MOD-12).
     *
     * @param  array<int, array{name: string, value: string}>  $opciones
     * @return array<string, mixed>
     */
    private function varianteDeFila(array $opciones, ?string $sku, float $price, ?float $salePrice, ?float $costo, int $stock): array
    {
        return [
            'options'    => $opciones,
            'sku'        => $sku ?: null,
            'price'      => $price,
            'sale_price' => $salePrice,
            'cost'       => $costo,
            'stock'      => $stock,
        ];
    }

    /**
     * Variante del CSV -> fila lista para un INSERT en lote, como `filaAAtributos`.
     *
     * @param  array<string, mixed>  $variante
     * @return array<string, mixed>
     */
    private function varianteAAtributos(string $productId, array $variante, int $orden): array
    {
        $modelo = new ProductVariant($variante + ['product_id' => $productId, 'sort_order' => $orden]);
        $ahora  = now();

        return array_merge($modelo->getAttributes(), [
            'id'         => $modelo->newUniqueId(),
            'tenant_id'  => app('currentTenant')->id,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

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
