# saas-hardware-api

API REST y multitenancy de **Hardware Catalog SaaS**. Laravel 13 · PHP 8.3 · Sanctum ·
spatie/laravel-multitenancy.

**La documentación vive en el [README de la raíz](../README.md)**, que es el único sitio donde se
mantiene: estructura, endpoints, variables de entorno, arquitectura multi-tenant y convenciones.

Lo mínimo para arrancar:

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configurar DB_CONNECTION y DB_* en .env
php artisan migrate
php artisan storage:link
php artisan serve --port=8000
php artisan queue:work        # OJO: sin esto no sale ningún correo
```

```bash
php artisan test        # 392 tests
vendor/bin/pint         # Formateo
composer dev            # serve + queue + logs + vite en paralelo
```
