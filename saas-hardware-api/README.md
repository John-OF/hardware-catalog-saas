# saas-hardware-api

API REST en **Laravel 13** (PHP 8.3) del proyecto [Hardware Catalog SaaS](../README.md). Expone el catálogo público por tienda, la gestión completa del panel de administración y las cuentas de clientes finales, todo bajo un esquema **multi-tenant de base de datos única**.

## Stack

- Laravel 13 · PHP 8.3
- [Laravel Sanctum](https://laravel.com/docs/sanctum) — autenticación por tokens (admins de tienda y clientes)
- [spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy) — aislamiento por tenant
- [Intervention Image v4](https://image.intervention.io) — optimización de imágenes a WebP
- Flysystem S3 — almacenamiento compatible con S3 / Cloudflare R2
- UUIDs (v7) como claves primarias

## Multitenancy

Todos los tenants comparten una base de datos; cada fila lleva `tenant_id`. El tenant activo se resuelve según el tipo de ruta:

- **Rutas privadas** (`auth:sanctum` + middleware `tenant`): por el header `X-Tenant: {slug}` — ver `app/Http/Middleware/InitializeTenantByHeader.php`.
- **Rutas públicas** (`/api/public/{slug}/...`): por el slug en la URL.
- **Dominios personalizados**: `GET /api/public/resolve-domain` devuelve el tenant asociado al dominio desde el que se sirve el frontend.

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configura DB_CONNECTION y DB_* en .env (MySQL/MariaDB o SQLite)
php artisan migrate
php artisan storage:link   # necesario para servir imágenes en local
php artisan serve --port=8000
```

La API queda en `http://localhost:8000` (el frontend espera `http://localhost:8000/api` por defecto).

### Variables de entorno relevantes

| Variable | Uso |
|---|---|
| `DB_CONNECTION`, `DB_*` | Conexión de base de datos |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL` | Almacenamiento S3/R2 para imágenes en producción. Sin configurar, `ImageService` usa el disco `public` local |
| `TURNSTILE_SECRET_KEY` | Clave secreta de Cloudflare Turnstile para validar reseñas. Sin configurar, usa la clave de prueba (siempre pasa) — solo apta para local |

## Endpoints

### Públicos (sin autenticación)

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/register` | Alta de una nueva tienda (tenant + usuario admin) |
| POST | `/api/auth/login` | Login de administrador |
| GET | `/api/public/resolve-domain` | Resuelve tenant por dominio personalizado |
| GET | `/api/public/{slug}` | Datos y branding de la tienda |
| GET | `/api/public/{slug}/categories` | Categorías activas |
| GET | `/api/public/{slug}/products` | Productos con búsqueda, filtros (categoría, disponibilidad, especificaciones) y paginación |
| GET | `/api/public/{slug}/products/{product}` | Ficha de producto (incluye relacionados y reseñas) |
| POST | `/api/public/{slug}/products/{product}/reviews` | Crear reseña — valida token de Turnstile y aplica rate limiting |
| POST | `/api/public/{slug}/orders` | Crear solicitud de pedido |
| GET | `/api/public/{slug}/pages` · `/pages/{page_slug}` | Páginas informativas |
| POST | `/api/public/{slug}/auth/register` · `/auth/login` | Cuenta de cliente final |

### Cliente autenticado (Bearer token, dentro de `/api/public/{slug}`)

| Método | Ruta | Descripción |
|---|---|---|
| POST·GET | `/auth/logout` · `/auth/me` | Sesión del cliente |
| GET | `/my-orders` | Historial de pedidos |
| GET | `/favorites` | Productos favoritos |
| POST | `/favorites/{product}` | Alternar favorito |

### Administración (Bearer token + `X-Tenant: {slug}`)

| Método | Ruta | Descripción |
|---|---|---|
| POST·GET | `/api/auth/logout` · `/api/auth/me` | Sesión |
| GET | `/api/dashboard/stats` | Métricas de la tienda |
| GET·PUT | `/api/tenant` | Configuración y branding |
| CRUD | `/api/products` | Productos |
| POST | `/api/products/reorder` | Reordenar (drag & drop) |
| POST | `/api/products/import` | Importación masiva por CSV |
| POST | `/api/products/bulk` | Acciones masivas |
| POST | `/api/products/{product}/duplicate` | Duplicar producto |
| CRUD | `/api/categories` (+ `POST /reorder`) | Categorías |
| CRUD | `/api/orders` | Pedidos |
| GET·PUT·DELETE | `/api/reviews` | Moderación de reseñas |
| CRUD | `/api/pages` | Páginas informativas |

### Flujo de pedidos y stock

Los pedidos tienen estados `pending → processing → attended / cancelled`. Al pasar a `attended` se **descuenta el stock** de los productos del pedido; al salir de `attended` (cancelación o reversión) se repone. Eliminar un pedido atendido también repone stock.

## Estructura

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php          # Registro de tiendas y sesión de admins
│   │   ├── TenantController.php        # Configuración y branding
│   │   ├── ProductController.php       # CRUD + reorder/import/bulk/duplicate
│   │   ├── CategoryController.php
│   │   ├── OrderController.php         # Estados de pedido y ajuste de stock
│   │   ├── ReviewController.php        # Moderación
│   │   ├── PageController.php          # Páginas informativas
│   │   ├── DashboardController.php     # Métricas
│   │   └── Public/
│   │       ├── PublicCatalogController.php    # Catálogo, reseñas, pedidos
│   │       ├── PublicAuthController.php       # Cuentas de cliente
│   │       ├── PublicFavoritesController.php
│   │       └── PublicOrdersController.php
│   └── Middleware/InitializeTenantByHeader.php
├── Models/            # Tenant, User, Category, Product, ProductImage,
│                      # Order, OrderItem, Review, Page
└── Services/ImageService.php   # Subida y optimización a WebP (URL o archivo)

database/migrations/   # tenants, users, categories, products, product_images,
                       # orders, order_items, reviews, pages, user_favorites, ...
routes/api.php         # Todas las rutas de la API
```

## Comandos útiles

```bash
php artisan test        # Suite de tests (PHPUnit)
vendor/bin/pint         # Formateo de código (Laravel Pint)
composer dev            # serve + queue + pail + vite en paralelo
```
