<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TrashController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\Public\PublicCatalogController;
use App\Http\Controllers\Api\Public\PublicAuthController;
use App\Http\Controllers\Api\Public\PublicFavoritesController;
use App\Http\Controllers\Api\Public\PublicOrdersController;
use App\Http\Controllers\Api\Public\PublicPlansController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas públicas — Sin autenticación
|--------------------------------------------------------------------------
*/

// TEC-13: todos los throttle de este archivo van con NOMBRE (login,
// auth_publica, escritura_publica…), definidos con sus topes en
// AppServiceProvider::limitesPorFormulario(). No volver a `throttle:5,1` a
// secas: sin nombre, todas esas rutas comparten un solo contador por IP.
Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:auth_publica');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Recuperación de contraseña del panel (SAAS-2). Sin auth: quien las usa es
// justamente quien no puede entrar. El throttle por IP es bajo a propósito
// porque son endpoints que aceptan correos arbitrarios.
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:auth_publica');

Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:auth_publica');

// Verificacion del correo del alta (FUN-5). Sin `auth:sanctum`: el enlace se
// abre desde el correo, muchas veces en otro navegador o en el movil, donde no
// hay sesion del panel. Lo que autentica es `signed`, que comprueba la firma que
// puso `URL::temporarySignedRoute`.
//
// El nombre de la ruta ('verificacion.correo') NO es decorativo: la notificacion
// genera el enlace con `route()`, asi que si se renombra aqui hay que renombrarlo
// alli el mismo dia o el alta empieza a mandar correos con un enlace roto.
Route::get('/auth/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:verificacion_correo'])
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

// Catálogo de planes de la plataforma, para la landing (INF-1). Sin tienda ni
// sesión: es la pantalla de quien todavía no se ha registrado.
Route::get('public/plans', [PublicPlansController::class, 'index'])
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
        ->middleware(['throttle:escritura_publica', 'throttle:anonymous_reviews']);

    // "Avísame cuando llegue" — registrar interés en un producto agotado
    Route::post('/products/{product}/notify-me', [PublicCatalogController::class, 'storeStockNotification'])
        ->middleware('throttle:escritura_publica');
    
    // Páginas informativas públicas
    Route::get('/pages', [PublicCatalogController::class, 'pages']);
    Route::get('/pages/{page_slug}', [PublicCatalogController::class, 'pageDetail']);

    // MOD-4: comprobar un codigo de cupon desde el carrito. Con su propio
    // limitador, mas estrecho que el resto: es el unico sitio del catalogo donde
    // adivinar a ciegas tiene premio.
    Route::post('/coupons/check', [PublicCatalogController::class, 'checkCoupon'])
        ->middleware('throttle:cupon');

    // Crear solicitud de pedido (público, limitado para evitar spam)
    Route::post('/orders', [PublicCatalogController::class, 'storeOrder'])
        ->middleware('throttle:escritura_publica');

    // Autenticación de cliente
    Route::post('/auth/register', [PublicAuthController::class, 'register'])
        ->middleware('throttle:auth_publica');
    Route::post('/auth/login', [PublicAuthController::class, 'login'])
        ->middleware('throttle:login');

    // Recuperación de contraseña del CLIENTE (FUN-11). Mismo throttle bajo que
    // el resto de auth y el mismo motivo que el del panel: son endpoints sin
    // sesión que aceptan un correo arbitrario.
    Route::post('/auth/forgot-password', [PublicAuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth_publica');
    Route::post('/auth/reset-password', [PublicAuthController::class, 'resetPassword'])
        ->middleware('throttle:auth_publica');

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
    ->middleware(['platform.ip', 'throttle:login']);

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
        ->middleware('throttle:reenvio_correo');

    // Estadísticas
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Reportes: ventas por periodo, más vendidos y stock bajo (MOD-9). Staff
    // entra: son los mismos pedidos y el mismo stock que ya ve en sus dos
    // pantallas. Lo que NO ve es el costo ni la utilidad, y eso no lo decide
    // esta ruta sino `Costos::usuarioPuedeVerlos()` dentro: las claves no
    // llegan a la respuesta.
    Route::get('/reports', [ReportController::class, 'index']);

    // Configuración del tenant. Staff la LEE -el panel necesita la moneda, el
    // slug y si la tienda esta publicada- pero no la cambia.
    Route::get('/tenant',    [TenantController::class, 'show']);

    // Plan, limites y consumo (SAAS-3). Aparte de /tenant para no tocar la
    // forma de esa respuesta; el porque esta en PlanController.
    Route::get('/plan',      [PlanController::class, 'show']);

    // Exportar (MOD-7). Van AQUI y no en el grupo de admin de mas abajo por el
    // orden de las rutas: `products/{product}` y `orders/{order}` los registra
    // `apiResource` unas lineas mas abajo y se tragarian `/export` como si fuera
    // un id. Es el mismo motivo por el que `products/reorder` esta antes.
    //
    // Solo admin: el catalogo lleva el costo de compra (MOD-6) y los pedidos,
    // los datos de contacto de todos los clientes de la tienda.
    Route::middleware('admin')->group(function () {
        Route::get('products/export', [ExportController::class, 'products']);
        Route::get('orders/export', [ExportController::class, 'orders']);
        // El del reporte lleva la utilidad, así que vive con los otros dos
        // aunque la pantalla que lo ofrece sí la vea staff (MOD-9).
        Route::get('reports/export', [ExportController::class, 'reports']);
    });

    // Productos: staff crea y edita, no borra (ver el subgrupo de abajo).
    Route::post('products/reorder', [ProductController::class, 'reorder']);
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate']);
    Route::apiResource('products', ProductController::class)->except(['destroy']);

    // Categorías: staff solo las lee, que es lo que necesita el formulario de
    // producto para elegir una.
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);

    // Pedidos. El PDF va ANTES del apiResource, o `orders/{order}` se tragaria
    // "pdf" como si fuera un id (mismo motivo que `products/export`).
    // Lo descarga tambien staff (MOD-2): quien atiende el mostrador es quien
    // cotiza, y el PDF no lleva ningun dato que staff no vea ya en el pedido.
    Route::get('orders/{order}/pdf', QuoteController::class);
    Route::apiResource('orders', OrderController::class)->except(['destroy']);

    // Clientes de la tienda (MOD-10). Lectura: no se crean ni se editan desde el
    // panel -las cuentas las abre el propio cliente en el catalogo-. Staff los ve
    // porque ya ve los mismos datos de contacto en cada pedido que atiende.
    Route::get('customers', [CustomerController::class, 'index']);
    Route::get('customers/{customer}', [CustomerController::class, 'show']);

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
            ->middleware('throttle:reenvio_correo');
        Route::apiResource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Actividad del panel: quién cambió qué (INF-3). Solo admin: es lo que
        // hace el resto del equipo. Lo que escribe cada ruta está en
        // App\Support\Bitacora y lo vigila BitacoraDeTiendaTest.
        Route::get('activity', [ActivityController::class, 'index']);

        // Cupones (MOD-4). Solo admin: un cupon es dinero que se deja de cobrar,
        // asi que lo decide quien decide los precios (FUN-4).
        Route::apiResource('coupons', CouponController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Papelera (MOD-8). Solo admin por lo mismo que borrar: si un
        // colaborador pudiera restaurar y volver a borrar, no borrar no seria
        // ninguna restriccion. `DELETE trash` va ANTES que `trash/{tipo}/{id}`
        // para que no se lo trague como si fuera un tipo.
        Route::get('trash', [TrashController::class, 'index']);
        Route::delete('trash', [TrashController::class, 'empty']);
        Route::post('trash/{tipo}/{id}/restore', [TrashController::class, 'restore']);
        Route::delete('trash/{tipo}/{id}', [TrashController::class, 'destroy']);
    });
});
