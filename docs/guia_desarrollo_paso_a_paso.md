# Guía de Desarrollo Paso a Paso — SaaS Catálogo de Hardware

> **Cómo usar este documento:**  
> Cada paso está numerado globalmente. Al terminar una sesión, anota en qué paso quedaste.  
> Al retomar, simplemente di: *"Sigamos con el paso N"* y el contexto completo está aquí.  
> Cada paso tiene un ✅ **Checkpoint** al final para verificar que todo salió bien antes de avanzar.

---

## ESTADO ACTUAL

```
Paso actual:  [ 10 ]
Último completado: [ 9 ]
Fecha última sesión: 2026-06-12
Notas: Fundación base completada (Base de datos, Migraciones, Modelos, Middleware de multitenancy)
```

> **Actualiza este bloque al terminar cada sesión.**

---

## FASE A — Entorno Local y Proyecto Base

---

### Paso 1 — Verificar herramientas instaladas

Antes de crear nada, confirmar que el entorno local tiene todo lo necesario.

**Ejecutar en terminal:**
```bash
php --version        # Necesario: 8.2 o superior
composer --version   # Necesario: 2.x
node --version       # Necesario: 18 o superior
npm --version        # Necesario: 9 o superior
git --version        # Necesario: cualquier versión reciente
```

**Si falta algo:**
| Herramienta | Instalador |
|---|---|
| PHP 8.3 | https://www.php.net/downloads o `winget install PHP.PHP.8.3` (Windows) |
| Composer | https://getcomposer.org/download/ |
| Node.js 20 LTS | https://nodejs.org/en/download |
| Git | https://git-scm.com/downloads |

✅ **Checkpoint:** Los 4 comandos devuelven versiones sin errores.

---

### Paso 2 — Crear el proyecto Laravel

Ejecutar en la carpeta raíz del workspace (`C:\Proyectos\`):

```bash
composer create-project laravel/laravel saas-hardware-api
cd saas-hardware-api
```

Verificar que la estructura creada incluye:
```
saas-hardware-api/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/
├── resources/
├── routes/
├── storage/
├── tests/
├── .env
├── artisan
└── composer.json
```

✅ **Checkpoint:** Ejecutar `php artisan --version` dentro de la carpeta. Debe mostrar `Laravel Framework 11.x.x`.

---

### Paso 3 — Inicializar repositorio Git

Dentro de `saas-hardware-api/`:

```bash
git init
git add .
git commit -m "chore: inicializar proyecto Laravel 11"
```

Crear repositorio en GitHub (sin README, sin .gitignore — ya los tiene Laravel) y conectarlo:

```bash
git remote add origin https://github.com/TU_USUARIO/saas-hardware-api.git
git branch -M main
git push -u origin main
```

✅ **Checkpoint:** El repositorio aparece en GitHub con el commit inicial.

---

### Paso 4 — Configurar el archivo `.env` base

Abrir `.env` en la raíz del proyecto. Ajustar estos valores (los demás se dejan por defecto por ahora):

```env
APP_NAME="SaaS Hardware Catalog"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=saas_hardware
DB_USERNAME=root
DB_PASSWORD=tu_password_aqui

FILESYSTEM_DISK=local
```

> **Nota:** Las credenciales de Cloudflare R2 se agregan en el Paso 18. Por ahora las imágenes se guardan localmente.

✅ **Checkpoint:** El archivo `.env` está guardado. No commitear `.env` — ya está en `.gitignore` de Laravel.

---

### Paso 5 — Crear la base de datos MySQL

Abrir el cliente de MySQL preferido (TablePlus, DBeaver, MySQL Workbench, o terminal) y ejecutar:

```sql
CREATE DATABASE saas_hardware
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

Luego verificar la conexión desde Laravel:

```bash
php artisan db:show
```

✅ **Checkpoint:** El comando muestra información de la base de datos sin errores de conexión.

---

### Paso 6 — Instalar dependencias PHP del proyecto

Dentro de `saas-hardware-api/`, instalar todos los paquetes necesarios de una vez:

```bash
# Autenticación SPA
composer require laravel/sanctum

# Multitenancy
composer require spatie/laravel-multitenancy

# Procesamiento de imágenes
composer require intervention/image-laravel

# Storage en S3/R2 (instalado ahora, configurado en Paso 18)
composer require league/flysystem-aws-s3-v3

# IDs únicos tipo UUID
composer require ramsey/uuid
```

Publicar los archivos de configuración de Sanctum y Multitenancy:

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan vendor:publish --provider="Spatie\Multitenancy\MultitenancyServiceProvider"
php artisan vendor:publish --provider="Intervention\Image\Providers\LaravelServiceProvider"
```

✅ **Checkpoint:** Ningún error de Composer. Los archivos `config/sanctum.php`, `config/multitenancy.php` e `config/image.php` existen.

**Configurar CORS para permitir peticiones desde el frontend:**

Abrir `config/cors.php` y ajustar:

```php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),  // Vite dev
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false, // false porque usamos Bearer tokens, no cookies
];
```

Agregar al `.env`:
```env
FRONTEND_URL=http://localhost:5173
```

> **En producción**, cambiar `FRONTEND_URL` al dominio de Vercel (ej. `https://saas-hardware.vercel.app`). Sin esta configuración, todas las peticiones del frontend fallarán con error de CORS.

✅ **Checkpoint:** CORS configurado. Verificar que `config/cors.php` tiene el origin correcto.

---

## FASE B — Base de Datos y Modelos

---

### Paso 7 — Crear migraciones

Crear las migraciones en orden (el orden importa por las foreign keys):

```bash
php artisan make:migration create_tenants_table
php artisan make:migration add_tenant_id_to_users_table
php artisan make:migration create_categories_table
php artisan make:migration create_products_table
```

**Abrir y completar cada archivo en `database/migrations/`:**

**`create_tenants_table.php`:**
```php
public function up(): void
{
    Schema::create('tenants', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('slug', 80)->unique();
        $table->string('name', 200);
        $table->text('logo_url')->nullable();
        $table->string('primary_color', 7)->default('#2563EB');
        $table->string('whatsapp_number', 20);
        $table->string('plan', 20)->default('free');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}
```

**`add_tenant_id_to_users_table.php`:**
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->uuid('tenant_id')->after('id')->nullable();
        $table->string('role', 20)->default('admin')->after('email');
        $table->boolean('is_active')->default(true)->after('role');
        $table->timestamp('last_login_at')->nullable()->after('is_active');

        $table->foreign('tenant_id')
              ->references('id')
              ->on('tenants')
              ->onDelete('cascade');

        $table->index('tenant_id', 'idx_users_tenant');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropForeign(['tenant_id']);
        $table->dropColumn(['tenant_id', 'role', 'is_active', 'last_login_at']);
    });
}
```

**`create_categories_table.php`:**
```php
public function up(): void
{
    Schema::create('categories', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('name', 100);
        $table->string('icon', 50)->nullable();
        $table->smallInteger('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        $table->foreign('tenant_id')
              ->references('id')
              ->on('tenants')
              ->onDelete('cascade');

        $table->unique(['tenant_id', 'name'], 'uq_category_tenant_name');
        $table->index('tenant_id', 'idx_categories_tenant');
    });
}
```

**`create_products_table.php`:**
```php
public function up(): void
{
    Schema::create('products', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->uuid('category_id')->nullable();
        $table->string('name', 300);
        $table->string('brand', 100)->nullable();
        $table->decimal('price', 12, 2);
        $table->integer('stock')->default(0);
        $table->text('description')->nullable();
        $table->json('specs')->nullable();
        $table->text('image_url')->nullable();
        $table->text('thumbnail_url')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        $table->foreign('tenant_id')
              ->references('id')
              ->on('tenants')
              ->onDelete('cascade');

        $table->foreign('category_id')
              ->references('id')
              ->on('categories')
              ->onDelete('set null');

        $table->index('tenant_id', 'idx_products_tenant');
        $table->index(['tenant_id', 'category_id'], 'idx_products_category');
        $table->index(['tenant_id', 'stock'], 'idx_products_stock');
        $table->index(['tenant_id', 'is_active'], 'idx_products_active');
    });
}
```

Ejecutar todas las migraciones:

```bash
php artisan migrate
```

✅ **Checkpoint:** El comando termina sin errores. Las 7 tablas existen en la BD (`tenants`, `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `categories`, `products`, `personal_access_tokens`).

---

### Paso 8 — Crear los Modelos

```bash
php artisan make:model Tenant
php artisan make:model Category
php artisan make:model Product
```

**`app/Models/Tenant.php`:**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantModel;

class Tenant extends Model
{
    use HasUuids, UsesTenantModel;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'slug', 'name', 'logo_url', 'primary_color',
        'whatsapp_number', 'plan', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
```

**`app/Models/User.php`** — Modificar el existente, agregar:
```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Multitenancy\Models\Concerns\BelongsToTenant;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids, BelongsToTenant;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'name', 'email', 'password', 'role', 'is_active',
    ];

    // tenant_id se asigna explícitamente en el controller, nunca desde input del usuario
    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'is_active'         => 'boolean',
        'password'          => 'hashed',    // bcrypt automático en Laravel 10+
    ];
}
```

**`app/Models/Category.php`:**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\BelongsToTenant;

class Category extends Model
{
    use HasUuids, BelongsToTenant;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = ['name', 'icon', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted()
    {
        // Incrementar versión de caché al modificar categorías para invalidar la caché pública
        static::saved(function ($category) {
            $tenant = $category->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });

        static::deleted(function ($category) {
            $tenant = $category->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
```

**`app/Models/Product.php`:**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\BelongsToTenant;

class Product extends Model
{
    use HasUuids, BelongsToTenant;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'category_id', 'name', 'brand', 'price',
        'stock', 'description', 'specs',
        'image_url', 'thumbnail_url', 'is_active',
    ];

    protected $casts = [
        'specs'      => 'array',
        'price'      => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    protected static function booted()
    {
        // Incrementar versión de caché al modificar productos para invalidar la caché pública
        static::saved(function ($product) {
            $tenant = $product->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });

        static::deleted(function ($product) {
            $tenant = $product->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // Accessor útil para el frontend
    public function getIsAvailableAttribute(): bool
    {
        return $this->stock > 0;
    }
}
```

✅ **Checkpoint:** Ejecutar `php artisan tinker` y luego `Tenant::count()`. Debe devolver `0` sin errores.

---

### Paso 9 — Configurar Multitenancy (spatie)

Abrir `config/multitenancy.php` y ajustar:

```php
'tenant_model' => \App\Models\Tenant::class,

'current_tenant_container_key' => 'currentTenant',

'tenant_finder' => \Spatie\Multitenancy\TenantFinder\DomainTenantFinder::class,
// Por ahora usaremos header/request; cambiar si se usan subdominios en producción
```

Crear la clase que identifica el tenant en cada request. Crear archivo `app/Http/Middleware/InitializeTenantByHeader.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class InitializeTenantByHeader
{
    public function handle(Request $request, Closure $next): mixed
    {
        // El frontend envía el slug del tenant en el header X-Tenant
        $slug = $request->header('X-Tenant');

        if (!$slug) {
            return response()->json(['message' => 'Tenant no especificado.'], 400);
        }

        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tienda no encontrada o inactiva.'], 404);
        }

        // Prevenir vulnerabilidad BOLA: Validar pertenencia del usuario autenticado al tenant
        if (\Illuminate\Support\Facades\Auth::check()) {
            $user = \Illuminate\Support\Facades\Auth::user();
            if ($user->tenant_id !== $tenant->id) {
                return response()->json(['message' => 'Acceso no autorizado para este tenant.'], 403);
            }
        }

        $tenant->makeCurrent();

        return $next($request);
    }
}
```

Registrar el middleware en `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'tenant' => \App\Http\Middleware\InitializeTenantByHeader::class,
    ]);
})
```

✅ **Checkpoint:** El archivo de middleware existe y está registrado. No hay errores de sintaxis (`php artisan route:list` no falla).

---

## FASE C — Autenticación

---

### Paso 10 — Crear el AuthController

```bash
php artisan make:controller Api/AuthController
```

**`app/Http/Controllers/Api/AuthController.php`:**
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Registrar una nueva tienda (tenant) + usuario administrador
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_name'     => 'required|string|max:200',
            'slug'           => [
                'required',
                'string',
                'max:80',
                'unique:tenants,slug',
                'regex:/^[a-z0-9\-]+$/',
                function ($attribute, $value, $fail) {
                    $reserved = ['admin', 'dashboard', 'login', 'register', 'api', 'public', 'settings', 'config', 'home', 'main'];
                    if (in_array(strtolower($value), $reserved)) {
                        $fail('El slug elegido está reservado por la plataforma.');
                    }
                }
            ],
            'whatsapp'       => 'required|string|max:20',
            'name'           => 'required|string|max:200',
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:8|confirmed',
        ]);

        // No pasar 'id' manualmente — HasUuids + newUniqueId() genera UUID v7 automáticamente
        $tenant = Tenant::create([
            'slug'           => $data['slug'],
            'name'           => $data['store_name'],
            'whatsapp_number'=> $data['whatsapp'],
        ]);

        // tenant_id se asigna explícitamente (está en $guarded, no en $fillable)
        // password se pasa en texto plano — el cast 'hashed' lo hashea automáticamente
        $user = new User([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => $data['password'],
            'role'      => 'admin',
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        $token = $user->createToken('spa-token', ['*'], now()->addDays(7));

        return response()->json([
            'token'  => $token->plainTextToken,
            'user'   => $user,
            'tenant' => $tenant,
        ], 201);
    }

    /**
     * Login de usuario existente
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $user = Auth::user();
        $user->update(['last_login_at' => now()]);

        // Revocar tokens anteriores (una sesión activa por usuario)
        $user->tokens()->delete();

        $token = $user->createToken('spa-token', ['*'], now()->addDays(7));

        return response()->json([
            'token'  => $token->plainTextToken,
            'user'   => $user,
            'tenant' => $user->tenant,
        ]);
    }

    /**
     * Cerrar sesión
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Devolver datos del usuario autenticado
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user'   => $request->user(),
            'tenant' => $request->user()->tenant,
        ]);
    }
}
```

✅ **Checkpoint:** El archivo existe sin errores de sintaxis.

---

### Paso 11 — Definir las rutas de la API

Abrir `routes/api.php` y reemplazar su contenido:

```php
<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TenantController;
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
    Route::get('/products',  [PublicCatalogController::class, 'products']);
    Route::get('/products/{product}', [PublicCatalogController::class, 'product']);
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
});
```

✅ **Checkpoint:** `php artisan route:list` muestra todas las rutas sin errores.

---

### Paso 12 — Probar el registro con un cliente HTTP

Iniciar el servidor de desarrollo:

```bash
php artisan serve
# Queda escuchando en http://localhost:8000
```

En Postman, Insomnia o Thunder Client (extensión VS Code), hacer:

**POST** `http://localhost:8000/api/auth/register`  
Headers: `Content-Type: application/json`  
Body:
```json
{
    "store_name": "PC Parts Demo",
    "slug": "pc-parts-demo",
    "whatsapp": "573001234567",
    "name": "Admin Demo",
    "email": "admin@demo.com",
    "password": "password123",
    "password_confirmation": "password123"
}
```

Respuesta esperada `201`:
```json
{
    "token": "1|xxxxxxxxxxxxxxxx",
    "user": { ... },
    "tenant": { "slug": "pc-parts-demo", ... }
}
```

✅ **Checkpoint:** El endpoint devuelve `201` con un token. El tenant y el usuario aparecen en las tablas de la BD.

---

## FASE D — CRUD de Categorías y Productos

---

### Paso 13 — Crear Form Requests de validación

```bash
php artisan make:request StoreCategoryRequest
php artisan make:request StoreProductRequest
php artisan make:request UpdateProductRequest
```

**`app/Http/Requests/StoreCategoryRequest.php`:**
```php
public function authorize(): bool { return true; }

public function rules(): array
{
    return [
        'name'       => 'required|string|max:100',
        'icon'       => 'nullable|string|max:50',
        'sort_order' => 'nullable|integer|min:0',
        'is_active'  => 'nullable|boolean',
    ];
}
```

**`app/Http/Requests/StoreProductRequest.php`:**
```php
public function authorize(): bool { return true; }

public function rules(): array
{
    return [
        'name'        => 'required|string|max:300',
        'brand'       => 'nullable|string|max:100',
        'price'       => 'required|numeric|min:0',
        'stock'       => 'required|integer|min:0',
        'category_id' => 'nullable|uuid|exists:categories,id',
        'description' => 'nullable|string|max:5000',
        'specs'       => 'nullable|array',
        'image'       => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        'is_active'   => 'nullable|boolean',
    ];
}
```

**`app/Http/Requests/UpdateProductRequest.php`:** (igual que Store pero campos opcionales)
```php
public function authorize(): bool { return true; }

public function rules(): array
{
    return [
        'name'        => 'sometimes|string|max:300',
        'brand'       => 'nullable|string|max:100',
        'price'       => 'sometimes|numeric|min:0',
        'stock'       => 'sometimes|integer|min:0',
        'category_id' => 'nullable|uuid|exists:categories,id',
        'description' => 'nullable|string|max:5000',
        'specs'       => 'nullable|array',
        'image'       => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        'is_active'   => 'nullable|boolean',
    ];
}
```

✅ **Checkpoint:** Los 3 archivos existen en `app/Http/Requests/`.

---

### Paso 14 — Crear el servicio de imágenes

Crear `app/Services/ImageService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class ImageService
{
    /**
     * Procesa y sube una imagen de producto.
     * Devuelve array con 'image_url' y 'thumbnail_url'.
     */
    public function uploadProductImage(UploadedFile $file, string $tenantSlug): array
    {
        $filename = Str::uuid()->toString();
        $folder   = "products/{$tenantSlug}";

        // Imagen principal: máx 1200×1200 px, WebP calidad 85
        $mainImage = Image::read($file)
            ->scaleDown(width: 1200, height: 1200)
            ->toWebp(quality: 85);

        $mainPath = "{$folder}/{$filename}.webp";
        Storage::disk('r2')->put($mainPath, $mainImage->toString());

        // Thumbnail: 400×400 px, recortado centrado
        $thumb = Image::read($file)
            ->cover(width: 400, height: 400)
            ->toWebp(quality: 80);

        $thumbPath = "{$folder}/{$filename}_thumb.webp";
        Storage::disk('r2')->put($thumbPath, $thumb->toString());

        return [
            'image_url'     => Storage::disk('r2')->url($mainPath),
            'thumbnail_url' => Storage::disk('r2')->url($thumbPath),
        ];
    }

    /**
     * Elimina las imágenes anteriores de un producto del storage.
     */
    public function deleteProductImages(?string $imageUrl, ?string $thumbUrl): void
    {
        // Extraer el path relativo de la URL y eliminar del disco
        foreach ([$imageUrl, $thumbUrl] as $url) {
            if ($url) {
                $path = parse_url($url, PHP_URL_PATH);
                Storage::disk('r2')->delete(ltrim($path, '/'));
            }
        }
    }
}
```

Registrar el servicio en `bootstrap/providers.php` o usarlo directamente con inyección de dependencias (Laravel lo resuelve automáticamente por su contenedor).

✅ **Checkpoint:** El archivo existe. Por ahora no se puede probar sin R2 configurado (se completa en Paso 18).

---

### Paso 15 — Crear CategoryController y ProductController

```bash
php artisan make:controller Api/CategoryController --api
php artisan make:controller Api/ProductController --api
```

**`app/Http/Controllers/Api/CategoryController.php`:**
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        // No pasar 'id' — HasUuids genera UUID v7 automáticamente
        $category = Category::create($request->validated());

        return response()->json($category, 201);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json($category);
    }

    public function update(StoreCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());
        return response()->json($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();
        return response()->json(null, 204);
    }
}
```

**`app/Http/Controllers/Api/ProductController.php`:**
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private ImageService $imageService) {}

    public function index(Request $request): JsonResponse
    {
        $products = Product::with('category')
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->active_only, fn($q) => $q->where('is_active', true))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $tenant = app('currentTenant');
            $urls   = $this->imageService->uploadProductImage($request->file('image'), $tenant->slug);
            $data   = array_merge($data, $urls);
        }

        // No pasar 'id' — HasUuids genera UUID v7 automáticamente
        $product = Product::create($data);

        return response()->json($product->load('category'), 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product->load('category'));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            // Eliminar imágenes anteriores
            $this->imageService->deleteProductImages($product->image_url, $product->thumbnail_url);

            $tenant = app('currentTenant');
            $urls   = $this->imageService->uploadProductImage($request->file('image'), $tenant->slug);
            $data   = array_merge($data, $urls);
        }

        $product->update($data);

        return response()->json($product->load('category'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->imageService->deleteProductImages($product->image_url, $product->thumbnail_url);
        $product->delete();

        return response()->json(null, 204);
    }
}
```

✅ **Checkpoint:** `php artisan route:list` muestra todos los endpoints de `products` y `categories`.

---

### Paso 16 — Crear TenantController

```bash
php artisan make:controller Api/TenantController
```

**`app/Http/Controllers/Api/TenantController.php`:**
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(private ImageService $imageService) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(app('currentTenant'));
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name'           => 'sometimes|string|max:200',
            'whatsapp_number'=> 'sometimes|string|max:20',
            'primary_color'  => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo'           => 'nullable|image|mimes:jpeg,png,webp|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $urls = $this->imageService->uploadProductImage($request->file('logo'), $tenant->slug . '/logo');
            $data['logo_url'] = $urls['image_url'];
        }

        $tenant->update($data);

        return response()->json($tenant);
    }
}
```

✅ **Checkpoint:** El archivo existe sin errores de sintaxis.

---

### Paso 17 — Crear el controlador del catálogo público

```bash
php artisan make:controller Api/Public/PublicCatalogController
```

**`app/Http/Controllers/Api/Public/PublicCatalogController.php`:**
```php
<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicCatalogController extends Controller
{
    public function tenant(string $slug): JsonResponse
    {
        $tenant = Cache::remember("tenant:{$slug}", 300, function () use ($slug) {
            return Tenant::where('slug', $slug)
                ->where('is_active', true)
                ->select(['id', 'slug', 'name', 'logo_url', 'primary_color', 'whatsapp_number'])
                ->firstOrFail();
        });

        return response()->json($tenant);
    }

    public function products(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        // Obtener la versión de caché actual para el tenant (soporte de invalidación en driver file)
        $version = Cache::remember("tenant:{$slug}:cache_version", 86400, fn() => 1);

        // IMPORTANTE: usar only() para prevenir cache flooding con parámetros arbitrarios
        $cacheKey = "catalog:{$slug}:v{$version}:" . md5(json_encode($request->only([
            'category_id', 'search', 'in_stock', 'page'
        ])));

        $products = Cache::remember($cacheKey, 300, function () use ($tenant, $request) {
            return Product::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->with('category:id,name,icon')
                ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
                ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
                ->when($request->in_stock, fn($q) => $q->where('stock', '>', 0))
                ->orderByDesc('created_at')
                ->paginate(24);
        });

        return response()->json($products);
    }

    public function product(string $slug, string $productId): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $product = Product::where('tenant_id', $tenant->id)
            ->where('id', $productId)
            ->where('is_active', true)
            ->with('category')
            ->firstOrFail();

        return response()->json($product);
    }
}
```

✅ **Checkpoint:** Hacer GET a `http://localhost:8000/api/public/pc-parts-demo` — debe devolver los datos del tenant creado en el Paso 12.

---

## FASE E — Almacenamiento de Imágenes (Cloudflare R2)

---

### Paso 18 — Configurar Cloudflare R2

**1.** Ir a https://dash.cloudflare.com → R2 Object Storage → Crear bucket llamado `saas-hardware-images`.

**2.** Ir a "Manage R2 API Tokens" → Crear token con permisos de lectura y escritura sobre el bucket.

**3.** Agregar al `.env`:

```env
R2_ACCESS_KEY_ID=tu_access_key_id
R2_SECRET_ACCESS_KEY=tu_secret_access_key
R2_DEFAULT_REGION=auto
R2_BUCKET=saas-hardware-images
R2_ENDPOINT=https://TU_ACCOUNT_ID.r2.cloudflarestorage.com
# IMPORTANTE: R2_URL debe ser el dominio público generado por Cloudflare (.r2.dev) o tu dominio personalizado del bucket.
# No usar la URL del endpoint API (S3) anterior ya que requiere firma y generará errores 403 al renderizar las imágenes.
R2_URL=https://pub-xxxxxx.r2.dev
```

**4.** Abrir `config/filesystems.php` y agregar dentro de `'disks'`:

```php
'r2' => [
    'driver'   => 's3',
    'key'      => env('R2_ACCESS_KEY_ID'),
    'secret'   => env('R2_SECRET_ACCESS_KEY'),
    'region'   => env('R2_DEFAULT_REGION', 'auto'),
    'bucket'   => env('R2_BUCKET'),
    'endpoint' => env('R2_ENDPOINT'),
    'url'      => env('R2_URL'),
    'use_path_style_endpoint' => true,
],
```

**5.** Cambiar el disco por defecto para uploads en `.env`:

```env
FILESYSTEM_DISK=r2
```

✅ **Checkpoint:** Ejecutar en Tinker:
```php
Storage::disk('r2')->put('test.txt', 'hola');
Storage::disk('r2')->exists('test.txt'); // true
Storage::disk('r2')->delete('test.txt');
```

---

## FASE F — Frontend (React + Vite)

---

### Paso 19 — Crear el proyecto frontend

En la carpeta `C:\Proyectos\` (al lado de `saas-hardware-api`):

```bash
npm create vite@latest saas-hardware-frontend -- --template react-ts
cd saas-hardware-frontend
npm install
```

Instalar dependencias:

```bash
npm install axios react-router-dom zustand @tanstack/react-query
npm install react-hot-toast lucide-react
npm install -D @types/node
```

Verificar que arranca:

```bash
npm run dev
# Debe abrir en http://localhost:5173
```

✅ **Checkpoint:** El browser muestra la página default de Vite + React.

---

### Paso 20 — Estructura de carpetas del frontend

Crear la siguiente estructura dentro de `saas-hardware-frontend/src/`:

```
src/
├── api/
│   ├── axios.ts          ← Instancia de Axios con interceptores
│   ├── auth.ts           ← Llamadas de auth (login, logout, me)
│   ├── products.ts       ← CRUD de productos
│   ├── categories.ts     ← CRUD de categorías
│   └── public.ts         ← Endpoints del catálogo público
├── components/
│   ├── ui/               ← Componentes genéricos (Button, Input, Modal, etc.)
│   └── layout/           ← Sidebar, Topbar, Layout
├── pages/
│   ├── auth/
│   │   └── LoginPage.tsx
│   ├── dashboard/
│   │   ├── DashboardPage.tsx
│   │   ├── ProductsPage.tsx
│   │   └── CategoriesPage.tsx
│   └── public/
│       ├── CatalogPage.tsx
│       └── ProductDetailPage.tsx
├── stores/
│   ├── authStore.ts      ← Estado de autenticación (Zustand)
│   └── tenantStore.ts    ← Datos del tenant activo (Zustand)
├── router/
│   ├── index.tsx         ← Definición de rutas
│   └── PrivateRoute.tsx  ← Guard de rutas autenticadas
├── types/
│   └── index.ts          ← Interfaces TypeScript (Tenant, Product, etc.)
└── main.tsx
```

Crear las carpetas vacías:

```bash
mkdir src/api src/components src/components/ui src/components/layout
mkdir src/pages src/pages/auth src/pages/dashboard src/pages/public
mkdir src/stores src/router src/types
```

✅ **Checkpoint:** La estructura de carpetas existe.

---

### Paso 21 — Definir tipos TypeScript compartidos

Crear `src/types/index.ts`:

```typescript
export interface Tenant {
  id: string;
  slug: string;
  name: string;
  logo_url: string | null;
  primary_color: string;
  whatsapp_number: string;
  plan: 'free' | 'pro' | 'enterprise';
  is_active: boolean;
}

export interface User {
  id: string;
  tenant_id: string;
  name: string;
  email: string;
  role: 'admin' | 'staff';
  is_active: boolean;
}

export interface Category {
  id: string;
  tenant_id: string;
  name: string;
  icon: string | null;
  sort_order: number;
  is_active: boolean;
}

export interface Product {
  id: string;
  tenant_id: string;
  category_id: string | null;
  category?: Category;
  name: string;
  brand: string | null;
  price: number;
  stock: number;
  is_available: boolean;
  description: string | null;
  specs: Record<string, string | number> | null;
  image_url: string | null;
  thumbnail_url: string | null;
  is_active: boolean;
  created_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface AuthResponse {
  token: string;
  user: User;
  tenant: Tenant;
}
```

✅ **Checkpoint:** El archivo existe sin errores de TypeScript.

---

### Paso 22 — Configurar Axios con interceptores

Crear `src/api/axios.ts`:

```typescript
import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Antes de cada petición: inyectar token y header de tenant
api.interceptors.request.use((config) => {
  const token  = sessionStorage.getItem('token');
  const tenant = sessionStorage.getItem('tenant_slug');

  if (token)  config.headers['Authorization'] = `Bearer ${token}`;
  if (tenant) config.headers['X-Tenant'] = tenant;

  return config;
});

// Si el servidor responde 401: limpiar sesión y redirigir
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      sessionStorage.clear();
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default api;
```

Crear `.env` en la raíz del frontend:
```env
VITE_API_URL=http://localhost:8000/api
```

✅ **Checkpoint:** El archivo existe. Los imports no muestran errores en el editor.

---

### Paso 23 — Crear los stores de Zustand

**`src/stores/authStore.ts`:**
```typescript
import { create } from 'zustand';
import type { User } from '../types';

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  setAuth: (token: string, user: User) => void;
  clearAuth: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  user:            null,
  token:           sessionStorage.getItem('token'),
  isAuthenticated: !!sessionStorage.getItem('token'),

  setAuth: (token, user) => {
    sessionStorage.setItem('token', token);
    set({ token, user, isAuthenticated: true });
  },

  clearAuth: () => {
    sessionStorage.clear();
    set({ token: null, user: null, isAuthenticated: false });
  },
}));
```

**`src/stores/tenantStore.ts`:**
```typescript
import { create } from 'zustand';
import type { Tenant } from '../types';

interface TenantState {
  tenant: Tenant | null;
  setTenant: (tenant: Tenant) => void;
  clearTenant: () => void;
}

export const useTenantStore = create<TenantState>((set) => ({
  tenant: null,
  setTenant: (tenant) => {
    sessionStorage.setItem('tenant_slug', tenant.slug);
    set({ tenant });
  },
  clearTenant: () => {
    sessionStorage.removeItem('tenant_slug');
    set({ tenant: null });
  },
}));
```

✅ **Checkpoint:** Ambos archivos existen sin errores.

---

### Paso 24 — Configurar React Router y rutas protegidas

**`src/router/PrivateRoute.tsx`:**
```typescript
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';

interface Props {
  children: React.ReactNode;
}

export default function PrivateRoute({ children }: Props) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
}
```

**`src/router/index.tsx`:**
```typescript
import { createBrowserRouter } from 'react-router-dom';
import PrivateRoute from './PrivateRoute';
import LoginPage from '../pages/auth/LoginPage';
import DashboardPage from '../pages/dashboard/DashboardPage';
import ProductsPage from '../pages/dashboard/ProductsPage';
import CategoriesPage from '../pages/dashboard/CategoriesPage';
import CatalogPage from '../pages/public/CatalogPage';
import ProductDetailPage from '../pages/public/ProductDetailPage';

export const router = createBrowserRouter([
  // Rutas públicas
  { path: '/login', element: <LoginPage /> },
  { path: '/:slug', element: <CatalogPage /> },
  { path: '/:slug/product/:id', element: <ProductDetailPage /> },

  // Rutas privadas (dashboard)
  {
    path: '/dashboard',
    element: <PrivateRoute><DashboardPage /></PrivateRoute>,
    children: [
      { path: 'products',   element: <ProductsPage /> },
      { path: 'categories', element: <CategoriesPage /> },
    ],
  },

  // Redirigir raíz al login
  { path: '/', element: <LoginPage /> },
]);
```

Actualizar `src/main.tsx`:
```typescript
import React from 'react';
import ReactDOM from 'react-dom/client';
import { RouterProvider } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import { router } from './router';
import './index.css';

const queryClient = new QueryClient();

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
      <Toaster position="top-right" />
    </QueryClientProvider>
  </React.StrictMode>
);
```

✅ **Checkpoint:** `npm run dev` no muestra errores de TypeScript en consola.

---

### Paso 25 — Página de Login

Los pasos de construcción de páginas individuales (Login, Dashboard, Productos, Catálogo público) continúan desde aquí con el mismo patrón. Se marcan como pendientes para desarrollar en sesiones siguientes.

- [ ] **Paso 25** — Página de Login (`LoginPage.tsx`)
- [ ] **Paso 26** — Layout del Dashboard (`DashboardPage.tsx` + Sidebar + Topbar)
- [ ] **Paso 27** — Página de Productos del dashboard (`ProductsPage.tsx`)
- [ ] **Paso 28** — Formulario de crear/editar producto (modal con subida de imagen)
- [ ] **Paso 29** — Página de Categorías del dashboard
- [ ] **Paso 30** — Página pública del catálogo (`CatalogPage.tsx`)
- [ ] **Paso 31** — Ficha de producto pública (`ProductDetailPage.tsx`)
- [ ] **Paso 32** — Botón WhatsApp con mensaje dinámico
- [ ] **Paso 33** — Personalización visual por tenant (CSS variables dinámicas)
- [ ] **Paso 34** — Configuración de despliegue (backend en hosting + frontend en Vercel)
- [ ] **Paso 35** — Variables de entorno en producción y prueba final end-to-end

---

## RESUMEN DEL ESTADO POR PASO

| # | Descripción | Estado |
|---|---|---|
| 1 | Verificar herramientas | ✅ Completado |
| 2 | Crear proyecto Laravel | ✅ Completado |
| 3 | Inicializar Git | ✅ Completado |
| 4 | Configurar `.env` | ✅ Completado |
| 5 | Crear base de datos MySQL | ✅ Completado |
| 6 | Instalar dependencias PHP | ✅ Completado |
| 7 | Crear migraciones | ✅ Completado |
| 8 | Crear Modelos | ✅ Completado |
| 9 | Configurar Multitenancy | ✅ Completado |
| 10 | AuthController | ⬜ Pendiente |
| 11 | Definir rutas API | ⬜ Pendiente |
| 12 | Probar registro con cliente HTTP | ⬜ Pendiente |
| 13 | Form Requests de validación | ⬜ Pendiente |
| 14 | Servicio de imágenes | ⬜ Pendiente |
| 15 | Category y ProductController | ⬜ Pendiente |
| 16 | TenantController | ⬜ Pendiente |
| 17 | Controlador catálogo público | ⬜ Pendiente |
| 18 | Configurar Cloudflare R2 | ⬜ Pendiente |
| 19 | Crear proyecto frontend React | ⬜ Pendiente |
| 20 | Estructura de carpetas frontend | ⬜ Pendiente |
| 21 | Tipos TypeScript compartidos | ⬜ Pendiente |
| 22 | Axios con interceptores | ⬜ Pendiente |
| 23 | Stores de Zustand | ⬜ Pendiente |
| 24 | React Router y rutas protegidas | ⬜ Pendiente |
| 25–35 | Páginas y despliegue | ⬜ Pendiente |

> Cambia ⬜ por ✅ cuando completes cada paso.

---

*Guía creada el 2026-06-12. Los pasos 25–35 se detallarán en sesiones siguientes conforme avance el desarrollo.*
