<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\Public\PublicCatalogController;
use App\Http\Controllers\Api\Public\PublicAuthController;
use App\Http\Controllers\Api\Public\PublicFavoritesController;
use App\Http\Controllers\Api\Public\PublicOrdersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas públicas — Sin autenticación
|--------------------------------------------------------------------------
*/
Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Recuperación de contraseña del panel (SAAS-2). Sin auth: quien las usa es
// justamente quien no puede entrar. El throttle por IP es bajo a propósito
// porque son endpoints que aceptan correos arbitrarios.
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');

Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1');

// Verificacion del correo del alta (FUN-5). Sin `auth:sanctum`: el enlace se
// abre desde el correo, muchas veces en otro navegador o en el movil, donde no
// hay sesion del panel. Lo que autentica es `signed`, que comprueba la firma que
// puso `URL::temporarySignedRoute`.
//
// El nombre de la ruta ('verificacion.correo') NO es decorativo: la notificacion
// genera el enlace con `route()`, asi que si se renombra aqui hay que renombrarlo
// alli el mismo dia o el alta empieza a mandar correos con un enlace roto.
Route::get('/auth/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verificacion.correo');

// Catálogo público (consultado por el frontend sin login)
//
// AUD-2: 'throttle:catalogo_publico' va en TODO el grupo, no ruta a ruta. Antes
// solo lo llevaban las que escriben (reseñas, pedidos, avisos de stock, auth), y
// las de lectura —que son las que un script repetiría— iban sin nada. El límite
// se define en AppServiceProvider, con el porqué de la cifra.
//
// Las rutas que ya traían su propio throttle lo conservan: los dos middleware se
// aplican y manda el más estricto, que es justo lo que se quiere (un pedido sigue
// siendo 10/min aunque el grupo permita 120).
Route::get('public/resolve-domain', [PublicCatalogController::class, 'resolveDomain'])
    ->middleware('throttle:catalogo_publico');

//
// AUD-4: 'tenant.slug' resuelve la tienda desde el slug y la deja como la actual,
// para que el global scope de BelongsToTenant filtre tambien en lo publico. Antes
// aqui no se resolvia ninguna y cada consulta filtraba a mano; seguian filtrando
// a mano, pero ahora hay red debajo.
Route::prefix('public/{slug}')->middleware(['throttle:catalogo_publico', 'tenant.slug'])->group(function () {
    Route::get('/',          [PublicCatalogController::class, 'tenant']);
    Route::get('/categories', [PublicCatalogController::class, 'categories']);
    Route::get('/products',  [PublicCatalogController::class, 'products']);
    // Valores de specs de TODO el catálogo, para que los filtros no dependan
    // de la página visible (PUB-2).
    Route::get('/facets',    [PublicCatalogController::class, 'facets']);
    Route::get('/products/{product}', [PublicCatalogController::class, 'product']);
    Route::post('/products/{product}/reviews', [PublicCatalogController::class, 'storeReview'])
        ->middleware(['throttle:10,1', 'throttle:anonymous_reviews']);

    // "Avísame cuando llegue" — registrar interés en un producto agotado
    Route::post('/products/{product}/notify-me', [PublicCatalogController::class, 'storeStockNotification'])
        ->middleware('throttle:10,1');
    
    // Páginas informativas públicas
    Route::get('/pages', [PublicCatalogController::class, 'pages']);
    Route::get('/pages/{page_slug}', [PublicCatalogController::class, 'pageDetail']);

    // Crear solicitud de pedido (público, limitado para evitar spam)
    Route::post('/orders', [PublicCatalogController::class, 'storeOrder'])
        ->middleware('throttle:10,1');

    // Autenticación de cliente
    Route::post('/auth/register', [PublicAuthController::class, 'register'])
        ->middleware('throttle:5,1');
    Route::post('/auth/login', [PublicAuthController::class, 'login'])
        ->middleware('throttle:5,1');

    // Rutas protegidas para clientes.
    //
    // AUD-3: 'customer' va detras de 'auth:sanctum' y comprueba que el token sea
    // de ESTA tienda. Sanctum solo valida que el token exista, y el registro de
    // clientes es abierto, asi que sin esto un token de la tienda A servia en la
    // B (en favoritos llegaba a escribir en su pivote).
    Route::middleware(['auth:sanctum', 'customer'])->group(function () {
        Route::post('/auth/logout', [PublicAuthController::class, 'logout']);
        Route::get('/auth/me', [PublicAuthController::class, 'me']);
        Route::get('/my-orders', [PublicOrdersController::class, 'index']);
        Route::get('/favorites', [PublicFavoritesController::class, 'index']);
        Route::post('/favorites/{product}', [PublicFavoritesController::class, 'toggle']);
    });
});

/*
|--------------------------------------------------------------------------
| Rutas de plataforma — Operador del SaaS (super-admin)
|--------------------------------------------------------------------------
|
| Deliberadamente SIN el middleware 'tenant': el operador trabaja por encima
| de todas las tiendas y no pertenece a ninguna.
*/
Route::post('/platform/login', [PlatformController::class, 'login'])
    ->middleware(['platform.ip', 'throttle:5,1']);

Route::middleware(['platform.ip', 'auth:sanctum', 'superadmin'])->prefix('platform')->group(function () {
    Route::post('/logout', [PlatformController::class, 'logout']);
    Route::get('/me',      [PlatformController::class, 'me']);

    // Resumen del negocio y bitácora de lo que ha tocado el operador (INF-2).
    Route::get('/stats', [PlatformController::class, 'stats']);
    Route::get('/logs',  [PlatformController::class, 'logs']);

    Route::get('/tenants',            [PlatformController::class, 'tenants']);
    Route::get('/tenants/{tenant}',   [PlatformController::class, 'show']);
    Route::put('/tenants/{tenant}',   [PlatformController::class, 'updateTenant']);
    Route::post('/tenants/{tenant}/password-reset', [PlatformController::class, 'sendAdminPasswordReset']);
    // Entrar en una tienda como soporte: token de 15 minutos y SOLO LECTURA.
    // Lo de "solo lectura" no lo impone esta ruta sino el middleware 'soporte'
    // del panel, al otro lado (App\Support\Suplantacion).
    Route::post('/tenants/{tenant}/impersonate', [PlatformController::class, 'impersonate']);
    // Rescate: nombrar admin a un colaborador cuando la tienda se quedó sin
    // ninguno (carrera de dos admins bajándose a la vez, o un desactivado a
    // mano). Solo funciona si de verdad no hay ningún admin activo.
    Route::post('/tenants/{tenant}/rescue-admin', [PlatformController::class, 'rescueAdmin']);
});

/*
|--------------------------------------------------------------------------
| Rutas privadas — Requieren autenticación + tenant
|--------------------------------------------------------------------------
*/
/*
| Dos niveles (FUN-4). Todo el grupo pide 'panel' -admin o staff activo- y lo
| que solo decide un admin va ademas en el subgrupo 'admin' del final.
|
| El criterio del reparto: staff lleva el dia a dia de la tienda (pedidos,
| productos, resenas, lista de espera) y el admin decide como es la tienda
| (configuracion, categorias -que gobiernan el armador desde FUN-8-, paginas,
| plan y equipo). Dentro de lo que staff toca, lo que BORRA o cambia el catalogo
| de golpe tambien es de admin: borrar productos o pedidos, importar CSV y las
| acciones masivas. Moderar resenas y la lista de espera, incluido borrar, si es
| de staff: es su trabajo diario y no se lleva nada que no se pueda rehacer.
|
| El frontend esconde lo que staff no puede usar, pero la barrera es esta.
*/
// 'soporte' cierra el panel a escrituras cuando quien mira es el operador
// entrando como soporte (INF-2). Va en el grupo entero a proposito: si fuera
// ruta por ruta, la primera ruta nueva que alguien anadiera naceria sin ella.
Route::middleware(['auth:sanctum', 'tenant', 'panel', 'soporte'])->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Reenviar el correo de verificacion (FUN-5). Aqui dentro porque solo reenvia
    // al correo del usuario autenticado: no acepta direcciones sueltas, asi que
    // no vale ni para sondear altas ni para mandarle correo a un tercero.
    Route::post('/auth/email/resend', [AuthController::class, 'resendVerificationEmail'])
        ->middleware('throttle:3,1');

    // Estadísticas
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Configuración del tenant. Staff la LEE -el panel necesita la moneda, el
    // slug y si la tienda esta publicada- pero no la cambia.
    Route::get('/tenant',    [TenantController::class, 'show']);

    // Plan, limites y consumo (SAAS-3). Aparte de /tenant para no tocar la
    // forma de esa respuesta; el porque esta en PlanController.
    Route::get('/plan',      [PlanController::class, 'show']);

    // Productos: staff crea y edita, no borra (ver el subgrupo de abajo).
    Route::post('products/reorder', [ProductController::class, 'reorder']);
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate']);
    Route::apiResource('products', ProductController::class)->except(['destroy']);

    // Categorías: staff solo las lee, que es lo que necesita el formulario de
    // producto para elegir una.
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);

    // Pedidos
    Route::apiResource('orders', OrderController::class)->except(['destroy']);

    // Reseñas/Calificaciones
    Route::apiResource('reviews', \App\Http\Controllers\Api\ReviewController::class)->only(['index', 'update', 'destroy']);

    // Lista de espera de "avisame cuando llegue" (FUN-1b). Sin `store`: las filas
    // las crea el catalogo publico, no el panel.
    Route::apiResource('stock-notifications', \App\Http\Controllers\Api\StockNotificationController::class)
        ->only(['index', 'update', 'destroy']);

    // ------------------------------------------------ solo admin (FUN-4)
    Route::middleware('admin')->group(function () {
        Route::put('/tenant', [TenantController::class, 'update']);
        // Comprobar el registro TXT del dominio propio (FUN-6).
        Route::post('/tenant/custom-domain/verify', [TenantController::class, 'verifyCustomDomain']);

        Route::post('products/import', [ProductController::class, 'import']);
        Route::post('products/bulk', [ProductController::class, 'bulkAction']);
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::post('categories/reorder', [CategoryController::class, 'reorder']);
        Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);

        // Borrar un pedido atendido devuelve su stock y borra el registro de la
        // venta: no es gestionar pedidos, es rehacer la contabilidad.
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');

        // Páginas informativas privadas
        Route::apiResource('pages', \App\Http\Controllers\Api\PageController::class);

        // Equipo de la tienda (FUN-4). Sin `show`: la lista ya trae todo lo que
        // hay de un usuario. El controlador resuelve el {user} a mano y no por
        // route model binding, para no salirse de la tienda (ver su cabecera).
        Route::post('users/{user}/resend-invitation', [UserController::class, 'resend'])
            ->middleware('throttle:3,1');
        Route::apiResource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy']);
    });
});
