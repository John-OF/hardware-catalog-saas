# Propuesta Técnica: Arquitectura del SaaS Catálogo de Componentes PC

> **Documento generado por:** Agente IA Consultor  
> **Basado en:** `propuesta_saas_catalogo.md`  
> **Última revisión:** 2026-06-12 — Stack actualizado (ver sección 0)  
> **Criterios de priorización:** Seguridad, costo operativo mínimo, compatibilidad con servidores externos y velocidad de MVP (en ese orden de peso).

---

## 0. Registro de Cambios y Justificación del Stack

### ¿Por qué se cambió el backend de FastAPI a Laravel?

Esta sección documenta la evolución de la decisión técnica para dejar trazabilidad de por qué el stack final difiere de la propuesta inicial.

#### Propuesta original (v1)
La primera versión recomendaba **FastAPI (Python) + PostgreSQL** desplegado en Railway o Render, con Redis como caché adicional. Era una propuesta sólida en términos de seguridad y rendimiento.

#### Contexto que cambió la decisión

Al incorporar la variable económica real del proyecto, el análisis reveló que el stack original tenía un **costo operativo incompatible con un proyecto de portafolio sin financiamiento**:

| Servicio (stack original) | Costo mensual estimado |
|---|---|
| Backend FastAPI en Railway/Render | $5 – $10 |
| Base de datos PostgreSQL gestionada | $5 – $7 |
| Redis (caché) | $3 – $5 |
| Almacenamiento de imágenes (R2) | $0 |
| Frontend en Vercel | $0 |
| **Total** | **~$13 – $22/mes** |

Para un desarrollador sin ingresos estables apostando a un proyecto que puede quedar solo en el portafolio, ese costo representa un riesgo financiero injustificado, especialmente cuando existen alternativas que **no sacrifican seguridad**.

> Los servicios con tier gratuito (Render free, Railway starter) tienen limitaciones críticas para demos de portafolio: **el servidor entra en modo sleep tras 15 minutos de inactividad**, lo que hace que la primera petición tarde 30–60 segundos. Eso destruye la primera impresión ante un reclutador o cliente potencial.

#### Por qué Laravel resuelve ambos problemas

**1. Costo: un solo servicio cubre todo**

El hosting PHP compartido (Hostinger, Namecheap, SiteGround) incluye en un solo plan de $2–3/mes:
- Servidor PHP siempre activo (sin sleep)
- Base de datos MySQL incluida
- SSL/TLS automático vía Let's Encrypt
- Sin servicios adicionales separados

**2. Seguridad: Laravel la activa por defecto, no por configuración**

Esta es la razón más importante. FastAPI es seguro *si* el desarrollador configura correctamente cada capa. Laravel es seguro *por diseño*: las protecciones más críticas están activadas desde la instalación sin requerir decisiones manuales:

| Amenaza | FastAPI | Laravel |
|---|---|---|
| SQL Injection | ✅ ORM con bindings (pero se puede bypassear con texto crudo) | ✅ Eloquent ORM + Query Builder siempre parametrizados |
| XSS | ⚠️ El desarrollador debe escapar manualmente en el frontend | ✅ Motor Blade escapa toda variable `{{ }}` por defecto |
| CSRF | ⚠️ Requiere configurar middleware manualmente | ✅ Activado globalmente desde instalación |
| Protección de asignación masiva | ⚠️ Pydantic ayuda pero requiere schemas explícitos | ✅ `$fillable` / `$guarded` obligatorio en modelos |
| Hashing de contraseñas | ⚠️ Requiere elegir e integrar passlib | ✅ `Hash::make()` usa bcrypt por defecto |
| Rate limiting | ⚠️ Librería externa (slowapi) | ✅ Middleware `throttle` nativo |
| Autenticación SPA segura | ⚠️ Construir desde cero con python-jose | ✅ Laravel Sanctum incluido, probado en millones de apps |

**3. Multitenancy: ecosistema maduro**

El paquete `spatie/laravel-multitenancy` (mantenido por Spatie, la empresa de paquetes más respetada del ecosistema PHP) implementa el patrón Shared Database con `tenant_id` de forma robusta y probada en producción.

#### ¿Qué se perdió con el cambio?

Ser honestos sobre los trade-offs:

| Trade-off | Impacto real |
|---|---|
| Se usa MySQL en lugar de PostgreSQL | Menor para este proyecto. MySQL soporta columnas JSON suficientes para el campo `specs`. Sin RLS nativo, pero el aislamiento se hace en capa de aplicación igual. |
| El backend pasa de Python a PHP | El desarrollador tiene experiencia en Python/FastAPI. Requiere aprender Laravel, pero la documentación de Laravel es considerada la mejor del ecosistema web. |
| Sin Redis para caché | Para el volumen de un MVP/portafolio, el caché de MySQL y el caché de aplicación de Laravel (file driver) son suficientes. Redis se puede agregar después si escala. |

#### Conclusión del cambio

Laravel no es una concesión económica que sacrifica calidad técnica. Es la elección correcta cuando el criterio "costo operativo" es una restricción real. Un proyecto que no puede mantenerse económicamente no llega a producción, y una demo con sleep de 60 segundos no impresiona a nadie. La seguridad se mantiene igual o mejor gracias a las protecciones integradas del framework.

---

## 1. Stack Tecnológico Final

### 1.1 Frontend — React + Vite + TypeScript ✅ (sin cambios)

| Tecnología | Justificación |
|---|---|
| **React 18 + Vite** | Compatible con la experiencia del desarrollador. Build rápido, HMR eficiente. |
| **TypeScript** | Tipado estático que previene bugs en tiempo de compilación. |
| **React Router v6** | Routing con protección de rutas privadas mediante wrappers de autenticación. |
| **Zustand** | Estado global liviano para `authStore` y `tenantStore`. |
| **TanStack Query v5** | Caché de datos del servidor, estados de carga/error, revalidación. |
| **Axios con interceptores** | Inyección automática del token en cada petición; manejo centralizado del 401. |

> **Seguridad Frontend:** Dado que el frontend (Vercel) y el backend (Hosting compartido) se despliegan en diferentes dominios, se descarta el modo cookie de Sanctum por restricciones de CORS. Se utilizará **API Token Authentication** vía cabecera `Authorization: Bearer`. El token **nunca** debe almacenarse en `localStorage`. Usar `sessionStorage` para mitigar ataques XSS persistentes y eliminar el token al cerrar la pestaña. Toda la lógica de autorización real ocurre en el backend.

---

### 1.2 Backend — Laravel 11 (PHP 8.3)

| Componente | Tecnología | Justificación |
|---|---|---|
| **Framework** | Laravel 11 | MVC maduro, seguridad por defecto, enorme ecosistema, documentación ejemplar. |
| **Autenticación API** | **Laravel Sanctum** | Autenticación basada en API Tokens (Bearer) idónea para dominios separados. Incluido en Laravel, sin dependencias extra. |
| **Multitenancy** | **spatie/laravel-multitenancy** | Paquete battle-tested. Identifica el tenant por request y aplica scopes automáticamente. |
| **ORM** | **Eloquent + Laravel Migrations** | ORM integrado con migraciones versionadas. Queries siempre parametrizadas. |
| **Imágenes** | **intervention/image-laravel** | Procesamiento, redimensionado y conversión a WebP directamente en PHP. Sin servicios externos. |
| **Validación** | **Form Requests de Laravel** | Validación de entrada declarativa con mensajes de error localizados. Previene inyecciones. |
| **Rate Limiting** | Middleware `throttle` nativo | `Route::middleware('throttle:5,1')` limita intentos de login a 5 por minuto. |
| **Almacenamiento** | **Laravel Filesystem + Cloudflare R2** | Abstracción que permite usar R2 (compatible con S3) configurando un driver. |
| **Servidor** | **PHP-FPM + Apache/Nginx** | Incluido en todo hosting compartido. Sin configuración especial. |

---

### 1.3 Base de Datos — MySQL 8.0+

MySQL se adopta en lugar de PostgreSQL por su inclusión universal en hosting compartido. Para este proyecto no representa una limitación significativa.

| Aspecto | Decisión | Razón |
|---|---|---|
| **Motor** | MySQL 8.0+ | Incluido en todo hosting cPanel/Plesk. JSON columns nativas desde v5.7. ACID completo con InnoDB. |
| **Estrategia multitenant** | **Shared Database / `tenant_id`** | Una sola BD, aislamiento por fila. `spatie/laravel-multitenancy` lo gestiona automáticamente. |
| **Índices críticos** | `tenant_id`, `category_id`, `is_active` | Toda query de catálogo filtra por `tenant_id`; el índice es obligatorio para evitar full scans. |
| **Campo specs** | `JSON` column | MySQL 8 soporta queries sobre JSON con `JSON_EXTRACT()`. Suficiente para filtros de hardware. |

> **Seguridad DB:** El usuario de la aplicación en producción debe tener solo permisos `SELECT`, `INSERT`, `UPDATE`, `DELETE`. Los permisos `CREATE TABLE`, `DROP`, `ALTER` son solo para el usuario de migraciones, ejecutado en deploy, nunca en runtime.

---

### 1.4 Almacenamiento de Imágenes — Cloudflare R2

| Proveedor | Costo | Razón de elección |
|---|---|---|
| **Cloudflare R2** ⭐ | Gratis hasta 10 GB/mes, **sin egress fees** | API 100% compatible con S3. El tráfico de salida es gratuito, a diferencia de AWS S3 que cobra por cada GB descargado. |
| AWS S3 | Pay-per-use + egress fees | Opción si el proyecto escala a enterprise. Migración sin cambios de código (misma API). |
| Almacenamiento local del servidor | $0 adicional | Solo como fallback temporal en desarrollo. No recomendado en producción (sin CDN, sin redundancia). |

#### Pipeline de procesamiento de imágenes

```
Imagen original (subida por usuario)
    → Controller de Laravel
    → intervention/image: redimensionar máx. 1200×1200 px
    → intervention/image: convertir a WebP (calidad 85%)
    → Generar thumbnail 400×400 px en WebP
    → Laravel Filesystem: subir ambas versiones a Cloudflare R2
    → Guardar image_url y thumbnail_url en tabla products
```

> WebP reduce el tamaño de archivo un 25–35% respecto a JPEG manteniendo calidad visual. Esto acelera la carga del catálogo público directamente.

---

## 2. Esquema de Base de Datos (MySQL)

> Los tipos se adaptan a MySQL 8. La lógica multitenant es idéntica al diseño original: `tenant_id` en cada tabla como discriminador.

### 2.1 Tabla: `tenants`

```sql
CREATE TABLE tenants (
    id              CHAR(36)     PRIMARY KEY,           -- UUID gestionado por Laravel
    slug            VARCHAR(80)  UNIQUE NOT NULL,       -- URL pública: /slug
    name            VARCHAR(200) NOT NULL,
    logo_url        TEXT         NULL,
    primary_color   VARCHAR(7)   NOT NULL DEFAULT '#2563EB',
    whatsapp_number VARCHAR(20)  NOT NULL,
    plan            VARCHAR(20)  NOT NULL DEFAULT 'free',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NULL,
    updated_at      TIMESTAMP    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2.2 Tabla: `users`

```sql
CREATE TABLE users (
    id              CHAR(36)     PRIMARY KEY,
    tenant_id       CHAR(36)     NOT NULL,
    email           VARCHAR(255) UNIQUE NOT NULL,
    password        VARCHAR(255) NOT NULL,              -- bcrypt vía Hash::make(), NUNCA texto plano
    full_name       VARCHAR(200) NULL,
    role            VARCHAR(20)  NOT NULL DEFAULT 'admin',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at   TIMESTAMP    NULL,
    created_at      TIMESTAMP    NULL,
    updated_at      TIMESTAMP    NULL,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_users_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> **Nota sobre unicidad de emails:** Para simplificar el portal de inicio de sesión global (`/login`) en el MVP sin requerir un paso previo de selección de tienda, el campo `email` se mantiene único a nivel global. Esto permite autenticar al usuario directamente y redirigirlo a su correspondiente tenant.

### 2.3 Tabla: `categories`

```sql
CREATE TABLE categories (
    id          CHAR(36)    PRIMARY KEY,
    tenant_id   CHAR(36)    NOT NULL,
    name        VARCHAR(100) NOT NULL,
    icon        VARCHAR(50)  NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NULL,
    updated_at  TIMESTAMP    NULL,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_category_tenant_name (tenant_id, name),
    INDEX idx_categories_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2.4 Tabla: `products`

```sql
CREATE TABLE products (
    id              CHAR(36)        PRIMARY KEY,
    tenant_id       CHAR(36)        NOT NULL,
    category_id     CHAR(36)        NULL,
    name            VARCHAR(300)    NOT NULL,
    brand           VARCHAR(100)    NULL,
    price           DECIMAL(12, 2)  NOT NULL,
    stock           INT             NOT NULL DEFAULT 0,
    description     TEXT            NULL,
    specs           JSON            NULL,               -- Atributos dinámicos: socket, TDP, etc.
    image_url       TEXT            NULL,               -- WebP principal (1200px)
    thumbnail_url   TEXT            NULL,               -- WebP thumbnail (400px)
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NULL,
    updated_at      TIMESTAMP       NULL,

    FOREIGN KEY (tenant_id)   REFERENCES tenants(id)    ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_products_tenant    (tenant_id),
    INDEX idx_products_category  (tenant_id, category_id),
    INDEX idx_products_stock     (tenant_id, stock),
    INDEX idx_products_active    (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> **Nota:** MySQL no soporta índices GIN sobre JSON como PostgreSQL. Los filtros sobre `specs` se resuelven con `JSON_EXTRACT()` o con columnas generadas virtuales sobre los campos más consultados (ej. socket, tipo de RAM).

### 2.5 Tabla: `personal_access_tokens` (generada por Sanctum)

Laravel Sanctum crea esta tabla automáticamente. Almacena los tokens de API con hash, fecha de expiración y capacidad de revocación. No requiere implementación manual.

```sql
-- Generada automáticamente por: php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
-- php artisan migrate
CREATE TABLE personal_access_tokens (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tokenable_type  VARCHAR(255) NOT NULL,
    tokenable_id    CHAR(36)     NOT NULL,
    name            VARCHAR(255) NOT NULL,
    token           VARCHAR(64)  UNIQUE NOT NULL,       -- SHA-256 del token, nunca el token raw
    abilities       TEXT         NULL,
    last_used_at    TIMESTAMP    NULL,
    expires_at      TIMESTAMP    NULL,
    created_at      TIMESTAMP    NULL,
    updated_at      TIMESTAMP    NULL,

    INDEX idx_tokens_tokenable (tokenable_type, tokenable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 3. Estrategia de Seguridad Multitenant

### 3.1 Aislamiento Automático con spatie/laravel-multitenancy

El paquete identifica el tenant en cada request (por subdominio, header o parámetro) y aplica un Global Scope a todos los modelos automáticamente. Un desarrollador no puede "olvidar" filtrar por `tenant_id`:

```php
// El TenantScope se aplica automáticamente a TODOS los modelos que usen BelongsToTenant
// No es necesario agregar ->where('tenant_id', ...) en cada query

class Product extends Model
{
    use HasUuids, BelongsToTenant; // <-- Este trait agrega el scope automáticamente y HasUuids maneja IDs únicos

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $casts = [
        'specs' => 'array', // JSON se convierte a array PHP automáticamente
        'price' => 'decimal:2',
    ];
}

// En el controller, esta query solo devuelve productos del tenant activo:
$products = Product::where('is_active', true)->get(); // tenant_id filtrado internamente
```

### 3.2 Autenticación con Laravel Sanctum

```php
// routes/api.php
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1'); // Máximo 5 intentos por minuto por IP

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    // ...
});

// AuthController.php
public function login(Request $request)
{
    $request->validate([
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    if (!Auth::attempt($request->only('email', 'password'))) {
        throw ValidationException::withMessages([
            'email' => ['Las credenciales proporcionadas son incorrectas.'],
        ]);
    }

    $user  = Auth::user();
    $token = $user->createToken('spa-token', ['*'], now()->addDays(7));

    return response()->json([
        'token'   => $token->plainTextToken,  // Solo se muestra UNA VEZ
        'user'    => $user,
        'tenant'  => $user->tenant,
    ]);
}
```

### 3.3 Validación de Entrada (Form Requests)

Laravel valida y sanitiza los datos antes de que lleguen al controller, evitando mass assignment y datos malformados:

```php
// app/Http/Requests/StoreProductRequest.php
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autenticación ya la valida el middleware auth:sanctum
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:300',
            'brand'       => 'nullable|string|max:100',
            'price'       => 'required|numeric|min:0',
            'stock'       => 'required|integer|min:0',
            'category_id' => 'nullable|uuid|exists:categories,id',
            'specs'       => 'nullable|array',
            'image'       => 'nullable|image|mimes:jpeg,png,webp|max:5120', // máx 5MB
        ];
    }
}
```

### 3.4 Headers de Seguridad HTTP

Configurar en el `.htaccess` del hosting compartido (Apache) o en Nginx:

```apache
# .htaccess (Apache - hosting compartido)
<IfModule mod_headers.c>
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(), microphone=()"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
</IfModule>
```

### 3.5 CORS (Cross-Origin Resource Sharing)

Dado que el frontend (Vercel) y el backend (hosting compartido) están en dominios diferentes, CORS debe configurarse explícitamente en `config/cors.php` de Laravel para permitir las peticiones del frontend. Sin esta configuración, el navegador bloqueará todas las llamadas a la API.

```php
// config/cors.php
'allowed_origins' => [
    env('FRONTEND_URL', 'http://localhost:5173'),
],
'supports_credentials' => false, // Bearer tokens, no cookies
```

> **Importante:** En producción, `FRONTEND_URL` debe apuntar al dominio exacto de Vercel (ej. `https://saas-hardware.vercel.app`). No usar `'*'` en `allowed_origins` — eso desactivaría la protección CORS.

---

## 4. Arquitectura y Compatibilidad con Servidores

### Diagrama de arquitectura (costo real: ~$2–3/mes)

```
┌─────────────────────────────────────────────┐
│  Hosting Compartido PHP ($2–3/mes)          │
│  ├── Laravel 11 (API REST)                  │
│  ├── MySQL 8 (BD incluida en el plan)       │
│  ├── SSL/TLS automático (Let's Encrypt)     │
│  └── PHP-FPM + Apache (configurado por host)│
└─────────────────────────────────────────────┘
                ↕ HTTPS / JSON
┌─────────────────────────────────────────────┐
│  Vercel (Frontend React + Vite)    GRATIS   │
└─────────────────────────────────────────────┘
                ↕
┌─────────────────────────────────────────────┐
│  Cloudflare R2 (Imágenes WebP)     GRATIS   │
│  └── Hasta 10 GB almacenamiento             │
│  └── Sin egress fees (tráfico gratis)       │
└─────────────────────────────────────────────┘
```

### Compatibilidad de proveedores

| Proveedor | Backend Laravel | BD | Imágenes | Costo aprox. | Notas |
|---|---|---|---|---|---|
| **Hostinger Shared** | ✅ PHP 8.3 | ✅ MySQL incluido | ❌ (usar R2) | **$2–3/mes** | ⭐ Recomendado para MVP |
| **Namecheap Shared** | ✅ PHP 8.3 | ✅ MySQL incluido | ❌ (usar R2) | **$2–4/mes** | Alternativa sólida |
| **Railway** | ✅ Dockerfile PHP | ✅ MySQL/PgSQL | ❌ (usar R2) | $5–10/mes | Si se quiere PaaS moderno |
| **Render** | ✅ Docker | ✅ PostgreSQL | ❌ (usar R2) | $7–15/mes | Free tier tiene sleep |
| **VPS (DigitalOcean/Hetzner)** | ✅ Total control | ✅ Local | ✅ Cualquier S3 | $4–6/mes | Máximo control; requiere sysadmin |

---

## 5. Roadmap de Desarrollo (4 Fases)

### 🔵 Fase 1 — Fundación Backend y Base de Datos (Semanas 1–3)

**Objetivo:** API REST funcional con autenticación multitenant.

- [ ] Crear proyecto Laravel: `composer create-project laravel/laravel saas-hardware`
- [ ] Instalar dependencias clave: `sanctum`, `spatie/laravel-multitenancy`, `intervention/image-laravel`, `league/flysystem-aws-s3-v3` (para R2)
- [ ] Configurar conexión MySQL en `.env`
- [ ] Crear y ejecutar migraciones: `tenants`, `users`, `categories`, `products`
- [ ] Configurar `spatie/laravel-multitenancy`: identificación de tenant por request
- [ ] Implementar modelos con `HasTenant` trait y relaciones Eloquent
- [ ] Configurar Laravel Sanctum para autenticación SPA
- [ ] Implementar endpoints de auth: `POST /api/register`, `POST /api/login`, `POST /api/logout`
- [ ] Rate limiting en rutas de autenticación (`throttle:5,1`)
- [ ] CRUD de productos con Form Requests y Resource Controllers
- [ ] CRUD de categorías
- [ ] Pipeline de imágenes: recibir → procesar con Intervention Image → subir a R2
- [ ] Configurar driver Cloudflare R2 en `config/filesystems.php`
- [ ] Tests de Feature para autenticación y aislamiento de tenants (`php artisan test`)

---

### 🟢 Fase 2 — Panel de Administración (Semanas 4–6)

**Objetivo:** Dashboard funcional para que los tenants gestionen su catálogo.

- [ ] Inicializar proyecto React + Vite + TypeScript (`npm create vite@latest`)
- [ ] Configurar Axios con interceptores: inyección de token, manejo de 401
- [ ] Implementar flujo de autenticación: Login → Token en memoria/sessionStorage → Logout
- [ ] Rutas protegidas con `<PrivateRoute>` que redirige al login si no hay sesión
- [ ] Layout base: Sidebar de navegación, Topbar con info del tenant, área de contenido
- [ ] Pantalla de configuración: nombre de tienda, logo, color de marca, número de WhatsApp
- [ ] Pantalla de listado de productos: tabla con paginación, búsqueda y filtro por categoría
- [ ] Formulario crear/editar producto: campos estándar + specs dinámicos + subida de imagen
- [ ] Vista previa de imagen antes de subir (FileReader API)
- [ ] Gestión de categorías: CRUD con drag & drop para reordenar
- [ ] Indicadores visuales: badge "Agotado", alerta de stock bajo (configurable)
- [ ] Estado global con Zustand: `authStore` (token, user), `tenantStore` (config de tienda)
- [ ] Notificaciones toast para feedback de acciones (react-hot-toast o sonner)

---

### 🟡 Fase 3 — Catálogo Público (Semanas 7–9)

**Objetivo:** Vitrina pública de alta velocidad para los clientes finales de cada tienda.

- [ ] Endpoints públicos sin autenticación: `GET /api/public/{slug}/products`
- [ ] Endpoint de detalle: `GET /api/public/{slug}/products/{id}`
- [ ] Caché de respuestas públicas con Laravel Cache (driver `file` en hosting compartido, TTL: 5 min)
- [ ] Ruta pública en el frontend: `/:slug` → carga configuración del tenant y catálogo
- [ ] Grid de productos responsivo con lazy loading de imágenes (`loading="lazy"`)
- [ ] Panel de filtros: por categoría y por specs (socket, tipo de RAM, certificación, etc.)
- [ ] Buscador en tiempo real con debounce de 300ms
- [ ] Ficha de producto: galería de imágenes, tabla de especificaciones técnicas, indicador de stock
- [ ] Botón **"Consultar por WhatsApp"** con URL dinámica:
  ```
  https://wa.me/{whatsapp}?text=Hola%2C+estoy+interesado+en+%5BNombre%5D+
  a+precio+%24%7Bprecio%7D+visto+en+su+cat%C3%A1logo+web.+%C2%BFTienen+disponibilidad%3F
  ```
- [ ] Personalización visual: colores de marca del tenant vía CSS custom properties (`--primary-color`)
- [ ] SEO: `<title>` y `<meta description>` dinámicos por tienda y por producto
- [ ] Open Graph tags para compartir productos en redes sociales
- [ ] Página de error 404 personalizada para slugs de tenant inexistentes
- [ ] Modo "Agotado": overlay visual en tarjeta, botón de WhatsApp desactivado o con texto alternativo

---

### 🔴 Fase 4 — Hardening, Pruebas y Despliegue (Semanas 10–12)

**Objetivo:** Sistema estable, seguro y desplegado en producción con proceso de deploy documentado.

- [ ] Configurar `.htaccess` con headers de seguridad HTTP (ver sección 3.4)
- [ ] Revisar lista OWASP Top 10 contra el código del proyecto
- [ ] Configurar variables de entorno en el panel del hosting (nunca en archivos `.env` versionados)
- [ ] Establecer proceso de deploy: `git pull` + `composer install --no-dev` + `php artisan migrate --force` + `php artisan config:cache`
- [ ] Backups automáticos de MySQL via cron del hosting (diario, retención 30 días)
- [ ] Monitoreo de errores: integrar **Sentry** (Laravel SDK + React SDK) — plan gratis disponible
- [ ] Configurar dominio y SSL en el panel del hosting
- [ ] Tests end-to-end de los flujos críticos: registro, login, creación de producto, vista pública, botón WhatsApp
- [ ] Optimización de imágenes en el catálogo público: `srcset` con thumbnail para móviles, imagen completa para desktop
- [ ] Prueba manual de la demo en dispositivos móviles (el catálogo público es el producto principal)
- [ ] README del proyecto: instrucciones de instalación local, estructura del proyecto, guía de deploy
- [ ] Documentación de la API con Laravel Scribe o comentarios en los controllers para el portafolio

---

## 6. Resumen de Decisiones Clave

| Decisión | Opción elegida | Razón principal |
|---|---|---|
| **Framework backend** | Laravel 11 | Seguridad por defecto, costo de hosting mínimo ($2–3/mes), ecosistema maduro |
| **Base de datos** | MySQL 8 | Incluida en todo hosting compartido; suficiente para los requisitos del proyecto |
| **Autenticación** | Laravel Sanctum (Bearer Tokens) | Diseñada para SPAs/APIs, compatible con dominios cruzados (Vercel + Hosting) |
| **Multitenancy** | spatie/laravel-multitenancy + `tenant_id` | Paquete probado en producción; aislamiento automático con scope global y verificación de pertenencia en middleware |
| **Imágenes** | Cloudflare R2 + WebP | Sin egress fees, API S3-compatible, gratis hasta 10 GB |
| **Caché** | Laravel Cache (file driver) | Disponible en hosting compartido sin servicios extra; Redis se puede agregar después |
| **Password hashing** | bcrypt vía `Hash::make()` | Activado por defecto en Laravel; resistente a ataques de fuerza bruta GPU |
| **Servidor** | PHP-FPM + Apache (hosting compartido) | Incluido en el plan base; sin configuración adicional; siempre activo |
| **Procesamiento imágenes** | intervention/image-laravel | Procesamiento en PHP sin workers ni colas externas para el MVP |

---

*Documento actualizado el 2026-06-12. El cambio de stack de FastAPI a Laravel se documenta en detalle en la sección 0.*
