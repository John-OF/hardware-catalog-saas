<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\Public\PublicCatalogController;
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

// Catálogo público (consultado por el frontend sin login)
Route::prefix('public/{slug}')->group(function () {
    Route::get('/',          [PublicCatalogController::class, 'tenant']);
    Route::get('/categories', [PublicCatalogController::class, 'categories']);
    Route::get('/products',  [PublicCatalogController::class, 'products']);
    Route::get('/products/{product}', [PublicCatalogController::class, 'product']);

    // Crear solicitud de pedido (público, limitado para evitar spam)
    Route::post('/orders', [PublicCatalogController::class, 'storeOrder'])
        ->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Rutas privadas — Requieren autenticación + tenant
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Configuración del tenant
    Route::get('/tenant',    [TenantController::class, 'show']);
    Route::put('/tenant',    [TenantController::class, 'update']);

    // Productos
    Route::apiResource('products', ProductController::class);

    // Categorías
    Route::apiResource('categories', CategoryController::class);

    // Pedidos
    Route::apiResource('orders', OrderController::class);
});
