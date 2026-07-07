# Hardware Catalog SaaS

Plataforma **SaaS multi-tenant** para que tiendas de componentes de PC publiquen su propio catálogo en línea. Cada tienda (tenant) administra su negocio desde un panel privado y obtiene un **catálogo público personalizable** con carrito de compras y pedidos por WhatsApp.

El repositorio es un monorepo con dos aplicaciones:

| Carpeta | Stack | Rol |
|---|---|---|
| [`saas-hardware-api`](saas-hardware-api) | Laravel 13 + PHP 8.3 | API REST y multitenancy |
| [`saas-hardware-frontend`](saas-hardware-frontend) | React 19 + Vite + TypeScript | Panel de administración y catálogo público |

## Características

### Plataforma
- **Multi-tenant en una sola base de datos.** Cada registro pertenece a un `tenant`; las rutas privadas resuelven el tenant por el header `X-Tenant` (slug) y el catálogo público por el slug en la URL.
- **Dominios personalizados**: una tienda puede servirse desde su propio dominio (`GET /api/public/resolve-domain` resuelve el tenant por dominio y el frontend usa rutas sin prefijo de slug).
- **Registro de tiendas self-service** (`POST /api/auth/register`) con validación de slugs reservados.
- **Autenticación** con Laravel Sanctum (tokens Bearer), separada para administradores de tienda y clientes finales.

### Catálogo público (`/{slug}`)
- Búsqueda, filtros por categoría, disponibilidad y **especificaciones técnicas**, paginación y ficha de producto con **galería de imágenes**.
- **Carrito de compras** y creación de pedidos que se envían a la tienda por **WhatsApp**.
- **Comparador de productos** y **armador de PC** con verificación de compatibilidad (`/{slug}/builder`).
- **Cross-selling**: productos relacionados en la ficha de producto.
- **Reseñas y calificaciones** con protección anti-bot (Cloudflare Turnstile), badge de compra verificada y rate limiting.
- **Cuentas de cliente**: registro/login por tienda, favoritos e historial de pedidos.
- **Páginas informativas** por tienda (`/{slug}/p/{pagina}`).
- **SEO**: título, favicon y etiquetas meta/Open Graph dinámicas por tienda.

### Panel de administración (`/dashboard`)
- **Productos**: CRUD con imágenes (galería), especificaciones técnicas, SKU, precios de oferta, estado, duplicado, acciones masivas, **importación por CSV** y reordenamiento drag & drop.
- **Categorías**: CRUD y reordenamiento drag & drop.
- **Pedidos**: registro y gestión con flujo de estados (`pending → processing → attended / cancelled`), **descuento automático de stock** al atender y reposición al cancelar/revertir, y notificaciones por WhatsApp según estado.
- **Stock**: control de inventario con umbral de stock bajo y operaciones diarias.
- **Reseñas**: moderación (aprobar/eliminar).
- **Métricas**: resumen de estadísticas de la tienda (vistas, pedidos, etc.).
- **Personalización (branding/theme)**: color principal y de acento, modo claro/oscuro, textos e imagen del hero, título + favicon de la pestaña.
- **Imágenes**: logo, portada y favicon admiten **pegar URL o subir archivo**; producto/logo/banner se optimizan a WebP.

## Stack tecnológico

**Backend** — Laravel 13, PHP 8.3, Laravel Sanctum, [spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy), Intervention Image v4, Flysystem S3 (compatible con Cloudflare R2), UUIDs.

**Frontend** — React 19, Vite, TypeScript, React Router 7, TanStack Query, Zustand, Axios, lucide-react (iconos SVG), react-hot-toast.

## Requisitos

- PHP **8.3+** y Composer
- Node.js **18+** y npm
- Una base de datos (MySQL/MariaDB o SQLite)
- Opcional: almacenamiento compatible con S3 (Cloudflare R2) para imágenes en producción
- Opcional: claves de [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) para las reseñas (en local funcionan las claves de prueba por defecto)

## Puesta en marcha

### 1. API (Laravel)

```bash
cd saas-hardware-api
composer install
cp .env.example .env
php artisan key:generate
# Configura la conexión de base de datos en .env (DB_CONNECTION, DB_*)
php artisan migrate
php artisan storage:link   # para servir imágenes subidas en local
php artisan serve --port=8000
```

La API queda en `http://localhost:8000`.

> **Imágenes en local**: si no configuras R2/S3, el `ImageService` guarda en el disco `public`. Asegúrate de haber corrido `php artisan storage:link`.

### 2. Frontend (React + Vite)

```bash
cd saas-hardware-frontend
npm install
# La URL de la API se toma de VITE_API_URL (por defecto http://localhost:8000/api)
npm run dev
```

El frontend queda en `http://localhost:5173`.

- Panel de administración: `http://localhost:5173/login`
- Catálogo público de una tienda: `http://localhost:5173/{slug}`

Más detalle de configuración y estructura en los README de [`saas-hardware-api`](saas-hardware-api/README.md) y [`saas-hardware-frontend`](saas-hardware-frontend/README.md).

## Endpoints principales

**Públicos** (resuelven el tenant por `{slug}` en la URL):

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/register` | Registro de una nueva tienda |
| GET | `/api/public/resolve-domain` | Resuelve tenant por dominio personalizado |
| GET | `/api/public/{slug}` | Datos y branding de la tienda |
| GET | `/api/public/{slug}/categories` | Categorías activas |
| GET | `/api/public/{slug}/products` | Productos (búsqueda, filtros, paginación) |
| GET | `/api/public/{slug}/products/{product}` | Ficha de producto |
| POST | `/api/public/{slug}/products/{product}/reviews` | Crear reseña (Turnstile + rate limit) |
| POST | `/api/public/{slug}/orders` | Crear solicitud de pedido |
| GET | `/api/public/{slug}/pages` · `/pages/{page}` | Páginas informativas |
| POST | `/api/public/{slug}/auth/register` · `/auth/login` | Cuenta de cliente |

**Cliente autenticado** (Bearer token, dentro de `/api/public/{slug}`):

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/my-orders` | Historial de pedidos del cliente |
| GET·POST | `/favorites` · `/favorites/{product}` | Listar / marcar favoritos |
| POST·GET | `/auth/logout` · `/auth/me` | Sesión del cliente |

**Administración** (Bearer token + header `X-Tenant: {slug}`):

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/login` · `/api/auth/logout` · `/api/auth/me` | Sesión |
| GET | `/api/dashboard/stats` | Métricas de la tienda |
| GET·PUT | `/api/tenant` | Ver / actualizar configuración y branding |
| CRUD | `/api/products` (+ `reorder`, `import`, `bulk`, `{id}/duplicate`) | Gestión de productos |
| CRUD | `/api/categories` (+ `reorder`) | Gestión de categorías |
| CRUD | `/api/orders` | Gestión de pedidos y stock |
| GET·PUT·DELETE | `/api/reviews` | Moderación de reseñas |
| CRUD | `/api/pages` | Páginas informativas |

## Estructura del repositorio

```
.
├── saas-hardware-api/        # API Laravel
│   ├── app/Http/Controllers/Api/   # Auth, Tenant, Product, Category, Order,
│   │   │                           # Review, Page, Dashboard
│   │   └── Public/                 # Catálogo, auth de clientes, favoritos, pedidos
│   ├── app/Http/Middleware/        # InitializeTenantByHeader (X-Tenant)
│   ├── app/Services/ImageService   # Optimización y subida de imágenes
│   └── database/migrations/        # tenants, products, orders, reviews, pages, ...
└── saas-hardware-frontend/   # SPA React
    └── src/
        ├── pages/dashboard/        # Overview, Productos, Categorías, Pedidos,
        │                           # Reseñas, Páginas, Configuración
        ├── pages/public/           # Catálogo, detalle, armador de PC, páginas
        ├── components/             # UI reutilizable, carrito, cuenta de cliente
        ├── stores/                 # Zustand: auth, carrito, cliente, tenant
        ├── hooks/                  # useTenantBranding, useTenantTheme
        └── api/                    # Cliente Axios y endpoints
```
