<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
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

// Catálogo público (consultado por el frontend sin login)
Route::get('public/resolve-domain', [PublicCatalogController::class, 'resolveDomain']);

Route::prefix('public/{slug}')->group(function () {
    Route::get('/',          [PublicCatalogController::class, 'tenant']);
    Route::get('/categories', [PublicCatalogController::class, 'categories']);
    Route::get('/products',  [PublicCatalogController::class, 'products']);
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

    // Rutas protegidas para clientes
    Route::middleware('auth:sanctum')->group(function () {
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
    ->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'superadmin'])->prefix('platform')->group(function () {
    Route::post('/logout', [PlatformController::class, 'logout']);
    Route::get('/me',      [PlatformController::class, 'me']);

    Route::get('/tenants',            [PlatformController::class, 'tenants']);
    Route::put('/tenants/{tenant}',   [PlatformController::class, 'updateTenant']);
    Route::post('/tenants/{tenant}/password-reset', [PlatformController::class, 'sendAdminPasswordReset']);
});

/*
|--------------------------------------------------------------------------
| Rutas privadas — Requieren autenticación + tenant
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant', 'admin'])->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Estadísticas
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Configuración del tenant
    Route::get('/tenant',    [TenantController::class, 'show']);
    Route::put('/tenant',    [TenantController::class, 'update']);

    // Productos
    Route::post('products/reorder', [ProductController::class, 'reorder']);
    Route::post('products/import', [ProductController::class, 'import']);
    Route::post('products/bulk', [ProductController::class, 'bulkAction']);
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate']);
    Route::apiResource('products', ProductController::class);

    // Categorías
    Route::post('categories/reorder', [CategoryController::class, 'reorder']);
    Route::apiResource('categories', CategoryController::class);

    // Pedidos
    Route::apiResource('orders', OrderController::class);

    // Reseñas/Calificaciones
    Route::apiResource('reviews', \App\Http\Controllers\Api\ReviewController::class)->only(['index', 'update', 'destroy']);

    // Páginas informativas privadas
    Route::apiResource('pages', \App\Http\Controllers\Api\PageController::class);
});
