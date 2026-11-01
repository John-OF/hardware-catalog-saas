<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaludController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TeamRoleController;
use App\Http\Controllers\Api\TrashController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WhatsappTemplateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\PlatformDosPasosController;
use App\Http\Controllers\Api\Public\PublicCatalogController;
use App\Http\Controllers\Api\Public\PublicAuthController;
use App\Http\Controllers\Api\Public\PublicFavoritesController;
use App\Http\Controllers\Api\Public\PublicOrdersController;
use App\Http\Controllers\Api\Public\PublicBuilderController;
use App\Http\Controllers\Api\Public\PublicComponentSpecsController;
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

// Los topes del PHP que atiende la web, para `salud:comprobar` (INF-19): la
// consola lee otro php.ini y no puede verlos. Firmada como la de arriba, y por
// lo mismo: si la firma no cuadra aqui, tampoco cuadra en los enlaces del correo,
// y el comando lo dice.
Route::get('/salud/php', [SaludController::class, 'php'])
    ->middleware('signed')
    ->name('salud.php');

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

// Specs con nombre fijo por tipo de componente (FUN-8, 13.2). Sin tienda: la
// definicion es de la plataforma. La leen el formulario del panel y el catalogo.
Route::get('public/component-specs', [PublicComponentSpecsController::class, 'index'])
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
    // El veredicto del armador de PC (FUN-8, 13.5): las reglas viven en el
    // servidor y el navegador solo pregunta y pinta.
    Route::get('/builder/compatibility', [PublicBuilderController::class, 'compatibilidad']);
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

// SEC-9: el segundo paso del login, con el código de la app o uno de
// recuperación. Sin sesión todavía: lo que identifica al operador es el
// desafío que devolvió el primer paso.
Route::post('/platform/login/dos-pasos', [PlatformController::class, 'loginConCodigo'])
    ->middleware(['platform.ip', 'throttle:dos_pasos']);

Route::middleware(['platform.ip', 'auth:sanctum', 'superadmin'])->prefix('platform')->group(function () {
    Route::post('/logout', [PlatformController::class, 'logout']);
    Route::get('/me',      [PlatformController::class, 'me']);

    // SEC-9: activar la verificación en dos pasos. FUERA de 'platform.dos-pasos'
    // a propósito: en producción es lo único que puede hacer un operador que
    // todavía no la tiene.
    Route::post('/dos-pasos',           [PlatformDosPasosController::class, 'iniciar'])->middleware('throttle:dos_pasos');
    Route::post('/dos-pasos/confirmar', [PlatformDosPasosController::class, 'confirmar'])->middleware('throttle:dos_pasos');

    Route::middleware('platform.dos-pasos')->group(function () {
        // Resumen del negocio y bitácora de lo que ha tocado el operador (INF-2).
        Route::get('/stats', [PlatformController::class, 'stats']);
        Route::get('/logs',  [PlatformController::class, 'logs']);

        Route::get('/tenants',            [PlatformController::class, 'tenants']);
        Route::get('/tenants/{tenant}',   [PlatformController::class, 'show']);
        Route::put('/tenants/{tenant}',   [PlatformController::class, 'updateTenant']);
        // INF-23: apuntar un pago del plan. Mueve el «pagado hasta» y reabre la
        // tienda si estaba cerrada; los pagos no se editan ni se borran.
        Route::post('/tenants/{tenant}/payments', [PlatformController::class, 'registerPayment']);
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
});

/*
|--------------------------------------------------------------------------
| Rutas privadas — Requieren autenticación + tenant
|--------------------------------------------------------------------------
*/
/*
| Todo el grupo pide 'panel' -alguien del equipo, activo- y cada ruta, ademas,
| su permiso: 'permiso:<clave>' (MOD-21). Las claves y lo que significan estan
| en config/permisos.php; un admin las tiene todas y un staff, las de su rol.
|
| Lo que NO es un permiso y sigue siendo solo de admin: el equipo y sus roles.
| Un rol que pudiera tocar el equipo podria darse a si mismo cualquier permiso.
|
| Una ruta nueva del panel lleva su permiso, o 'admin', o figura en SIN_PERMISO
| de PermisosPorAccionTest con su motivo; si no, ese test falla.
|
| El frontend esconde lo que cada uno no puede usar, pero la barrera es esta.
*/
// 'soporte' cierra el panel a escrituras cuando quien mira es el operador
// entrando como soporte (INF-2). Va en el grupo entero a proposito: si fuera
// ruta por ruta, la primera ruta nueva que alguien anadiera naceria sin ella.
Route::middleware(['auth:sanctum', 'tenant', 'panel', 'soporte'])->group(function () {

    // Sin permiso: son de la propia sesion (ver SIN_PERMISO).
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Reenviar el correo de verificacion (FUN-5). Aqui dentro porque solo reenvia
    // al correo del usuario autenticado: no acepta direcciones sueltas, asi que
    // no vale ni para sondear altas ni para mandarle correo a un tercero.
    Route::post('/auth/email/resend', [AuthController::class, 'resendVerificationEmail'])
        ->middleware('throttle:reenvio_correo');

    // Configuración del tenant. Todo el equipo la LEE -el panel necesita la
    // moneda, el slug y si la tienda esta publicada-; cambiarla es un permiso.
    Route::get('/tenant',    [TenantController::class, 'show']);

    // Plan, limites y consumo (SAAS-3). Aparte de /tenant para no tocar la
    // forma de esa respuesta; el porque esta en PlanController. Lo lee todo el
    // equipo por lo mismo que /tenant: los avisos de prueba y de tope.
    Route::get('/plan',      [PlanController::class, 'show']);

    // Mensajes de WhatsApp (MOD-25). Leerlos no pide permiso: Pedidos y Lista
    // de espera arman sus botones con ellos. Cambiarlos es configurar la tienda.
    Route::get('/whatsapp-templates', [WhatsappTemplateController::class, 'index']);

    // Estadísticas
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('permiso:resumen.ver');

    // Reportes: ventas por periodo, más vendidos y stock bajo (MOD-9). El costo
    // y la utilidad no los decide esta ruta sino `Costos::usuarioPuedeVerlos()`
    // dentro (permiso `costos.ver`): sin el, las claves no llegan a la respuesta.
    Route::get('/reports', [ReportController::class, 'index'])->middleware('permiso:reportes.ver');

    // Exportar (MOD-7). Van ANTES de los `apiResource` por el orden de las
    // rutas: `products/{product}` y `orders/{order}` se tragarian `/export`
    // como si fuera un id. Es el mismo motivo por el que `products/reorder`
    // esta antes. Las columnas de costo y utilidad solo salen con `costos.ver`.
    Route::get('products/export', [ExportController::class, 'products'])->middleware('permiso:productos.exportar');
    Route::get('orders/export', [ExportController::class, 'orders'])->middleware('permiso:pedidos.exportar');
    Route::get('reports/export', [ExportController::class, 'reports'])->middleware('permiso:reportes.exportar');

    // Productos. `import` y `bulk` van antes del `apiResource` por lo mismo que
    // `export`. Borrar en lote pide ademas `productos.borrar`, y eso lo mira
    // el controlador: la ruta es la misma que publicar en lote.
    Route::post('products/import', [ProductController::class, 'import'])->middleware('permiso:productos.importar');
    Route::post('products/bulk', [ProductController::class, 'bulkAction'])->middleware('permiso:productos.masivo');
    Route::post('products/reorder', [ProductController::class, 'reorder'])->middleware('permiso:productos.editar');
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->middleware('permiso:productos.editar');
    Route::get('products', [ProductController::class, 'index'])->middleware('permiso:productos.ver')->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware('permiso:productos.ver')->name('products.show');
    Route::post('products', [ProductController::class, 'store'])->middleware('permiso:productos.editar')->name('products.store');
    Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update'])->middleware('permiso:productos.editar')->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permiso:productos.borrar')->name('products.destroy');

    // Categorías. Leerlas no pide permiso: el formulario de producto las
    // necesita para elegir una, y son las mismas que enseña el catalogo
    // publico. Cambiarlas, si: gobiernan el armador desde FUN-8.
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
    Route::middleware('permiso:categorias.gestionar')->group(function () {
        Route::post('categories/reorder', [CategoryController::class, 'reorder']);
        Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
    });

    // Pedidos. El PDF va ANTES del apiResource, o `orders/{order}` se tragaria
    // "pdf" como si fuera un id (mismo motivo que `products/export`). Va con
    // ver: el PDF no lleva nada que no este ya en el pedido.
    Route::get('orders/{order}/pdf', QuoteController::class)->middleware('permiso:pedidos.ver');
    Route::get('orders', [OrderController::class, 'index'])->middleware('permiso:pedidos.ver')->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('permiso:pedidos.ver')->name('orders.show');
    // La venta de mostrador (OWN-3) y el cambio de estado.
    Route::post('orders', [OrderController::class, 'store'])->middleware('permiso:pedidos.gestionar')->name('orders.store');
    Route::match(['put', 'patch'], 'orders/{order}', [OrderController::class, 'update'])->middleware('permiso:pedidos.gestionar')->name('orders.update');
    // Borrar un pedido atendido devuelve su stock y borra el registro de la
    // venta: no es gestionar pedidos, es rehacer la contabilidad.
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->middleware('permiso:pedidos.borrar')->name('orders.destroy');

    // Clientes de la tienda (MOD-10). Lectura: no se crean ni se editan desde el
    // panel -las cuentas las abre el propio cliente en el catalogo-.
    Route::get('customers', [CustomerController::class, 'index'])->middleware('permiso:clientes.ver');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permiso:clientes.ver');

    // Reseñas/Calificaciones
    Route::apiResource('reviews', \App\Http\Controllers\Api\ReviewController::class)
        ->only(['index', 'update', 'destroy'])
        ->middleware('permiso:resenas.gestionar');

    // Lista de espera de "avisame cuando llegue" (FUN-1b). Sin `store`: las filas
    // las crea el catalogo publico, no el panel.
    Route::apiResource('stock-notifications', \App\Http\Controllers\Api\StockNotificationController::class)
        ->only(['index', 'update', 'destroy'])
        ->middleware('permiso:espera.gestionar');

    // Configuración de la tienda y su dominio propio (FUN-6).
    Route::middleware('permiso:tienda.configurar')->group(function () {
        Route::put('/tenant', [TenantController::class, 'update']);
        Route::post('/tenant/custom-domain/verify', [TenantController::class, 'verifyCustomDomain']);
        Route::put('/whatsapp-templates', [WhatsappTemplateController::class, 'update']);
    });

    // Páginas informativas privadas
    Route::apiResource('pages', \App\Http\Controllers\Api\PageController::class)->middleware('permiso:paginas.gestionar');

    // Actividad del panel: quién cambió qué (INF-3). Lo que escribe cada ruta
    // está en App\Support\Bitacora y lo vigila BitacoraDeTiendaTest.
    Route::get('activity', [ActivityController::class, 'index'])->middleware('permiso:actividad.ver');

    // Cupones (MOD-4): un cupon es dinero que se deja de cobrar.
    Route::apiResource('coupons', CouponController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middleware('permiso:cupones.gestionar');

    // Papelera (MOD-8). Su permiso requiere los dos borrados: si se pudiera
    // restaurar y volver a borrar, no poder borrar no seria ninguna
    // restriccion. `DELETE trash` va ANTES que `trash/{tipo}/{id}` para que no
    // se lo trague como si fuera un tipo.
    Route::middleware('permiso:papelera.gestionar')->group(function () {
        Route::get('trash', [TrashController::class, 'index']);
        Route::delete('trash', [TrashController::class, 'empty']);
        Route::post('trash/{tipo}/{id}/restore', [TrashController::class, 'restore']);
        Route::delete('trash/{tipo}/{id}', [TrashController::class, 'destroy']);
    });

    // ------------------------------------------- solo admin: el equipo
    Route::middleware('admin')->group(function () {
        // Equipo de la tienda (FUN-4). Sin `show`: la lista ya trae todo lo que
        // hay de un usuario. El controlador resuelve el {user} a mano y no por
        // route model binding, para no salirse de la tienda (ver su cabecera).
        Route::post('users/{user}/resend-invitation', [UserController::class, 'resend'])
            ->middleware('throttle:reenvio_correo');
        Route::apiResource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Roles del equipo y el catalogo de permisos que se les puede marcar
        // (MOD-21).
        Route::get('permissions', [TeamRoleController::class, 'catalogo']);
        Route::apiResource('team-roles', TeamRoleController::class)
            ->only(['index', 'store', 'update', 'destroy']);
    });
});
