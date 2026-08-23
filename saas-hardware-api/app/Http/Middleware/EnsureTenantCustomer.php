<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El token de cliente solo vale en SU tienda (AUD-3).
 *
 * `auth:sanctum` comprueba que el token existe y es valido, nada mas. En las
 * rutas publicas la tienda no viene del header `X-Tenant` sino del slug de la
 * URL, asi que un token emitido por `/public/tienda-a/auth/login` pasaba tal cual
 * por `/public/tienda-b/...`: el registro de clientes es abierto, o sea que
 * conseguir un token de "algun usuario" no cuesta nada.
 *
 * En favoritos eso ya escribia en la pivote de otra tienda; en el resto de rutas
 * el controlador filtraba por `tenant_id` y devolvia listas vacias, que no filtra
 * datos pero deja al visitante "logueado" en una tienda donde no tiene cuenta.
 *
 * Se responde 401 y no 403 a proposito. El token no es que no alcance aqui: es
 * que aqui no identifica a nadie, que es lo que dice un 401. Ademas encaja con
 * lo que el frontend ya hacia: el interceptor limpia la sesion de cliente ante un
 * 401 en rutas publicas, asi que el visitante ve el formulario de acceso de ESTA
 * tienda en vez de un error. Con 403 habria que haber añadido una rama nueva.
 * Importa porque `customer_token` vive en localStorage, que es del origen y no de
 * la tienda: quien entra a dos tiendas del mismo dominio arrastra el token de la
 * primera.
 */
class EnsureTenantCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $slug = $request->route('slug');

        // Sin slug no hay tienda contra la que comparar. No deberia pasar
        // —el middleware solo se aplica dentro de `public/{slug}`— pero si
        // pasara, lo correcto es cerrar, no dejar pasar.
        if (!$user || !is_string($slug)) {
            abort(401, 'No autenticado en esta tienda.');
        }

        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();

        if (!$tenant || $user->tenant_id !== $tenant->id) {
            abort(401, 'No autenticado en esta tienda.');
        }

        return $next($request);
    }
}
