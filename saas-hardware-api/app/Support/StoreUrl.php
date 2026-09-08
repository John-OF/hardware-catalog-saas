<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Enlaces publicos de una tienda, para los correos.
 *
 * Vive aparte porque lo necesitan ya tres notificaciones y todas tienen que
 * componer la URL igual: con dominio propio se usa ese, y si no, la URL con slug
 * del frontend. Es la misma pareja que resuelve el frontend al arrancar
 * (`resolveDomain` cuando no hay slug en la ruta), y tenerla copiada en cada
 * correo era la forma segura de que un dia dejaran de coincidir.
 *
 * Devuelve null cuando no hay tienda: el correo simplemente se queda sin boton,
 * que es mejor que enviarlo con un enlace roto.
 */
class StoreUrl
{
    public static function forTenant(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return null;
        }

        if (filled($tenant->custom_domain)) {
            return 'https://'.$tenant->custom_domain;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/'.$tenant->slug;
    }

    /**
     * Ficha de un producto.
     *
     * El path es el que enlaza el frontend (`/product/{id}`, ver el router). Si se
     * mueve alli hay que moverlo aqui: es el mismo acoplamiento que FUN-7 dejo
     * escrito en `routes/web.php`, y por el mismo motivo.
     */
    public static function forProduct(?Tenant $tenant, string $productId): ?string
    {
        $base = self::forTenant($tenant);

        return $base ? $base.'/product/'.$productId : null;
    }
}
