# Hardware Catalog SaaS

Plataforma **SaaS multi-tenant** para que tiendas de componentes de PC publiquen su propio catálogo
en línea. Cada tienda (tenant) administra su negocio desde un panel privado (`/dashboard`) y obtiene
un **catálogo público personalizable** con carrito, reseñas, armador de PC y pedidos que se cierran
por **WhatsApp** (no hay pasarela de pagos).

Monorepo con dos aplicaciones:

| Carpeta | Stack | Rol |
|---|---|---|
| [`saas-hardware-api`](saas-hardware-api) | Laravel 13 + PHP 8.3 | API REST y multitenancy |
| [`saas-hardware-frontend`](saas-hardware-frontend) | React 19 + Vite + TypeScript | Panel de administración y catálogo público (una sola SPA) |

> Este README es la referencia técnica del proyecto: **qué hay, cómo está hecho y cómo se levanta**.
> Lo que hace el sistema funcionalidad por funcionalidad —con sus límites y sus puntos flojos— está
> en `docs/funcionalidades.md`. Ver [Documentación del proyecto](#documentación-del-proyecto).

---

## Índice

1. [Qué hace](#qué-hace)
2. [Requisitos](#requisitos)
3. [Puesta en marcha](#puesta-en-marcha)
4. [Arquitectura multi-tenant](#arquitectura-multi-tenant)
5. [Backend — `saas-hardware-api/`](#backend--saas-hardware-api)
6. [Frontend — `saas-hardware-frontend/`](#frontend--saas-hardware-frontend)
7. [Convenciones de trabajo](#convenciones-de-trabajo)
8. [Documentación del proyecto](#documentación-del-proyecto)

---

## Qué hace

**Para quien todavía no tiene tienda**: una landing (`/` sin tienda que resolver) que presenta el
producto y los tres planes con su precio real, servido por `GET /api/public/plans` — sin pasarela de
cobro todavía, así que hoy es "enséñalo", no "véndelo" (`SAAS-3`).

**Para la tienda**: alta self-service con verificación de correo, catálogo con especificaciones
técnicas y variantes (capacidad, color…, cada una con su precio, stock y foto), imágenes
optimizadas, importación **y exportación** por CSV, pedidos con estados y descuento automático de
stock, envío a domicilio o recojo en tienda y métodos de pago informativos, costo de compra con su
margen y la utilidad de cada pedido, ficha de cada cliente registrado con lo que lleva comprado,
venta de mostrador, moderación de reseñas, lista de espera de productos agotados, páginas
informativas, métricas y personalización visual completa (colores, tipografías, portada, pie,
favicon).

**Para el comprador**: buscador con filtros por categoría, disponibilidad y specs reales del
catálogo; comparador de hasta tres productos; **armador de PC** que avisa de incompatibilidades;
carrito; cuenta con favoritos e historial; reseñas con moderación y anti-bot.

**Para el operador del SaaS**: panel propio —restringido por IP— para listar tiendas, suspenderlas,
cambiar su plan y rescatar la contraseña de un dueño. Los planes limitan cuánto puede crear cada
tienda (`config/plans.php`).

**Lo que todavía no existe** y conviene saber antes de nada: no hay pasarela de pago —ni para cobrar
en la tienda ni para cobrarle a la tienda— ni impuestos/comprobante, ni cupones, ni papelera. El
envío existe pero es un precio fijo, sin zonas ni transportista. El detalle completo —qué
hace cada función, qué **no** hace y dónde cojea— está en `docs/funcionalidades.md`.

---

## Requisitos

- PHP **8.3+** y Composer
- Node.js **22.22+** (o 24.15+) y npm — lo piden Vitest y jsdom; el build solo necesitaría 20.19+
- Una base de datos (MySQL/MariaDB; los tests corren en SQLite en memoria)
- **Un worker de colas corriendo** (`php artisan queue:work`): los correos van por cola y sin él no
  sale ninguno
- **En producción, un cron del sistema llamando `php artisan schedule:run` cada minuto**: es lo que
  cierra solas las tiendas cuya prueba de 7 días venció (`trials:cerrar-vencidas`), lo que vacía la
  papelera de lo que lleve más de 30 días borrado (`papelera:purgar`, `MOD-8`) y lo que hace la
  **copia de seguridad diaria** (`copias:crear`, `INF-7`); sin él, ninguna prueba se cierra aunque la
  fecha ya haya pasado, la papelera crece sin techo y **no hay ninguna copia de la base**
- **`mysqldump` accesible** para el usuario que corre el cron (`BACKUP_BIN_PATH` si no está en su
  PATH), y `BACKUP_DISK` apuntando a almacenamiento **fuera de este servidor** (`r2`/`s3`): una copia
  en el mismo disco que la base no es una copia
- Opcional en local, **obligatorio en producción**: almacenamiento compatible con S3 (Cloudflare R2)
  para las imágenes
- Opcional en local, **obligatorio en producción**: claves de
  [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) para las reseñas

---

## Puesta en marcha

```bash
# API
cd saas-hardware-api
composer install
cp .env.example .env
php artisan key:generate
# Configurar DB_CONNECTION y DB_* en .env
php artisan migrate
php artisan storage:link      # imágenes en local
php artisan serve --port=8000
php artisan queue:work        # OJO: sin esto no sale ningún correo

# Frontend
cd saas-hardware-frontend
npm install
npm run dev                   # http://localhost:5173
```

- Alta de tienda: `http://localhost:5173/register`
- Panel: `http://localhost:5173/login`
- Catálogo público: `http://localhost:5173/{slug}`

`composer dev` levanta servidor, cola, logs y Vite a la vez, que es la forma corta de lo anterior.

### Al desplegar una versión nueva

**Cada entorno tiene su propia base de datos**: una migración aplicada en local no existe en
producción. En cada despliegue, en el servidor:

```bash
php artisan migrate --force   # --force porque en producción pide confirmación
```

**Antes de que el código nuevo reciba tráfico, o a la vez.** El código cuenta con las columnas de sus
migraciones: si sube sin ellas, falla. Hoy hay dos casos con consecuencias graves:
`add_is_published_to_tenants_table` (sin ella, todo el catálogo público da error) y
`add_origen_to_activity_logs_table` (sin ella fallan suspender una tienda, cambiarle el plan o entrar
como soporte). `php artisan migrate:status` dice cuáles faltan.

Y **reiniciar el worker de colas** (`INF-13`):

```bash
php artisan queue:restart
```

`queue:work` es un proceso largo que **mantiene el código PHP en memoria**: sin este reinicio sigue
ejecutando la versión con la que arrancó, aunque el código nuevo ya esté en el servidor y la suite
pase. Todo lo que sale por la cola —los ocho correos— se queda en la versión vieja **sin ningún
error y sin nada en `failed_jobs`**, que es lo que lo hace difícil de ver. Se descubrió comprobando
`SEC-8` en local: el primer correo salió todavía vulnerable con el arreglo ya escrito y sus tests en
verde. `queue:restart` no mata el proceso: le pide terminar el trabajo que tenga y salir, y lo
vuelve a levantar el supervisor.

### Copias de seguridad y cómo restaurar una (`INF-7`)

`copias:crear` vuelca la base, comprueba que el volcado trae dentro las tablas que tiene que traer y
lo guarda en `copias/` del disco de `config/backups.php`, borrando de paso las que pasen de
`BACKUP_RETENTION_DAYS`. Lo dispara el scheduler a las 03:30, así que **depende del mismo cron** que
todo lo demás.

Tres cosas que no se ven en el código y hay que tener presentes:

- **La copia es de la base, no de las imágenes.** Las fotos viven en R2/S3 y tienen su propio
  versionado. Restaurando sólo esto, el catálogo vuelve entero pero una imagen borrada del bucket
  sigue borrada.
- **`BACKUP_DISK` tiene que estar fuera de este servidor.** Una copia en el mismo disco que la base
  se pierde con él.
- **Un disco público se rechaza**, y no es una precaución teórica: el volcado lleva los datos de
  todas las tiendas y los correos de todos sus clientes, y el disco `public` se sirve desde
  `/storage`.

**Restaurar** se hace a mano y mirando —no hay comando, a propósito: uno que pise la base de
producción a un tecleo de distancia es más peligroso que cómodo—:

```bash
# 1. Bajar la copia del disco donde esté (r2/s3) al servidor.
# 2. Restaurarla SIEMPRE primero en una base aparte, nunca encima de la buena.
mysql -u root -p -e "CREATE DATABASE saas_restore CHARACTER SET utf8mb4"
mysql -u root -p saas_restore < 2026-09-18_033000-production.sql

# 3. Comprobar que está lo que tiene que estar antes de tocar nada.
mysql -u root -p saas_restore -e "SELECT COUNT(*) FROM tenants; SELECT COUNT(*) FROM orders"

# 4. Sólo entonces, y con la aplicación en mantenimiento, apuntar DB_DATABASE a la
#    restaurada o volcarla sobre la de producción.
php artisan down && php artisan migrate --force && php artisan up
```

El paso 3 no es opcional: una copia que nadie ha restaurado nunca no se sabe si sirve, y el día que
haga falta no es el día de averiguarlo.

---

## Arquitectura multi-tenant

Todos los tenants comparten **una sola base de datos**; cada fila lleva `tenant_id`
(vía [spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy)).
El tenant activo se resuelve según el tipo de ruta:

- **Rutas privadas del panel** (`auth:sanctum` + `tenant` + `panel`, y `admin` encima de lo que solo
  decide un administrador): por el header `X-Tenant: {slug}` —
  `app/Http/Middleware/InitializeTenantByHeader.php`.
- **Rutas públicas** (`/api/public/{slug}/...`): por el slug de la URL — `InitializeTenantBySlug.php`.
- **Dominios personalizados**: `GET /api/public/resolve-domain` devuelve el tenant asociado al
  dominio; en ese caso el frontend usa rutas **sin** prefijo de slug.
- **Rutas de plataforma** (`/api/platform/...`): deliberadamente **sin** middleware de tenant. El
  operador está por encima de las tiendas y su usuario tiene `tenant_id` a null.

**El global scope de `BelongsToTenant` falla en cerrado**: sin tienda resuelta, una consulta no
devuelve nada en vez de devolverlo todo. Para mirar por encima de las tiendas hay que pedirlo
explícitamente con `withoutTenant()`. **`User` es la única excepción**, y el porqué está escrito en
el propio modelo (es el problema del huevo y la gallina: `auth:sanctum` resuelve al usuario antes de
que ningún middleware haya resuelto la tienda). Ojo con lo que abarca: la excepción es solo **sin**
tienda, donde `User` lo ve todo; **con** tienda resuelta —en todo el panel— su scope filtra igual que
el de cualquier modelo, así que una consulta de `User` sobre **otras** tiendas también necesita
`withoutTenant()`.

Hay **tres autenticaciones separadas**, todas con Sanctum (tokens Bearer):

1. **Equipo de la tienda** — panel (header `X-Tenant` + Bearer). Token de 7 días, una sesión activa
   por usuario. Dos roles: `admin` (todo) y `staff` (el día a día: pedidos, productos sin borrar,
   reseñas, lista de espera). El rol se lee del usuario en cada petición, no del token. Un correo
   solo puede estar en el panel de **una** tienda, porque el login no pide la tienda.
2. **Clientes finales** — cuentas por tienda dentro del catálogo público (favoritos e historial).
   Token de 30 días. El middleware `customer` exige que el token sea **de esa tienda**.
3. **Operador de la plataforma** (`superadmin`) — panel propio, restringido además por IP. Token de
   1 día. Puede **entrar en una tienda como soporte**: eso emite un token del admin de esa tienda
   con la ability `soporte`, que caduca en 15 minutos, **no puede escribir nada** (middleware
   `soporte`, aplicado al grupo entero del panel) y queda anotado en `activity_logs` antes de
   emitirse. No revoca los tokens del dueño: puede seguir trabajando mientras el operador mira.

El registro de tiendas es **self-service** (`POST /api/auth/register`) con validación de slugs
reservados; la tienda nace sin publicar y se publica al verificar el correo. Las claves primarias son
**UUID v7** (cronológicos, para no fragmentar índices en MySQL).

---

## Backend — `saas-hardware-api/`

**Stack**: Laravel 13 · PHP 8.3 · Sanctum · spatie/laravel-multitenancy · Intervention Image v4 ·
Flysystem S3 (compatible con Cloudflare R2).

### Estructura

```
app/
├── Casts/SanitizedHtml.php        # Limpia el HTML al guardar
├── Console/Commands/              # CloseExpiredTrials (FUN-16), PurgeTrash (MOD-8) y
│                                  # CreateBackup (INF-7), los tres desde el scheduler;
│                                  # CreateSuperAdmin, a mano
├── Enums/ComponentType.php        # Qué pieza de PC vende una categoría
├── Exceptions/PlanLimitException  # 422 con la clave del límite alcanzado
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php              # Alta de tienda, sesión, verificación, reset
│   │   ├── TenantController.php            # Configuración y branding
│   │   ├── ProductController.php           # CRUD + reorder/import/bulk/duplicate
│   │   ├── CategoryController.php          # CRUD + reorder
│   │   ├── OrderController.php             # Estados, stock y venta de mostrador
│   │   ├── ReviewController.php            # Moderación
│   │   ├── StockNotificationController.php # Lista de espera
│   │   ├── PageController.php              # Páginas informativas
│   │   ├── ActivityController.php          # Actividad del panel: quién cambió qué (INF-3)
│   │   ├── TrashController.php             # Papelera de productos y pedidos (MOD-8)
│   │   ├── QuoteController.php             # La cotizacion de un pedido en PDF (MOD-2)
│   │   ├── CouponController.php            # Cupones de descuento (MOD-4)
│   │   ├── DashboardController.php         # Métricas
│   │   ├── PlanController.php              # Plan, límites y consumo
│   │   ├── PlatformController.php          # Panel del operador
│   │   └── Public/
│   │       ├── PublicCatalogController.php # Catálogo, ficha, reseñas, pedidos, avisos
│   │       ├── PublicAuthController.php    # Cuentas de cliente (incluida su recuperación de contraseña)
│   │       ├── PublicFavoritesController.php
│   │       ├── PublicOrdersController.php
│   │       └── PublicPlansController.php   # Catálogo de planes con precio, para la landing (INF-1)
│   ├── Middleware/
│   │   ├── InitializeTenantByHeader.php    # Panel: X-Tenant
│   │   ├── InitializeTenantBySlug.php      # Público: slug de la URL
│   │   ├── EnsurePanelUser.php             # Puerta del panel: admin o staff
│   │   ├── EnsureAdmin.php · EnsureSuperAdmin.php
│   │   ├── EnsureTenantCustomer.php        # El token es de ESTA tienda
│   │   ├── RestrictPlatformIp.php          # Lista de IPs del panel de plataforma
│   │   └── RestrictImpersonation.php       # Sesión de soporte: el panel, en solo lectura
│   └── Requests/                           # StoreProductRequest, StoreCategoryRequest, ...
├── Models/            # Tenant, User, Category, Product, ProductImage, ProductVariant,
│                      # Order, OrderItem, Coupon, Review, StockNotification, Page, ActivityLog
│   └── Concerns/BelongsToTenant.php        # Global scope que falla en cerrado
├── Notifications/     # VerifyEmail, ResetPassword, CustomerResetPassword, TeamInvitation,
│                      # NewOrder, OrderPlaced, OrderStatusChanged, BackInStock  (todas ShouldQueue)
├── Services/
│   ├── ImageService.php    # Subida y optimización a WebP; borra solo archivos que nadie usa
│   ├── OrderPricing.php    # Precios y total calculados en el servidor
│   ├── ViewCounter.php     # Visitas acumuladas en caché
│   └── DomainVerifier.php  # Comprueba el TXT del dominio propio
└── Support/
    ├── PlanGate.php        # Aplica los límites del plan
    ├── Bitacora.php        # Anota en la actividad lo que hace el equipo desde el panel
    ├── Paginacion.php      # Filas por página de un listado, con tope de 100
    ├── Busqueda.php        # La busqueda de productos (INF-6): la misma en catalogo, panel y CSV
    ├── Impuesto.php       # El impuesto de una venta (MOD-2): checkout, mostrador y reportes
    ├── Cupones.php        # Aplicar un cupon (MOD-4) y repartirlo al medir el margen
    ├── PreciosPorCantidad.php  # El precio por mayor (MOD-15): un tramo es un PRECIO, no un descuento
    ├── Seo.php            # Lo que ve un buscador (INF-4): contenido, canonico, JSON-LD y sitemap
    ├── Copias.php        # Volcado y comprobacion de la copia de seguridad (INF-7)
    ├── Reportes.php        # Las cuentas de los reportes, compartidas por la pantalla y el CSV
    ├── Money.php           # Formato de moneda por tienda
    ├── StoreUrl.php        # URL pública de una tienda, para los correos
    ├── TextoDeCorreo.php   # Escapa el Markdown de un dato antes de meterlo en una linea de correo (SEC-8)
    ├── CeldaCsv.php        # Neutraliza las formulas de una celda del CSV (SEC-7), y las desneutraliza al importar
    └── Suplantacion.php    # Sesión de soporte: ability, duración y cómo se reconoce

config/backups.php     # Dónde y cuánto se guardan las copias de seguridad (INF-7)
config/plans.php       # La matriz de planes y límites
config/timezones.php   # Zonas horarias que puede elegir una tienda (MOD-13).
                       # El criterio es DONDE HAY TIENDAS, no que moneda usan: sacarla
                       # de las monedas dejo fuera a los paises dolarizados. Copia en
                       # utils/timezones.ts, y un test comprueba que no se separen.
resources/views/       # catalog_og (lo que ve un crawler, INF-4), sitemap (INF-4)
                       # y cotizacion (el PDF de MOD-2)
routes/api.php         # Toda la API
routes/web.php         # Vistas previas Open Graph para crawlers + redirect al SPA
routes/console.php     # Tareas programadas (Schedule::command), sin Kernel.php en Laravel 13
tests/Feature/         # 66 archivos, 697 tests (+1 en tests/Unit)
```

### Endpoints

**Públicos, sin autenticación:**

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/register` | Alta de tienda (tenant + admin) · 5/min |
| POST | `/api/auth/login` | Login del panel (admin o staff) · 5/min por correo, 20/min por IP |
| POST | `/api/auth/forgot-password` · `/reset-password` | Recuperación de contraseña · 5/min |
| GET | `/api/auth/verify-email/{id}/{hash}` | Verificar correo (URL firmada) → redirige al SPA |
| GET | `/api/public/resolve-domain` | Resuelve tenant por dominio propio |
| GET | `/api/public/plans` | Catálogo de planes con precio, para la landing (INF-1) |
| GET | `/{slug}/sitemap.xml` · `/robots.txt` | Sitemap de una tienda y robots del host (INF-4). No son `/api/`: van en `routes/web.php` |

**Catálogo público** (prefijo `/api/public/{slug}`, throttle de grupo 120/min, tenant por slug):

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/` | Datos y branding de la tienda (solo columnas públicas) |
| GET | `/categories` | Categorías activas (con `component_type`) |
| GET | `/products` | Búsqueda, filtros (`category_id`, `component_type`, `specs`, `in_stock`), orden y paginación |
| GET | `/facets` | Valores de spec reales del catálogo, para los filtros |
| GET | `/products/{product}` | Ficha: galería, variantes, reseñas aprobadas, relacionados |
| POST | `/products/{product}/reviews` | Crear reseña (Turnstile) · 10/min |
| POST | `/products/{product}/notify-me` | "Avísame cuando llegue" (`variant_id` obligatorio si el producto tiene variantes) · 10/min |
| POST | `/orders` | Crear pedido; cada línea con `variant_id` si el producto tiene variantes; `delivery_method` (`pickup`/`delivery`) y `coupon_code` opcionales — el costo del envío y el descuento los calcula el servidor, nunca lo que mande el cliente (MOD-1, MOD-4) · 10/min |
| POST | `/coupons/check` | Comprueba un código desde el carrito y devuelve cuánto descontaría (MOD-4). Limitador propio (`throttle:cupon`, **5/min**) porque es el único sitio público donde adivinar a ciegas tiene premio |
| GET | `/pages` · `/pages/{page_slug}` | Páginas informativas |
| POST | `/auth/register` · `/auth/login` | Cuenta de cliente · 5/min (el login, por correo; 20/min por IP) |
| POST | `/auth/forgot-password` · `/auth/reset-password` | Recuperar contraseña del cliente · 5/min |

**Cliente autenticado** (Bearer + middleware `customer`, dentro de `/api/public/{slug}`):

| Método | Ruta | Descripción |
|---|---|---|
| POST·GET | `/auth/logout` · `/auth/me` | Sesión del cliente |
| GET | `/my-orders` | Historial de pedidos |
| GET·POST | `/favorites` · `/favorites/{product}` | Listar y alternar favoritos |

**Panel** (Bearer + `X-Tenant: {slug}` + rol `admin` o `staff`). La columna *Staff* dice qué puede
un colaborador; un `admin` puede todo. El reparto y su criterio están en `routes/api.php`:

| Método | Ruta | Descripción | Staff |
|---|---|---|---|
| POST·GET | `/api/auth/logout` · `/api/auth/me` | Sesión | Sí |
| POST | `/api/auth/email/resend` | Reenviar verificación · 3/min | Sí |
| GET | `/api/dashboard/stats` | Métricas del Resumen: totales históricos, más vistos y últimos pedidos | Sí |
| GET | `/api/reports` | Reportes de un rango (MOD-9): `desde`, `hasta` y `agrupacion=dia\|mes` (por defecto, 30 días por día; tope 366 días / 60 meses). El rango y el agrupado se leen en la zona de la tienda (MOD-13) y `rango.zona` la devuelve. Responde resumen, serie, más vendidos y stock bajo. Las claves `costo`, `utilidad`, `margen` y `lineas_sin_costo` **no salen para staff** (MOD-6) | Sí, sin costos |
| GET | `/api/plan` | Plan, límites y consumo | Sí |
| GET · PUT | `/api/tenant` | Configuración y branding, incluidos `payment_methods` (MOD-3), `delivery_enabled`/`delivery_cost` (MOD-1), `timezone` (MOD-13, whitelist de `config/timezones.php`) y `tax_enabled`/`tax_name`/`tax_rate`/`tax_included` (MOD-2) | Solo `GET` |
| POST | `/api/tenant/custom-domain/verify` | Comprobar el TXT del dominio propio | No |
| CRUD | `/api/products` (+ `POST /reorder`, `/{id}/duplicate`) | Productos; alta y edición aceptan `variants` (JSON) y `variant_images[<posición>]`. `cost` (y `variants[].cost`) solo lo ve y lo escribe un admin: si la clave no llega, el costo guardado **no se toca** (MOD-6) | Todo menos `DELETE` |
| POST | `/api/products/import` · `/api/products/bulk` | Import CSV y acciones masivas. El CSV admite una columna `variante` (MOD-12): vacía, la fila es un producto; rellena (`Capacidad: 1 TB`, hasta tres opciones separadas por `|`), la fila es una variante del producto que se llame igual, y `sku`/`precio`/`precio_oferta`/`costo`/`tramos`/`stock` son suyos. Columna `estado` (FUN-21): `publicado`/`borrador` (o `published`/`draft`); vacia crea publicado y al actualizar no toca el estado, y un valor que no se entiende rechaza la fila. Columna `tramos` (MOD-15): el precio por mayor en formato `10:90|25:85` —desde cuantas unidades y a cuanto sale cada una—; una fila cuyo tramo no baje del precio de esa fila se rechaza. Campo `modo` (FUN-17) para lo que ya existe —mismo nombre, sin mayúsculas ni tildes—: `omitir` (por defecto), `actualizar` (una celda vacía no borra) o `duplicar`; responde `created_count`/`updated_count`/`unchanged_count`/`skipped_count` y `changes`, una entrada por producto con su fila, qué pasó y qué cambió (FUN-19; lo que el archivo trae igual no se guarda y cuenta como `sin_cambios`). La `categoria` tiene que existir: **no crea categorías** y la fila que nombra una que no existe se rechaza (FUN-18) | No |
| CRUD | `/api/categories` (+ `POST /reorder`) | Categorías | Solo `GET` |
| CRUD | `/api/orders` | Pedidos y venta de mostrador; el detalle trae `utilidad`, `costo_total` y `lineas_sin_costo` **solo para admin** (MOD-6) | Todo menos `DELETE` |
| GET | `/api/orders/{order}/pdf` | La cotización del pedido en PDF (MOD-2), con el logo y el desglose del impuesto. **No es un comprobante fiscal** y el propio documento lo dice | No |
| CRUD | `/api/coupons` | Cupones de descuento (MOD-4): código único por tienda, porcentaje o monto, con vigencia, tope de usos y compra mínima. Techo de 100 por tienda | Sí |
| GET | `/api/customers` · `/api/customers/{id}` | Clientes con cuenta y lo que han comprado; orden `recientes`/`gasto`/`pedidos`, búsqueda por nombre, correo o teléfono (MOD-10) | Sí |
| GET | `/api/products/export` · `/api/orders/export` | Exportar a CSV (MOD-7). Acepta los filtros del listado; el de pedidos además `desde`/`hasta`. El del catálogo saca **una fila por variante** con la columna `variante` que lee el importador (MOD-12) y la columna `tramos` con el precio por mayor (MOD-15) | No |
| GET | `/api/reports/export` | El reporte del rango en CSV (MOD-9): mismos parámetros que `/api/reports`, con la serie y los más vendidos —sin recortar— en dos bloques | No |
| GET·PUT·DELETE | `/api/reviews` | Moderación | Sí |
| GET·PUT·DELETE | `/api/stock-notifications` | Lista de espera | Sí |
| CRUD | `/api/pages` | Páginas informativas | No |
| GET·POST·PUT·DELETE | `/api/users` (+ `POST /{id}/resend-invitation`) | Equipo: invitar con `role` (`staff` por defecto), cambiar rol, activar, eliminar | No |
| GET | `/api/activity` | Actividad del panel: quién cambió qué (filtros `area`, `actor` por correo; 30 por página) | No |
| GET·DELETE | `/api/trash` | La papelera (MOD-8): `?tipo=productos\|pedidos` (paginado, con los totales de los dos) y `DELETE` para vaciarla entera | Sí |
| POST·DELETE | `/api/trash/{tipo}/{id}/restore` · `/api/trash/{tipo}/{id}` | Restaurar (puede dar 422 si el plan ya no tiene hueco) y eliminar definitivamente | Sí |

**Plataforma** (Bearer + rol `superadmin` + lista de IPs):

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/platform/login` · `/logout` | Sesión del operador |
| GET | `/api/platform/me` · `/tenants` | Perfil y listado de tiendas |
| GET | `/api/platform/stats` | Resumen del negocio: altas, estados, reparto por plan y totales |
| GET | `/api/platform/logs` | Bitácora del operador (filtros `tenant_id`, `action`); no incluye la actividad de las tiendas |
| GET | `/api/platform/tenants/{tenant}` | Ficha: plan y consumo, equipo, últimos pedidos, bitácora |
| PUT | `/api/platform/tenants/{tenant}` | Suspender/reactivar y cambiar plan |
| POST | `/api/platform/tenants/{tenant}/password-reset` | Mandar recuperación al dueño |
| POST | `/api/platform/tenants/{tenant}/impersonate` | Entrar como soporte: token de 15 min y **solo lectura** |
| POST | `/api/platform/tenants/{tenant}/rescue-admin` | Nombrar admin a un colaborador cuando la tienda se quedó sin ninguno |

> El token de soporte lleva la ability `soporte`, y el middleware `soporte` —aplicado al grupo
> entero del panel— rechaza con 403 cualquier método que no sea GET/HEAD (salvo `/api/auth/logout`).
> `GET /api/auth/me` devuelve `soporte: true` para que el panel avise de que se está en casa ajena.

**Rutas web (no API)** — `routes/web.php`: `/{slug}`, `/{slug}/product/{id}`, `/{slug}/p/{pageSlug}`
y `/{slug}/builder` devuelven una vista **indexable** si quien pide es un crawler conocido, y
redirigen a `FRONTEND_URL` si es una persona. Esa vista (`INF-4`) lleva contenido de verdad
—productos con enlace y precio, specs, páginas informativas—, `canonical`, y JSON-LD de `Product`
(precio, moneda, disponibilidad, marca) y de `Store`; los datos los arma `App\Support\Seo`.
Además hay `/{slug}/sitemap.xml` (no exige crawler: Search Console lo pide sin declararse) y
`/robots.txt`. Las mismas cuatro vistas existen otra vez sin el
`{slug}`, bajo `Route::domain('{tenantDominio}')`, para las tiendas con **dominio propio y
verificado**: resuelven la tienda por el `Host` de la petición en vez de por slug, y un humano sale
hacia `FRONTEND_URL/{slug}/...` en vez de a la ruta pedida tal cual (sin slug, esa URL no sabría de
qué tienda se trata). Un host que no es ni el de la app ni el de ninguna tienda cae en 404 —o en la
vista `welcome` de siempre, si es la raíz, o en el `robots.txt` de la plataforma si es esa ruta.

> **Un dominio propio, además, puede tener `robots.txt` y `sitemap.xml` en su raíz** (`INF-4`), y esa
> es la diferencia real que da para SEO: los dos son **del host, no de un path**, así que una tienda
> por slug —que comparte host con la plataforma— no puede tener los suyos ahí; su sitemap vive en
> `/{slug}/sitemap.xml` y se envía a mano en Search Console.
>
> **`public/robots.txt` está borrado a propósito.** Laravel trae uno estático y el servidor web lo
> sirve **antes** de llegar a ninguna ruta: con él ahí, ni el host de la aplicación ni un dominio
> propio reciben el suyo. La suite no puede verlo —en pruebas no hay archivos estáticos—, así que hay
> un caso que comprueba que el archivo no ha vuelto.

### Variables de entorno relevantes

| Variable | Uso |
|---|---|
| `DB_CONNECTION`, `DB_*` | Base de datos (MySQL en producción; SQLite en los tests) |
| `FRONTEND_URL` | Base del SPA. De ahí cuelgan los enlaces de los correos y los redirects |
| `BACKUP_DISK` · `BACKUP_RETENTION_DAYS` · `BACKUP_BIN_PATH` | Copias de seguridad (`INF-7`): disco destino (**fuera de este servidor** en producción; uno público se rechaza), días que se guardan y carpeta de `mysqldump` si no está en el PATH del cron |
| `CORS_ALLOWED_ORIGINS`, `CORS_ALLOWED_ORIGIN_PATTERNS` | Orígenes permitidos (los dominios propios no se conocen de antemano) |
| `FILESYSTEM_DISK` + `R2_*` | Imágenes. **En producción tiene que ser `r2` o la app no arranca**; salida explícita con `ALLOW_LOCAL_STORAGE=true` |
| `QUEUE_CONNECTION` | Los correos van por cola: **hace falta un worker** |
| `MAIL_*` / `RESEND_API_KEY` | Envío de correo |
| `TURNSTILE_SECRET_KEY` | Anti-bot de reseñas. Fuera de local, sin clave se rechazan con 502 |
| `PLATFORM_ALLOWED_IPS` | IPs del panel de plataforma. **En producción, vacío = no entra nadie** |
| `PASSWORD_UNCOMPROMISED` | Contrasta contraseñas contra Have I Been Pwned |
| `APP_DEBUG` | **En producción tiene que ser `false` o la app no arranca** |

### Comandos

```bash
php artisan test        # 794 tests (PHPUnit, SQLite en memoria)
php artisan trials:cerrar-vencidas   # Suspende tiendas con la prueba vencida (normalmente vía Schedule::command, diario)
php artisan papelera:purgar          # Borra lo que lleve +30 días en la papelera, con sus fotos (MOD-8; ídem, diario)
php artisan copias:crear             # Copia de seguridad de la base y purga de las caducadas (INF-7; ídem, 03:30)
vendor/bin/pint         # Formateo (Laravel Pint)
composer dev            # serve + queue:listen + pail + vite en paralelo
composer setup          # install + .env + key + migrate + build
```

---

## Frontend — `saas-hardware-frontend/`

**Stack**: React 19 · Vite · TypeScript · React Router 7 · TanStack Query (datos de servidor) ·
Zustand (estado global) · Axios con interceptores (Bearer + `X-Tenant`) · lucide-react ·
react-hot-toast. CSS propio, sin framework.

### Rutas

**Públicas** (con prefijo `{slug}`, o sin él bajo dominio propio):

| Ruta | Página |
|---|---|
| `/{slug}` · `/` | Catálogo (`/` resuelve la tienda por el dominio; en un host de `VITE_PLATFORM_HOSTS`, landing — `pages/marketing/LandingPage.tsx`, INF-1/INF-9) |
| `/{slug}/product/:id` · `/product/:id` | Ficha de producto |
| `/{slug}/builder` · `/builder` | Armador de PC |
| `/{slug}/p/:pageSlug` · `/p/:pageSlug` | Página informativa |

**Cuenta y panel:**

| Ruta | Página |
|---|---|
| `/login` · `/register` | Acceso y alta de tienda |
| `/forgot-password` · `/reset-password` | Recuperación de contraseña |
| `/dashboard` | Resumen con métricas |
| `/dashboard/products` · `/categories` · `/orders` · `/customers` · `/reports` · `/pages` · `/reviews` · `/waitlist` | Gestión (`/customers`: clientes con cuenta y su historial, MOD-10; `/reports`: ventas por periodo, más vendidos y stock bajo, MOD-9) |
| `/dashboard/users` · `/dashboard/activity` | Equipo y actividad del panel (solo admin; filtros de actividad en la URL: `area`, `persona`, `pagina`) |
| `/dashboard/trash` | Papelera de productos y pedidos (solo admin, `MOD-8`; el tipo va en la URL: `tipo`, `pagina`) |
| `/dashboard/coupons` | Cupones de descuento (solo admin, `MOD-4`) |
| `/dashboard/settings` | Branding, tema, portada, dominio, favicon |
| `/platform/login` | Acceso del operador del SaaS |
| `/platform` · `/platform/tenants` · `/platform/tenants/:id` · `/platform/logs` | Panel del operador: resumen, tiendas, ficha y bitácora (layout en `PlatformLayout`) |
| `*` | 404 explícito |

**Carga diferida**: todo va con `lazy()` **menos el catálogo**, que se queda estático a propósito por
ser la ruta de entrada de casi todo el tráfico.

### Estructura

```
src/
├── api/            # Cliente Axios (interceptores) y funciones por recurso
├── router/         # Rutas, PrivateRoute, SoloAdmin (pantallas de admin), Suspense y errorElement
├── pages/
│   ├── auth/       # Login, RegisterStore, ForgotPassword, ResetPassword
│   ├── dashboard/  # Overview, Products, Categories, Orders, Pages, Reviews,
│   │               # Waitlist, Users, Activity, Settings (layout en DashboardPage)
│   ├── platform/   # PlatformLogin, PlatformLayout, PlatformOverview, Platform (tiendas),
│   │               # PlatformTenant (ficha), PlatformLogs
│   └── public/     # Catalog, ProductDetail, PcBuilder, PageDetail
├── components/
│   ├── dashboard/  # NewOrderModal (venta de mostrador), EditorDeVariantes, VerifyEmailBanner,
│   │               # EditorDeTramos (precio por mayor, MOD-15),
│   │               # SupportBanner, InformeDeImport (resultado del import CSV)
│   ├── public/     # CartDrawer, CustomerAccountModal, StoreHeader, StoreFooter,
│   │               # AnnouncementBar
│   └── ui/         # Dialogo (ventana flotante del panel), CategoryIcon, ImageSourceField,
│                   # ErrorBoundary, fallbacks
├── stores/         # Zustand: authStore (admin), customerAuthStore (cliente),
│                   # platformAuthStore, cartStore (por tienda), tenantStore
├── hooks/          # useTenantBranding (título, favicon, meta/OG), useTenantTheme,
│                   # useBloqueoDeScroll
├── utils/          # money, theme, themePresets, neutrals, shape, fonts, hero,
│                   # branding, phone, sanitizeHtml, componentTypes, variants,
│                   # variantesEnFormulario, plataforma (¿es el host del SaaS?),
│                   # paymentMethods (qué mostrarle al comprador)
├── types/          # Tipos compartidos de la API
└── test/           # setup de Vitest y datos de ejemplo (fixtures) para los tests
```

Los tests van **al lado de lo que prueban** (`cartStore.test.ts` junto a `cartStore.ts`).

### Claves de diseño

- **Estilos: cada componente tiene su `.css` al lado.** El de una **página** va acotado con
  `:where(.page-x)` y esa clase se pone en la raíz —y también en los `return` tempranos de carga y
  error—; el de un **componente** es global. `index.css` se importa **el primero** en `main.tsx` a
  propósito: si no, las hojas de página pierden los empates de especificidad contra el sistema de
  diseño.
- **Ojo con la especificidad**: una regla `:where(.page-x) .clase-de-componente` **empata** con el
  CSS del componente y el desempate lo decide el orden de carga de los chunks. Ese tipo de regla va
  en la hoja del componente.
- **La tarjeta de estado (`.state-card`) y el icono que gira (`.spinner`) tienen valor por defecto en
  `index.css`** (UI-13): una pantalla nueva no tiene que copiarlos, y la que los define en su hoja
  solo pisa lo que ella fija. `.spinner` solo gira; el color lo pone quien lo usa.
- **Toda ventana flotante del panel es `<Dialogo>`** (`components/ui/Dialogo.tsx`), nunca un
  overlay propio: va por portal a `.dashboard-layout` (si no, la barra superior y el menú quedan por
  encima del velo), bloquea el scroll y cierra con Escape. Recibe en `className` la clase de la
  página (`page-products`…) para que sus reglas `:where(.page-x)` sigan llegando al contenido, que es
  `<form className="dialogo-cuerpo">` terminado en `<div className="dialogo-acciones">`.
- El **branding por tienda** se aplica en tiempo de ejecución (`useTenantBranding`, `useTenantTheme`)
  a partir de lo que devuelve la API: variables CSS, modo claro/oscuro, título y favicon.
- **Los filtros del catálogo viven en la URL**, no en estado local: un enlace compartido reproduce
  lo que el remitente estaba viendo.
- El **carrito** (`cartStore`) se persiste en `localStorage` y es por tienda. Una línea es producto +
  variante: el mismo producto en 16 GB y en 32 GB son dos líneas.
- **Variantes: el precio y el stock de un producto con variantes son un resumen** (el de la más
  barata y la suma), para listar, ordenar y filtrar. Todo lo que **cobra o descuenta** usa la
  variante: `datosDeVenta()` en `utils/variants.ts`, `OrderPricing` en el backend.
- La sesión de cliente (`customerAuthStore`) es independiente de la de admin (`authStore`).

### Variables de entorno y scripts

| Variable | Uso | Por defecto |
|---|---|---|
| `VITE_API_URL` | URL base de la API | `http://localhost:8000/api` |
| `VITE_TURNSTILE_SITEKEY` | Site key del widget Turnstile | sin ella, el formulario de reseñas queda deshabilitado |
| `VITE_PLATFORM_HOSTS` | Hosts de la propia plataforma, separados por comas (`plataforma.com,www.plataforma.com`). En ellos `/` es la landing; en cualquier otro host, `/` resuelve la tienda por dominio propio (INF-9). **En producción hay que ponerlo al hacer el build**, o la landing no sale nunca | `localhost,127.0.0.1` |

```bash
npm run dev       # Desarrollo con HMR (http://localhost:5173)
npm run build     # tsc -b + build de producción en dist/
npm run preview   # Sirve el build
npm run lint      # ESLint
npm test          # 195 tests (Vitest + Testing Library, jsdom)
npm run test:watch
```

**Qué cubren los tests de frontend:** el carrito (líneas por variante, total con el precio de la
variante y su oferta, cambio de tienda), el checkout de `CartDrawer` (lo que se manda a
`POST /orders`, el mensaje de WhatsApp con el número de pedido, y que un pedido rechazado no vacíe
el carrito), la validación de variantes del formulario de producto, los mensajes de error de los
formularios sin sesión (`erroresDeFormulario`), los interceptores de Axios (qué token va a cada
ruta y qué sesión cierra un 401), las guardas `PrivateRoute`/`SoloAdmin`, qué host es el de la
plataforma (`utils/plataforma`), el envío y los métodos de pago en el checkout (`utils/paymentMethods`,
la selección de entrega y su costo en `CartDrawer`), que el `FormData` de Configuración mande
booleanos de verdad (`api/tenant`), el margen y qué precio se compara con el costo (`utils/margen`),
la descarga de las exportaciones (`api/exportaciones`: blob, filtros y nombre del archivo), la
pantalla de clientes (`CustomersPage`: totales, cliente sin compras y la ficha que solo se pide al
abrirla), la de reportes (`ReportsPage`: que el rango y la agrupación se le pidan al servidor, que
sin permiso no se pinte utilidad ni margen, la serie como tabla y el motivo de un rango rechazado),
y `money`/`phone`. La red se sustituye en cada test; ninguno
necesita la API levantada. Las páginas grandes (ficha, armador, formulario de producto) todavía no
tienen tests.

---

## Convenciones de trabajo

- **Commits**: mensajes en español, estilo Conventional Commits (`feat:`, `fix:`, `docs:`,
  `feat(scope):`), en minúsculas y **sin acentos ni ñ** en el asunto.
- **Planes**: la matriz de límites vive en `config/plans.php` y la aplica `App\Support\PlanGate`
  **desde los controladores** (no desde un middleware: el import CSV pide hueco para N filas de
  golpe). El gate va **al principio del método**, antes de escribir o de subir archivos. En la
  matriz, un entero es un tope, `null` es *sin tope* y un booleano es una función incluida o no; un
  plan que no está en la lista **cae al plan por defecto y no a `null`**.
- **Al añadir un sitio que cree algo limitado**, acordarse del gate; al añadir una consulta que mire
  por encima de las tiendas, `withoutTenant()` explícito.
- **Los topes son de la creación, no del estado existente**: bajar de plan nunca borra nada.
- **El costo de compra (`products.cost`, `product_variants.cost`) está oculto por defecto**: va en el
  `$hidden` de los modelos y solo lo enseña `App\Support\Costos::mostrar()`, que comprueba que quien
  mira sea admin. Una consulta pública nueva no lo filtra por descuido: no lo lleva de entrada. Y en
  el formulario, **una clave `cost` ausente significa "no lo toques"**, no "bórralo": es lo que
  impide que un colaborador —que no ve el campo— vacíe los costos al editar un producto.
- **Lo que no es del comprador va oculto en el modelo, no quitado en el controlador** (`ACC-1`,
  `ACC-7`): el correo, el `visitor_id` y el `user_id` de las reseñas (`Review::$hidden`), las visitas
  de cada producto (`Product::$hidden`) y **la relación `tenant` de cualquier modelo con
  `BelongsToTenant`**, que el trait oculta siempre. Esa última es la que no se ve venir: basta con
  que algo lea `$modelo->tenant` —una notificación, un hook— para que la relación quede cargada y
  la respuesta salga con la fila entera de la tienda. Quien necesite la tienda en una respuesta la
  manda en una clave propia. Al añadir una ruta pública, se suma al barrido de
  `RutasPublicasNoExponenDatosInternosTest`.
- **La utilidad de una venta sale de `order_items.unit_cost`**, el costo copiado el día de la venta,
  igual que `unit_price`. Nunca del costo actual del producto: cambiar el costo hoy no puede
  reescribir lo que se ganó ayer. El envío cobrado (`delivery_cost`) no cuenta como utilidad.
- **Cambiar precio o stock de una variante fuera del formulario** (una venta, una devolución, un
  ajuste en lote) obliga a llamar después a `Product::sincronizarResumenDeVariantes()`, o el
  catálogo enseñará un precio y un stock que ya no son. La cuenta en sí vive en
  `Product::resumenDeVariantes()`, que es lo que usa el import CSV para escribir la ficha y sus
  variantes en el mismo INSERT en lote sin releerlas: si algún día cambia la regla del resumen, se
  cambia ahí y los dos caminos la siguen.
- **Borrar un producto o un pedido es mandarlo a la papelera** (`MOD-8`): los dos modelos usan
  `SoftDeletes`, así que desaparecen del catálogo, del panel, del buscador, de los reportes y de los
  topes del plan sin tocar ninguna de esas consultas. Las excepciones son las dos que **no** pasan
  por Eloquent y hay que recordar a mano: `Reportes::lineasDelRango()`, que hace un join con
  `DB::table('orders')` y lleva su `whereNull('orders.deleted_at')` escrito —sin él sumaría ventas
  borradas y cuadraría consigo mismo—, e `ImageService::borrarSiNadieLasUsa()`, donde lo que se
  quiere es justo lo contrario: un producto en la papelera **sí** cuenta como que usa su foto.
  **Las fotos no se borran al mandar a la papelera**, sólo al vaciarla o al purgarla: restaurar un
  producto sin sus imágenes sería media restauración. Un producto en la papelera **no ocupa hueco del
  plan**, y por eso el gate se comprueba al **restaurar**.
- **Las fotos de producto se borran con `ImageService::borrarSiNadieLasUsa()`, después de cambiar la
  base**, nunca borrando el archivo directamente: duplicar un producto comparte las URL con el
  original, y borrar a ciegas le rompía las fotos al otro.
- **Un booleano en `FormData` (Configuración, productos) va como `'1'`/`'0'`, nunca `''`.** A
  diferencia de un campo de texto —donde `''` sirve para "vaciar" y el middleware
  `ConvertEmptyStringsToNull` lo convierte en `null`—, la regla `boolean` de Laravel valida `''` como
  inválido, y sin convertir el valor en el backend antes de guardar, un string como `"0"` se guarda
  literal en una columna JSON — y en el navegador **`"0"` es verdadero** (cualquier string no vacío
  lo es). Es el fallo real que encontró `MOD-3`: un método de pago recién apagado volvía a aparecer
  marcado al recargar Configuración. Si el dato se guarda dentro de un JSON (como `payment_methods`),
  conviértelo con `filter_var($valor, FILTER_VALIDATE_BOOLEAN)` antes de guardar.
- **El orden del precio de una venta es uno solo y no se reordena**:
  `productos → descuento (MOD-4) → + envío (MOD-1) → impuesto (MOD-2) → total`. El descuento va
  **antes** del impuesto o el cupón descontaría también de la parte que es del fisco, que la tienda
  paga igual; y va **solo sobre los productos**, porque lo que se le paga al repartidor no baja
  porque el comprador tenga un código. Del navegador viaja **el código, nunca el descuento**, igual
  que con el envío viaja el método y nunca el costo.
- **El precio por mayor se calcula con `App\Support\PreciosPorCantidad`** (MOD-15), nunca a mano:
  lo piden el checkout público, la venta de mostrador, la ficha y el importador CSV. La decisión de
  la que cuelga todo lo demás es que **un tramo es un PRECIO y no un descuento**: el precio del tramo
  entra en `order_items.unit_price` igual que entraba el de oferta, así que `subtotal` sigue siendo
  precio × cantidad y ni la utilidad del pedido, ni los reportes, ni el reparto proporcional del
  cupón necesitan saber que los tramos existen. Por eso tampoco reordena nada: los productos entran
  al orden de arriba ya a su precio por cantidad, y el cupón **se acumula** encima. Dos reglas que no
  se ven en la columna: **un tramo nunca cobra más que el precio normal** (con una oferta por debajo
  gana la oferta, o quien compra diez pagaría más por unidad que quien compra una), y **todas las
  unidades de la línea van al mismo precio** —tramo plano, no marginal—, que es lo único que deja que
  la línea tenga un solo `unit_price`. `price_tiers` es JSON (`[{"min":10,"price":90}]`) en
  `products` y `product_variants`, y se normaliza **al leer y al guardar**: `json_encode(90.0)`
  escribe `90`, así que sin el accessor la misma columna devuelve enteros o decimales según el
  número. Con variantes, los de la ficha son un resumen de la más barata y **no cobran nada**, como
  el precio y el costo.
- **Una división en SQL lleva `* 1.0`.** SQLite divide enteros como enteros (`200/1000` da `0`) y
  MySQL devuelve decimal, así que la misma expresión da dos resultados según el motor. Es la trampa
  de las funciones de fecha **al revés y peor**: ahí la suite pasaba y producción fallaba; aquí
  producción acierta y **la que miente es la suite**, que deja de poder distinguir una fórmula buena
  de una rota. Las dos expresiones que reparten dinero por línea (`Impuesto::expresionNeta()` y
  `Cupones::expresionNeta()`) lo llevan, y sus tests usan cifras que **no** dan división exacta a
  propósito.
- **El impuesto de una venta se calcula con `App\Support\Impuesto`** (MOD-2), nunca a mano: lo piden
  el checkout público, la venta de mostrador y los reportes. Dos reglas que no se ven en las
  columnas. La primera: **`total` es siempre lo que paga el cliente y `tax_amount` es cuánto de eso
  es impuesto**, cobre la tienda como cobre — con los precios incluyéndolo el total no cambia y el
  impuesto se saca hacia atrás (`base * tasa / (100 + tasa)`, no `base * tasa / 100`, que es el error
  clásico y cobra de más); sumándose al final, `total = base + impuesto`. La segunda: **el margen se
  mide sin impuesto**, igual que se mide sin envío, o con los precios llevándolo dentro la utilidad
  sale inflada en el porcentaje entero — y ese número es el que el dueño usa para poner precios. Por
  eso `Order::getUtilidadAttribute()` y `Reportes` usan los dos el mismo helper
  (`Impuesto::dentroDe()` y `Impuesto::expresionNeta()`): si uno descontara y el otro no, la ficha de
  un pedido y el reporte darían dos márgenes de la misma venta. En `orders` los cuatro `tax_*` son un
  **snapshot** del día de la venta, como `delivery_cost` y `unit_cost`: `null` es "se vendió sin
  impuesto", que no es lo mismo que "con el 0%".
- **Toda búsqueda de productos pasa por `App\Support\Busqueda`**, nunca un `LIKE` escrito a mano en
  el controlador. La ofrecen tres sitios —el catálogo público, el listado del panel y la exportación
  a CSV— y cada uno miraba columnas distintas: la misma palabra daba tres resultados y el CSV no
  traía lo que el dueño tenía en pantalla. Ahí dentro va además lo que no es obvio: el término se
  parte en palabras (todas tienen que aparecer, en cualquier orden) y `%` y `_` se escapan con
  `ESCAPE '!'`, **declarado explícitamente** porque MySQL tiene carácter de escape por defecto y
  SQLite —donde corre la suite— no, así que sin declararlo los dos motores no harían lo mismo.
- **Listados paginados con `Paginacion::porPagina($request, $porDefecto)`**, nunca
  `$request->integer('per_page')` a secas: sin tope, `per_page=100000` devuelve la tabla entera.
- **Lo que se difiere al envío de la respuesta (`streamDownload`, `defer()`) corre fuera del alcance
  de la tienda**: para entonces el middleware ya la olvidó, y el fallo en cerrado devuelve cero filas
  **sin ningún error**. Esas consultas van con `withoutTenant()` y el `tenant_id` fijado antes de
  empezar (`ExportController`, `App\Support\Reportes`).
- **Una función de fecha en SQL se escribe para los dos motores.** MySQL (producción) no tiene
  `strftime` y SQLite (la suite) no tiene `DATE_FORMAT`: escribir solo una deja los tests en verde y
  un 500 en el servidor. Se elige por `DB::connection()->getDriverName()`, como en
  `Reportes::expresionDePeriodo()`. **Y nunca `CONVERT_TZ`**: devuelve `NULL` si el MySQL no tiene
  cargadas las tablas de zonas horarias —lo normal en una instalación nueva— y eso agrupa todo en
  una fila vacía sin dar ningún error; el desplazamiento va en minutos.
- **Todo se guarda en UTC; la zona de la tienda (`tenants.timezone`, MOD-13) solo decide cómo se
  lee.** Un rango de fechas del panel son días del calendario de la tienda: sus límites se calculan
  con Carbon y se comparan como instantes (`created_at >= X AND < Y`), nunca con `whereDate`, que
  compara la fecha UTC y se come las primeras horas del día. En el frontend, una fecha del panel se
  pinta con `utils/fechas.ts` y la zona de la tienda, nunca con `toLocaleDateString()` a secas, que
  usa el reloj de quien mira.
- **Toda ruta nueva del panel que escribe decide si anota en la actividad.** Se anota con
  `App\Support\Bitacora::anotar()` después de guardar (nunca tumba la acción si falla). Si la ruta no
  debe anotar, va en `SIN_ANOTAR` de `BitacoraDeTiendaTest` con su motivo; si no está en ninguna de
  las dos listas, ese test se pone en rojo. `ActivityLog` separa la actividad de las tiendas
  (`origen = tienda`) de la del operador (`plataforma`), y cada pantalla lee solo la suya.
- **Rate limits con nombre, nunca `throttle:5,1` a secas**: un throttle sin nombre usa como clave
  solo la IP, así que todas las rutas que lo llevan comparten un contador. Los limitadores
  (`login`, `auth_publica`, `escritura_publica`, `cupon`, `verificacion_correo`, `reenvio_correo`) están en
  `AppServiceProvider::limitesPorFormulario()`, con la ruta en la clave; el de login cuenta por
  correo + IP.

---

## Documentación del proyecto

La carpeta `docs/` es **documentación local** (está en `.gitignore`, así que no viaja en el
repositorio):

| Archivo | Contesta |
|---|---|
| `funcionalidades.md` | **Qué hace** el sistema, qué no, y dónde está flojo |
| `auditoria.md` | **Por qué** está así: el porqué de cada hallazgo y decisión, por ID |
| `pendientes.md` | **Qué falta** hoy |
| `mejoras_propuestas.md` | **En qué orden**: el backlog por fases y su histórico |

Los IDs que aparecen en los comentarios del código (`AUD-4`, `FUN-8`, `SAAS-3`, `TEC-10`…) se
explican en `auditoria.md`.
