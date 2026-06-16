# Hardware Catalog SaaS

Plataforma **SaaS multi-tenant** para que tiendas de componentes de PC publiquen su propio catálogo en línea. Cada tienda (tenant) administra sus productos y categorías desde un panel privado y obtiene un **catálogo público personalizable** con consultas directas por WhatsApp.

El repositorio es un monorepo con dos aplicaciones:

| Carpeta | Stack | Rol |
|---|---|---|
| [`saas-hardware-api`](saas-hardware-api) | Laravel 13 + PHP 8.3 | API REST y multitenancy |
| [`saas-hardware-frontend`](saas-hardware-frontend) | React 19 + Vite + TypeScript | Panel de administración y catálogo público |

## Características

- **Multi-tenant en una sola base de datos.** Cada registro pertenece a un `tenant`; las rutas privadas resuelven el tenant por el header `X-Tenant` (slug) y el catálogo público por el slug en la URL.
- **Panel de administración**: gestión de productos (con imágenes y especificaciones técnicas) y categorías.
- **Catálogo público por tienda** (`/{slug}`): búsqueda, filtros por categoría y disponibilidad, ficha de producto y botón de contacto por WhatsApp.
- **Personalización por tenant (branding/theme)**: color principal y de acento, modo claro/oscuro, textos e imagen del hero, y título + favicon de la pestaña del navegador.
- **Imágenes**: logo, portada y favicon admiten **pegar URL o subir archivo**. Las imágenes de producto/logo/banner se optimizan a WebP; el favicon se conserva en su formato original.
- **Autenticación** con Laravel Sanctum (tokens).

## Stack tecnológico

**Backend** — Laravel 13, PHP 8.3, Laravel Sanctum, [spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy), Intervention Image v4, Flysystem S3 (compatible con Cloudflare R2), UUIDs.

**Frontend** — React 19, Vite, TypeScript, React Router, TanStack Query, Zustand, Axios, lucide-react (iconos SVG), react-hot-toast.

## Requisitos

- PHP **8.3+** y Composer
- Node.js **18+** y npm
- Una base de datos (MySQL/MariaDB o SQLite)
- Opcional: almacenamiento compatible con S3 (Cloudflare R2) para imágenes en producción

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

## Endpoints principales

**Públicos** (resuelven el tenant por `{slug}` en la URL):

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/public/{slug}` | Datos y branding de la tienda |
| GET | `/api/public/{slug}/categories` | Categorías activas |
| GET | `/api/public/{slug}/products` | Productos (búsqueda, filtros, paginación) |
| GET | `/api/public/{slug}/products/{product}` | Ficha de producto |

**Autenticados** (Bearer token + header `X-Tenant: {slug}`):

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/login` · `/api/auth/logout` · `/api/auth/me` | Sesión |
| GET·PUT | `/api/tenant` | Ver / actualizar configuración y branding de la tienda |
| CRUD | `/api/products` | Gestión de productos |
| CRUD | `/api/categories` | Gestión de categorías |

## Estructura del repositorio

```
.
├── saas-hardware-api/        # API Laravel
│   ├── app/Http/Controllers/Api/   # Auth, Tenant, Product, Category, Public
│   ├── app/Http/Middleware/        # InitializeTenantByHeader (X-Tenant)
│   ├── app/Services/ImageService   # Optimización y subida de imágenes
│   └── database/migrations/        # tenants, categories, products, ...
└── saas-hardware-frontend/   # SPA React
    └── src/
        ├── pages/dashboard/        # Productos, Categorías, Configuración
        ├── pages/public/           # Catálogo y detalle de producto
        ├── components/ui/          # Componentes reutilizables
        ├── hooks/                  # useTenantBranding (título + favicon)
        └── api/                    # Cliente Axios y endpoints
```
