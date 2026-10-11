<?php

namespace App\Jobs;

use App\Support\HuellaDelCodigo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * El trabajo de prueba de `salud:comprobar` (`INF-19`).
 *
 * No hace nada más que dejar en la caché, bajo una clave de un solo uso, la
 * huella del código con el que arrancó el worker que lo atiende. Que aparezca
 * dice que hay un worker vivo; lo que contiene dice si corre la versión actual
 * (`INF-13`). Va por la misma cola que los correos, que es la que importa.
 *
 * **`NotTenantAware`**: se encola sin tienda resuelta, y con
 * `queues_are_tenant_aware_by_default` spatie descarta un trabajo así **sin
 * llegar a `failed_jobs`** (`AUD-11`). Sin esto la comprobación diría "no hay
 * worker" con el worker vivo.
 */
class ComprobarCola implements NotTenantAware, ShouldQueue
{
    use Queueable;

    /**
     * Sin reintentos: si falla, la comprobación ya lo cuenta como que nadie lo
     * atendió, y reintentarlo solo llenaría `failed_jobs` de trabajos de prueba.
     */
    public int $tries = 1;

    public function __construct(public string $token) {}

    public static function clave(string $token): string
    {
        return 'salud:cola:'.$token;
    }

    public function handle(): void
    {
        // Diez minutos: de sobra para que la comprobación lo lea (y lo borra al
        // leerlo). Si el worker estaba parado y lo atiende tarde, ya no lo lee
        // nadie y caduca.
        Cache::put(self::clave($this->token), HuellaDelCodigo::delProceso(), now()->addMinutes(10));
    }
}
