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
            // Resuelve la tienda de las rutas publicas desde el slug de la URL,
            // para que el global scope de BelongsToTenant filtre tambien ahi
            // (AUD-4). El panel usa 'tenant', que resuelve por header.
            'tenant.slug' => \App\Http\Middleware\InitializeTenantBySlug::class,
            // Cerca de IP para el panel de plataforma (AUD-13). Va en el login
            // ademas de en el grupo autenticado: el login es donde se prueban
            // contrasenias, asi que protegerlo solo a partir del token habria
            // dejado abierto justo lo que hay que cerrar.
            'platform.ip' => \App\Http\Middleware\RestrictPlatformIp::class,
        ]);

        // TEC-9: withMiddleware() instala por defecto un redirect de invitados
        // a la ruta con nombre `login`, que aqui no existe —el backend es solo
        // API y la pantalla de acceso vive en el SPA—. Sin esto, cualquier ruta
        // privada llamada sin token, o con uno caducado, respondia 500 con
        // `Route [login] not defined` en vez de 401: el cliente no podia
        // distinguir "no estas autenticado" de "el servidor se rompio", y algo
        // tan corriente como una sesion vencida llenaba de 500 los logs justo
        // donde luego hay que mirar. Con null, Authenticate lanza
        // AuthenticationException y el handler la convierte en 401 (en JSON
        // para `api/*`, por el shouldRenderJsonWhen de mas abajo).
        $middleware->redirectGuestsTo(null);

        // El tenant debe resolverse ANTES de SubstituteBindings para que el
        // global scope de BelongsToTenant filtre el route-model binding y así
        // un recurso de otro tenant devuelva 404 (cierra el IDOR, SEC-2).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\InitializeTenantByHeader::class,
        );
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\InitializeTenantBySlug::class,
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

        // FUN-5: el enlace de verificacion caducado tiene que acabar en una
        // pantalla, no en un JSON.
        //
        // Es el mismo desajuste que TEC-9, por el otro lado: el backend es solo
        // API y la linea de arriba convierte en JSON todo lo que cuelgue de
        // `api/*`, que es lo correcto para el SPA. Pero esta ruta concreta la abre
        // una PERSONA desde su cliente de correo, asi que un enlace de hace dos
        // dias le mostraria `{"message":"Invalid signature."}` a pantalla completa
        // y ahi se acaba el alta de la tienda.
        //
        // Va atado al NOMBRE de la ruta y no a la excepcion a secas para no
        // cambiarle la respuesta a ninguna ruta firmada futura sin querer.
        $exceptions->render(function (
            \Illuminate\Routing\Exceptions\InvalidSignatureException $e,
            Request $request,
        ) {
            if ($request->route()?->getName() !== 'verificacion.correo') {
                return null;
            }

            return redirect(rtrim((string) config('app.frontend_url'), '/').'/login?verificacion=caducada');
        });
    })->create();
