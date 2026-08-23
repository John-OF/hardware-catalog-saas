<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant'     => \App\Http\Middleware\InitializeTenantByHeader::class,
            'admin'      => \App\Http\Middleware\EnsureAdmin::class,
            'superadmin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            // Solo para las rutas publicas con sesion de cliente: el token tiene
            // que ser de la tienda del slug, no de cualquiera (AUD-3).
            'customer'   => \App\Http\Middleware\EnsureTenantCustomer::class,
        ]);

        // El tenant debe resolverse ANTES de SubstituteBindings para que el
        // global scope de BelongsToTenant filtre el route-model binding y así
        // un recurso de otro tenant devuelva 404 (cierra el IDOR, SEC-2).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\InitializeTenantByHeader::class,
        );
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureAdmin::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
