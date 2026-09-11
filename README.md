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

**Para la tienda**: alta self-service con verificación de correo, catálogo con especificaciones
técnicas, imágenes optimizadas, importación por CSV, pedidos con estados y descuento automático de
stock, venta de mostrador, moderación de reseñas, lista de espera de productos agotados, páginas
informativas, métricas y personalización visual completa (colores, tipografías, portada, pie,
favicon).

**Para el comprador**: buscador con filtros por categoría, disponibilidad y specs reales del
catálogo; comparador de hasta tres productos; **armador de PC** que avisa de incompatibilidades;
carrito; cuenta con favoritos e historial; reseñas con moderación y anti-bot.

**Para el operador del SaaS**: panel propio —restringido por IP— para listar tiendas, suspenderlas,
cambiar su plan y rescatar la contraseña de un dueño. Los planes limitan cuánto puede crear cada
tienda (`config/plans.php`).

**Lo que todavía no existe** y conviene saber antes de nada: no hay pasarela de pago ni facturación
del SaaS, ni envíos, ni impuestos/comprobante, ni variantes de producto. El detalle completo —qué
hace cada función, qué **no** hace y dónde cojea— está en `docs/funcionalidades.md`.

---

## Requisitos

- PHP **8.3+** y Composer
- Node.js **18+** y npm
- Una base de datos (MySQL/MariaDB; los tests corren en SQLite en memoria)
- **Un worker de colas corriendo** (`php artisan queue:work`): los correos van por cola y sin él no
  sale ninguno
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

---

## Arquitectura multi-tenant

Todos los tenants comparten **una sola base de datos**; cada fila lleva `tenant_id`
(vía [spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy)).
El tenant activo se resuelve según el tipo de ruta:

- **Rutas privadas de administración** (`auth:sanctum` + `tenant` + `admin`): por el header
  `X-Tenant: {slug}` — `app/Http/Middleware/InitializeTenantByHeader.php`.
- **Rutas públicas** (`/api/public/{slug}/...`): por el slug de la URL — `InitializeTenantBySlug.php`.
- **Dominios personalizados**: `GET /api/public/resolve-domain` devuelve el tenant asociado al
  dominio; en ese caso el frontend usa rutas **sin** prefijo de slug.
- **Rutas de plataforma** (`/api/platform/...`): deliberadamente **sin** middleware de tenant. El
  operador está por encima de las tiendas y su usuario tiene `tenant_id` a null.

**El global scope de `BelongsToTenant` falla en cerrado**: sin tienda resuelta, una consulta no
devuelve nada en vez de devolverlo todo. Para mirar por encima de las tiendas hay que pedirlo
explícitamente con `withoutTenant()`. **`User` es la única excepción**, y el porqué está escrito en
el propio modelo (es el problema del huevo y la gallina: `auth:sanctum` resuelve al usuario antes de
que ningún middleware haya resuelto la tienda).

Hay **tres autenticaciones separadas**, todas con Sanctum (tokens Bearer):

1. **Administradores de tienda** — panel (header `X-Tenant` + Bearer). Token de 7 días, una sesión
   activa por usuario.
2. **Clientes finales** — cuentas por tienda dentro del catálogo público (favoritos e historial).
   Token de 30 días. El middleware `customer` exige que el token sea **de esa tienda**.
3. **Operador de la plataforma** (`superadmin`) — panel propio, restringido además por IP.

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
│   │   ├── DashboardController.php         # Métricas
│   │   ├── PlanController.php              # Plan, límites y consumo
│   │   ├── PlatformController.php          # Panel del operador
│   │   └── Public/
│   │       ├── PublicCatalogController.php # Catálogo, ficha, reseñas, pedidos, avisos
│   │       ├── PublicAuthController.php    # Cuentas de cliente
│   │       ├── PublicFavoritesController.php
│   │       └── PublicOrdersController.php
│   ├── Middleware/
│   │   ├── InitializeTenantByHeader.php    # Panel: X-Tenant
│   │   ├── InitializeTenantBySlug.php      # Público: slug de la URL
│   │   ├── EnsureAdmin.php · EnsureSuperAdmin.php
│   │   ├── EnsureTenantCustomer.php        # El token es de ESTA tienda
│   │   └── RestrictPlatformIp.php          # Lista de IPs del panel de plataforma
│   └── Requests/                           # StoreProductRequest, StoreCategoryRequest, ...
├── Models/            # Tenant, User, Category, Product, ProductImage, Order,
│                      # OrderItem, Review, StockNotification, Page
│   └── Concerns/BelongsToTenant.php        # Global scope que falla en cerrado
├── Notifications/     # VerifyEmail, ResetPassword, NewOrder, OrderPlaced,
│                      # OrderStatusChanged, BackInStock  (todas ShouldQueue)
├── Services/
│   ├── ImageService.php    # Subida y optimización a WebP
│   ├── OrderPricing.php    # Precios y total calculados en el servidor
│   └── ViewCounter.php     # Visitas acumuladas en caché
└── Support/
    ├── PlanGate.php        # Aplica los límites del plan
    ├── Money.php           # Formato de moneda por tienda
    └── StoreUrl.php        # URL pública de una tienda, para los correos

config/plans.php       # La matriz de planes y límites
routes/api.php         # Toda la API
routes/web.php         # Vistas previas Open Graph para crawlers + redirect al SPA
tests/Feature/         # 41 archivos, 320 tests (+1 en tests/Unit)
```

### Endpoints

**Públicos, sin autenticación:**

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/register` | Alta de tienda (tenant + admin) · 5/min |
| POST | `/api/auth/login` | Login de administrador · 5/min |
| POST | `/api/auth/forgot-password` · `/reset-password` | Recuperación de contraseña · 5/min |
| GET | `/api/auth/verify-email/{id}/{hash}` | Verificar correo (URL firmada) → redirige al SPA |
| GET | `/api/public/resolve-domain` | Resuelve tenant por dominio propio |

**Catálogo público** (prefijo `/api/public/{slug}`, throttle de grupo 120/min, tenant por slug):

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/` | Datos y branding de la tienda (solo columnas públicas) |
| GET | `/categories` | Categorías activas (con `component_type`) |
| GET | `/products` | Búsqueda, filtros (`category_id`, `component_type`, `specs`, `in_stock`), orden y paginación |
| GET | `/facets` | Valores de spec reales del catálogo, para los filtros |
| GET | `/products/{product}` | Ficha: galería, reseñas aprobadas, relacionados |
| POST | `/products/{product}/reviews` | Crear reseña (Turnstile) · 10/min |
| POST | `/products/{product}/notify-me` | "Avísame cuando llegue" · 10/min |
| POST | `/orders` | Crear pedido · 10/min |
| GET | `/pages` · `/pages/{page_slug}` | Páginas informativas |
| POST | `/auth/register` · `/auth/login` | Cuenta de cliente · 5/min |

**Cliente autenticado** (Bearer + middleware `customer`, dentro de `/api/public/{slug}`):

| Método | Ruta | Descripción |
|---|---|---|
| POST·GET | `/auth/logout` · `/auth/me` | Sesión del cliente |
| GET | `/my-orders` | Historial de pedidos |
| GET·POST | `/favorites` · `/favorites/{product}` | Listar y alternar favoritos |

**Administración** (Bearer + `X-Tenant: {slug}` + rol admin):

| Método | Ruta | Descripción |
|---|---|---|
| POST·GET | `/api/auth/logout` · `/api/auth/me` | Sesión |
| POST | `/api/auth/email/resend` | Reenviar verificación · 3/min |
| GET | `/api/dashboard/stats` | Métricas |
| GET | `/api/plan` | Plan, límites y consumo |
| GET·PUT | `/api/tenant` | Configuración y branding |
| CRUD | `/api/products` (+ `POST /reorder`, `/import`, `/bulk`, `/{id}/duplicate`) | Productos |
| CRUD | `/api/categories` (+ `POST /reorder`) | Categorías |
| CRUD | `/api/orders` | Pedidos y venta de mostrador |
| GET·PUT·DELETE | `/api/reviews` | Moderación |
| GET·PUT·DELETE | `/api/stock-notifications` | Lista de espera |
| CRUD | `/api/pages` | Páginas informativas |
| GET·POST·PUT·DELETE | `/api/users` (+ `POST /{id}/resend-invitation`) | Equipo de la tienda |

**Plataforma** (Bearer + rol `superadmin` + lista de IPs):

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/platform/login` · `/logout` | Sesión del operador |
| GET | `/api/platform/me` · `/tenants` | Perfil y listado de tiendas |
| PUT | `/api/platform/tenants/{tenant}` | Suspender/reactivar y cambiar plan |
| POST | `/api/platform/tenants/{tenant}/password-reset` | Mandar recuperación al dueño |

**Rutas web (no API)** — `routes/web.php`: `/{slug}`, `/{slug}/product/{id}`, `/{slug}/p/{pageSlug}`
y `/{slug}/builder` devuelven una vista Open Graph si quien pide es un crawler conocido, y
redirigen a `FRONTEND_URL` si es una persona.

### Variables de entorno relevantes

| Variable | Uso |
|---|---|
| `DB_CONNECTION`, `DB_*` | Base de datos (MySQL en producción; SQLite en los tests) |
| `FRONTEND_URL` | Base del SPA. De ahí cuelgan los enlaces de los correos y los redirects |
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
php artisan test        # 321 tests (PHPUnit, SQLite en memoria)
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
| `/{slug}` · `/` | Catálogo (`/` resuelve la tienda por el dominio) |
| `/{slug}/product/:id` · `/product/:id` | Ficha de producto |
| `/{slug}/builder` · `/builder` | Armador de PC |
| `/{slug}/p/:pageSlug` · `/p/:pageSlug` | Página informativa |

**Cuenta y panel:**

| Ruta | Página |
|---|---|
| `/login` · `/register` | Acceso y alta de tienda |
| `/forgot-password` · `/reset-password` | Recuperación de contraseña |
| `/dashboard` | Resumen con métricas |
| `/dashboard/products` · `/categories` · `/orders` · `/pages` · `/reviews` · `/waitlist` | Gestión |
| `/dashboard/settings` | Branding, tema, portada, dominio, favicon |
| `/platform/login` · `/platform` | Panel del operador del SaaS |
| `*` | 404 explícito |

**Carga diferida**: todo va con `lazy()` **menos el catálogo**, que se queda estático a propósito por
ser la ruta de entrada de casi todo el tráfico.

### Estructura

```
src/
├── api/            # Cliente Axios (interceptores) y funciones por recurso
├── router/         # Rutas, PrivateRoute, Suspense y errorElement globales
├── pages/
│   ├── auth/       # Login, RegisterStore, ForgotPassword, ResetPassword
│   ├── dashboard/  # Overview, Products, Categories, Orders, Pages, Reviews,
│   │               # Waitlist, Settings (layout en DashboardPage)
│   ├── platform/   # PlatformLogin, Platform
│   └── public/     # Catalog, ProductDetail, PcBuilder, PageDetail
├── components/
│   ├── dashboard/  # NewOrderModal (venta de mostrador), VerifyEmailBanner
│   ├── public/     # CartDrawer, CustomerAccountModal, StoreHeader, StoreFooter,
│   │               # AnnouncementBar
│   └── ui/         # CategoryIcon, ImageSourceField, ErrorBoundary, fallbacks
├── stores/         # Zustand: authStore (admin), customerAuthStore (cliente),
│                   # platformAuthStore, cartStore (por tienda), tenantStore
├── hooks/          # useTenantBranding (título, favicon, meta/OG), useTenantTheme
├── utils/          # money, theme, themePresets, neutrals, shape, fonts, hero,
│                   # branding, phone, sanitizeHtml, componentTypes
└── types/          # Tipos compartidos de la API
```

### Claves de diseño

- **Estilos: cada componente tiene su `.css` al lado.** El de una **página** va acotado con
  `:where(.page-x)` y esa clase se pone en la raíz —y también en los `return` tempranos de carga y
  error—; el de un **componente** es global. `index.css` se importa **el primero** en `main.tsx` a
  propósito: si no, las hojas de página pierden los empates de especificidad contra el sistema de
  diseño.
- **Ojo con la especificidad**: una regla `:where(.page-x) .clase-de-componente` **empata** con el
  CSS del componente y el desempate lo decide el orden de carga de los chunks. Ese tipo de regla va
  en la hoja del componente.
- El **branding por tienda** se aplica en tiempo de ejecución (`useTenantBranding`, `useTenantTheme`)
  a partir de lo que devuelve la API: variables CSS, modo claro/oscuro, título y favicon.
- **Los filtros del catálogo viven en la URL**, no en estado local: un enlace compartido reproduce
  lo que el remitente estaba viendo.
- El **carrito** (`cartStore`) se persiste en `localStorage` y es por tienda.
- La sesión de cliente (`customerAuthStore`) es independiente de la de admin (`authStore`).

### Variables de entorno y scripts

| Variable | Uso | Por defecto |
|---|---|---|
| `VITE_API_URL` | URL base de la API | `http://localhost:8000/api` |
| `VITE_TURNSTILE_SITEKEY` | Site key del widget Turnstile | sin ella, el formulario de reseñas queda deshabilitado |

```bash
npm run dev       # Desarrollo con HMR (http://localhost:5173)
npm run build     # tsc -b + build de producción en dist/
npm run preview   # Sirve el build
npm run lint      # ESLint
```

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
