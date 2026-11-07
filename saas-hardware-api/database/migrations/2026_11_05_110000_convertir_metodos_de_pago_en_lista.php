<?php

use App\Support\MetodosDePago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Los métodos de pago pasan de cuatro fijos a una lista que arma la tienda
 * (MOD-23).
 *
 * Solo datos: la columna sigue siendo el mismo JSON. Lo de antes —un objeto
 * con `yape`, `plin`, `transferencia` y `efectivo`— se reescribe como lista con
 * `MetodosDePago::desdeFormatoAntiguo()`, que conserva también los apagados y
 * junta los campos sueltos (banco, número, titular) en el texto que ahora
 * escribe la tienda. El código lee las dos formas (lo hace el cast), así que
 * funciona con y sin esta migración; esto deja la base con una sola.
 *
 * Después se olvida la caché del catálogo de esas tiendas: lo cacheado lleva la
 * forma vieja y el catálogo nuevo ya no la entiende.
 *
 * No hay vuelta atrás exacta: una lista escrita a mano no tiene un «Yape» que
 * devolver a su casilla. El `down()` no toca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tiendas = DB::table('tenants')->whereNotNull('payment_methods')->get(['id', 'slug', 'payment_methods']);

        foreach ($tiendas as $tienda) {
            $guardado = json_decode($tienda->payment_methods, true);

            if (! is_array($guardado) || array_is_list($guardado)) {
                continue;
            }

            DB::table('tenants')
                ->where('id', $tienda->id)
                ->update(['payment_methods' => json_encode(MetodosDePago::normalizar($guardado))]);

            try {
                Cache::forget("tenant:{$tienda->slug}");
                Cache::increment("tenant:{$tienda->slug}:cache_version");
            } catch (\Throwable) {
                // Sin caché disponible no hay nada viejo que olvidar.
            }
        }
    }

    public function down(): void
    {
        //
    }
};
